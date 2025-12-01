<?php declare(strict_types = 1);

namespace Modules\Zentinel\Actions;

use CController;
use CControllerResponseData;
use CProfile;
use API;

class CControllerZentinelView extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $fields = [
            'filter_groupids' => 'array_db hosts_groups.groupid',
            'filter_prodids'  => 'array_db hosts_groups.groupid',
            'filter_ack'      => 'in -1,0,1',
            'filter_age'      => 'string',
            'filter_set'      => 'in 1',
            'filter_rst'      => 'in 1'
        ];
        return $this->validateInput($fields);
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= \USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        // --- 1. Gestão de Filtros ---
        if ($this->hasInput('filter_rst')) {
            CProfile::delete('web.zentinel.filter.groupids');
            CProfile::delete('web.zentinel.filter.prodids');
            CProfile::delete('web.zentinel.filter.ack');
            CProfile::delete('web.zentinel.filter.age');
        }
        elseif ($this->hasInput('filter_set')) {
            CProfile::updateArray('web.zentinel.filter.groupids', $this->getInput('filter_groupids', []), \PROFILE_TYPE_ID);
            CProfile::updateArray('web.zentinel.filter.prodids', $this->getInput('filter_prodids', []), \PROFILE_TYPE_ID);
            CProfile::update('web.zentinel.filter.ack', $this->getInput('filter_ack', -1), \PROFILE_TYPE_INT);
            CProfile::update('web.zentinel.filter.age', $this->getInput('filter_age', ''), \PROFILE_TYPE_STR);
        }

        $filter_groupids = CProfile::getArray('web.zentinel.filter.groupids', []);
        $filter_prodids  = CProfile::getArray('web.zentinel.filter.prodids', []);
        $filter_ack      = (int)CProfile::get('web.zentinel.filter.ack', -1);
        $filter_age      = CProfile::get('web.zentinel.filter.age', '');

        // --- 2. Busca de Problemas ---
        $options = [
            'output' => ['eventid', 'objectid', 'name', 'clock', 'severity', 'acknowledged'],
            'sortfield' => ['eventid'], 
            'sortorder' => \ZBX_SORT_DOWN,
            'recent' => true
        ];

        if ($filter_groupids) {
            $options['groupids'] = $filter_groupids;
        }
        if ($filter_ack !== -1) {
            $options['acknowledged'] = ($filter_ack == 1);
        }
        if ($filter_age !== '') {
            $seconds = \timeUnitToSeconds($filter_age);
            if ($seconds > 0) $options['time_till'] = \time() - $seconds;
        }

        $problems = API::Problem()->get($options);
        if ($problems === false) $problems = [];

        // --- 3. Enriquecimento de Dados (Correção do selectGroups) ---
        if ($problems) {
            $triggerIds = array_column($problems, 'objectid');
            
            // Passo A: Busca Triggers e Hosts (SEM selectGroups aqui!)
            $triggers = API::Trigger()->get([
                'output' => ['triggerid'],
                'selectHosts' => ['hostid', 'name'],
                'triggerids' => $triggerIds,
                'preservekeys' => true
            ]);

            // Passo B: Coleta IDs dos Hosts encontrados
            $hostIds = [];
            foreach ($triggers as $t) {
                if (!empty($t['hosts'])) {
                    foreach ($t['hosts'] as $h) {
                        $hostIds[$h['hostid']] = $h['hostid'];
                    }
                }
            }

            // Passo C: Busca Grupos dos Hosts separadamente (Aqui é permitido)
            $hostGroupsMap = [];
            if (!empty($hostIds)) {
                $hosts = API::Host()->get([
                    'output' => ['hostid'],
                    'selectGroups' => ['groupid'], // Válido em host.get
                    'hostids' => $hostIds,
                    'preservekeys' => true
                ]);
                
                // Mapeia HostID -> Array de GroupIDs
                foreach ($hosts as $hid => $hdata) {
                    $hostGroupsMap[$hid] = array_column($hdata['groups'], 'groupid');
                }
            }

            // Passo D: Cruza as informações
            foreach ($problems as &$problem) {
                $tid = $problem['objectid'];
                $problem['host_name'] = 'N/A';
                $problem['is_production'] = false;

                if (isset($triggers[$tid]) && !empty($triggers[$tid]['hosts'])) {
                    $hostData = $triggers[$tid]['hosts'][0];
                    $problem['host_name'] = $hostData['name'];
                    $hid = $hostData['hostid'];

                    // Verifica se o host pertence a algum grupo de produção selecionado
                    if (!empty($filter_prodids) && isset($hostGroupsMap[$hid])) {
                        if (array_intersect($hostGroupsMap[$hid], $filter_prodids)) {
                            $problem['is_production'] = true;
                        }
                    }
                }
            }
            unset($problem);
        }

        // --- 4. Estatísticas ---
        $stats = [
            'total' => count($problems),
            'ack_count' => 0,
            'critical_count' => 0,
            'avg_duration' => 0
        ];
        $total_duration = 0;
        foreach ($problems as $p) {
            if ($p['acknowledged'] == 1) $stats['ack_count']++;
            if ($p['severity'] >= \TRIGGER_SEVERITY_HIGH) $stats['critical_count']++;
            $total_duration += (\time() - (int)$p['clock']);
        }
        if ($stats['total'] > 0) {
            $stats['avg_duration'] = \zbx_date2age(0, (int)($total_duration / $stats['total']));
        }

        // --- 5. Dados para o Filtro ---
        $data_groups = [];
        if ($filter_groupids) {
            $data_groups = API::HostGroup()->get(['output' => ['groupid', 'name'], 'groupids' => $filter_groupids]);
        }
        $data_prod_groups = [];
        if ($filter_prodids) {
            $data_prod_groups = API::HostGroup()->get(['output' => ['groupid', 'name'], 'groupids' => $filter_prodids]);
        }

        $data = [
            'problems'         => $problems,
            'stats'            => $stats,
            'filter_groupids'  => $data_groups,
            'filter_prodids'   => $data_prod_groups,
            'filter_ack'       => $filter_ack,
            'filter_age'       => $filter_age
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('Zentinel: Command Center'));
        $this->setResponse($response);
    }
}
