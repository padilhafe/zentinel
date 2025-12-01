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
        // CORREÇÃO: Usamos 'array' em vez de 'array_db' para evitar bloqueio
        // se o navegador enviar parâmetros vazios (ex: filter_groupids[]=)
        $fields = [
            'filter_groupids' => 'array', 
            'filter_prodids'  => 'array',
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
        // --- 0. Sanitização de Entrada (Limpeza) ---
        // Remove strings vazias que podem vir da URL
        $raw_groupids = $this->getInput('filter_groupids', []);
        $raw_prodids  = $this->getInput('filter_prodids', []);
        
        $clean_groupids = array_filter($raw_groupids);
        $clean_prodids  = array_filter($raw_prodids);

        // --- 1. Filtros (Salvar/Resetar) ---
        if ($this->hasInput('filter_rst')) {
            CProfile::delete('web.zentinel.filter.groupids');
            CProfile::delete('web.zentinel.filter.prodids');
            CProfile::delete('web.zentinel.filter.ack');
            CProfile::delete('web.zentinel.filter.age');
        }
        elseif ($this->hasInput('filter_set')) {
            // Salvamos apenas os dados limpos
            CProfile::updateArray('web.zentinel.filter.groupids', $clean_groupids, \PROFILE_TYPE_ID);
            CProfile::updateArray('web.zentinel.filter.prodids', $clean_prodids, \PROFILE_TYPE_ID);
            CProfile::update('web.zentinel.filter.ack', $this->getInput('filter_ack', -1), \PROFILE_TYPE_INT);
            CProfile::update('web.zentinel.filter.age', $this->getInput('filter_age', ''), \PROFILE_TYPE_STR);
        }

        // Recupera do perfil
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

        // Só aplica o filtro se o array não estiver vazio
        if (!empty($filter_groupids)) {
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

        // --- 3. Enriquecimento de Dados (Estratégia Inversa - API Compatível) ---
        if ($problems) {
            $triggerIds = array_column($problems, 'objectid');
            
            // A. Busca Triggers para descobrir Hosts
            $triggers = API::Trigger()->get([
                'output' => ['triggerid'],
                'selectHosts' => ['hostid', 'name'],
                'triggerids' => $triggerIds,
                'preservekeys' => true
            ]);

            // B. Coleta IDs de Hosts
            $hostIds = [];
            foreach ($triggers as $t) {
                if (!empty($t['hosts'])) {
                    foreach ($t['hosts'] as $h) {
                        $hostIds[$h['hostid']] = $h['hostid'];
                    }
                }
            }

            // C. Busca Grupos dos Hosts (Usando API HostGroup para evitar erro deprecated)
            $hostGroupsMap = [];
            if (!empty($hostIds)) {
                $groups = API::HostGroup()->get([
                    'output' => ['groupid'],
                    'selectHosts' => ['hostid'],
                    'hostids' => $hostIds
                ]);

                foreach ($groups as $group) {
                    if (!empty($group['hosts'])) {
                        foreach ($group['hosts'] as $h) {
                            $hostGroupsMap[$h['hostid']][] = $group['groupid'];
                        }
                    }
                }
            }

            // D. Mapeamento Final
            foreach ($problems as &$problem) {
                $tid = $problem['objectid'];
                $problem['host_name'] = 'N/A';
                $problem['is_production'] = false;

                if (isset($triggers[$tid]) && !empty($triggers[$tid]['hosts'])) {
                    $hostData = $triggers[$tid]['hosts'][0];
                    $problem['host_name'] = $hostData['name'];
                    $hid = $hostData['hostid'];

                    // Lógica de Produção
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

        // --- 5. Dados para popular os Selects do Filtro ---
        $data_groups = [];
        if (!empty($filter_groupids)) {
            $data_groups = API::HostGroup()->get(['output' => ['groupid', 'name'], 'groupids' => $filter_groupids]);
        }
        $data_prod_groups = [];
        if (!empty($filter_prodids)) {
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