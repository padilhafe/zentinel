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
            'filter_groupids' => 'array_db hosts_groups.groupid', // Filtro geral (o que exibir)
            'filter_prodids'  => 'array_db hosts_groups.groupid', // NOVO: O que é considerado Produção?
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
        // --- Gestão de Filtros ---
        if ($this->hasInput('filter_rst')) {
            CProfile::delete('web.zentinel.filter.groupids');
            CProfile::delete('web.zentinel.filter.prodids'); // Novo
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

        // --- Busca de Problemas ---
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

        // --- Enrich Data (Hosts e Grupos) ---
        // Precisamos saber os grupos de cada host para separar PROD de NON-PROD
        if ($problems) {
            $triggerIds = array_column($problems, 'objectid');
            $triggers = API::Trigger()->get([
                'output' => ['triggerid'],
                'selectHosts' => ['hostid', 'name'],
                'selectGroups' => ['groupid'], // Importante para a lógica de separação
                'triggerids' => $triggerIds,
                'preservekeys' => true
            ]);

            foreach ($problems as &$problem) {
                $tid = $problem['objectid'];
                $problem['host_name'] = 'N/A';
                $problem['is_production'] = false; // Flag padrão

                if (isset($triggers[$tid]) && !empty($triggers[$tid]['hosts'])) {
                    $problem['host_name'] = $triggers[$tid]['hosts'][0]['name'];
                    
                    // Verifica se algum grupo do host está na lista de "Produção" definida no filtro
                    if (!empty($filter_prodids)) {
                        foreach ($triggers[$tid]['groups'] as $group) {
                            if (in_array($group['groupid'], $filter_prodids)) {
                                $problem['is_production'] = true;
                                break;
                            }
                        }
                    }
                }
            }
            unset($problem);
        }

        // --- Cálculo de Estatísticas ---
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

        // --- Dados para os Filtros ---
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