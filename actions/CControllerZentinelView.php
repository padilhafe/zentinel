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
            // Filtros de Escopo (O que buscar)
            'filter_groupids'   => 'array',
            'filter_hostids'    => 'array',
            // Filtros de Classificação e Estado
            'filter_nonprodids' => 'array',
            'filter_ack'        => 'in -1,0,1',
            'filter_age'        => 'string',
            'filter_set'        => 'in 1',
            'filter_rst'        => 'in 1',
            'filter_severities' => 'array',
            // Ordenação
            'sort'              => 'in clock,severity',
            'sortorder'         => 'in '.ZBX_SORT_DOWN.','.ZBX_SORT_UP
        ];
        return $this->validateInput($fields);
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= \USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        // --- 0. Inputs e Ordenação ---
        $sortField = $this->getInput('sort', CProfile::get('web.zentinel.sort', 'clock'));
        $sortOrder = $this->getInput('sortorder', CProfile::get('web.zentinel.sortorder', ZBX_SORT_DOWN));

        CProfile::update('web.zentinel.sort', $sortField, PROFILE_TYPE_STR);
        CProfile::update('web.zentinel.sortorder', $sortOrder, PROFILE_TYPE_STR);

        // Sanitização
        $clean_severities = array_filter($this->getInput('filter_severities', []), 'is_numeric');
        $clean_nonprodids = array_filter($this->getInput('filter_nonprodids', []));
        $clean_groupids   = array_filter($this->getInput('filter_groupids', []));
        $clean_hostids    = array_filter($this->getInput('filter_hostids', []));

        // --- 1. Filtros (Salvar/Resetar) ---
        if ($this->hasInput('filter_rst')) {
            CProfile::delete('web.zentinel.filter.groupids');
            CProfile::delete('web.zentinel.filter.hostids');
            CProfile::delete('web.zentinel.filter.nonprodids');
            CProfile::delete('web.zentinel.filter.ack');
            CProfile::delete('web.zentinel.filter.age');
            CProfile::delete('web.zentinel.filter.severities');
        }
        elseif ($this->hasInput('filter_set')) {
            CProfile::updateArray('web.zentinel.filter.groupids', $clean_groupids, \PROFILE_TYPE_ID);
            CProfile::updateArray('web.zentinel.filter.hostids', $clean_hostids, \PROFILE_TYPE_ID);
            CProfile::updateArray('web.zentinel.filter.nonprodids', $clean_nonprodids, \PROFILE_TYPE_ID);
            CProfile::updateArray('web.zentinel.filter.severities', $clean_severities, \PROFILE_TYPE_INT); 
            CProfile::update('web.zentinel.filter.ack', $this->getInput('filter_ack', -1), \PROFILE_TYPE_INT);
            CProfile::update('web.zentinel.filter.age', $this->getInput('filter_age', ''), \PROFILE_TYPE_STR);
        }

        // Recupera do perfil
        $filter_groupids   = CProfile::getArray('web.zentinel.filter.groupids', []);
        $filter_hostids    = CProfile::getArray('web.zentinel.filter.hostids', []);
        $filter_nonprodids = CProfile::getArray('web.zentinel.filter.nonprodids', []);
        $filter_severities = CProfile::getArray('web.zentinel.filter.severities', []); 
        $filter_ack        = (int)CProfile::get('web.zentinel.filter.ack', -1);
        $filter_age        = CProfile::get('web.zentinel.filter.age', '');

        // --- 2. Busca de Problemas ---
        $options = [
            'output' => ['eventid', 'objectid', 'name', 'clock', 'severity', 'acknowledged'],
            'sortfield' => ['eventid'], 
            'sortorder' => $sortOrder,
            'recent' => false
        ];

        // APLICANDO FILTROS DE ESCOPO NA API (Performance Máxima)
        if (!empty($filter_groupids)) {
            $options['groupids'] = $filter_groupids;
        }
        if (!empty($filter_hostids)) {
            $options['hostids'] = $filter_hostids;
        }
        // Aplica outros filtros
        if ($filter_ack !== -1) $options['acknowledged'] = ($filter_ack == 1);
        if ($filter_age !== '') {
            $seconds = \timeUnitToSeconds($filter_age);
            if ($seconds > 0) $options['time_till'] = \time() - $seconds;
        }
        if (!empty($filter_severities)) $options['severities'] = $filter_severities;

        $problems = API::Problem()->get($options);
        if ($problems === false) $problems = [];

        // --- 3. Enriquecimento ---
        if ($problems) {
            $triggerIds = array_column($problems, 'objectid');
            $triggers = API::Trigger()->get([
                'output' => ['triggerid'],
                'selectHosts' => ['hostid', 'name', 'status'],
                'triggerids' => $triggerIds,
                'preservekeys' => true
            ]);

            $hostIds = [];
            foreach ($triggers as $t) {
                if (!empty($t['hosts'])) {
                    foreach ($t['hosts'] as $h) $hostIds[$h['hostid']] = $h['hostid'];
                }
            }

            $hostGroupsMap = [];
            if (!empty($hostIds)) {
                $groups = API::HostGroup()->get([
                    'output' => ['groupid'],
                    'selectHosts' => ['hostid'],
                    'hostids' => $hostIds
                ]);
                foreach ($groups as $group) {
                    if (!empty($group['hosts'])) {
                        foreach ($group['hosts'] as $h) $hostGroupsMap[$h['hostid']][] = $group['groupid'];
                    }
                }
            }

            foreach ($problems as $key => &$problem) { 
                $tid = $problem['objectid'];
                $problem['host_name'] = 'N/A';
                $problem['is_production'] = true; 

                if (isset($triggers[$tid]) && !empty($triggers[$tid]['hosts'])) {
                    $hostData = $triggers[$tid]['hosts'][0];
                    if ((int)$hostData['status'] !== \HOST_STATUS_MONITORED) {
                        unset($problems[$key]);
                        continue;
                    }
                    $problem['host_name'] = $hostData['name'];
                    $hid = $hostData['hostid'];

                    // Lógica de Classificação (Prod vs Non-Prod)
                    if (!empty($filter_nonprodids) && isset($hostGroupsMap[$hid])) {
                        if (array_intersect($hostGroupsMap[$hid], $filter_nonprodids)) {
                            $problem['is_production'] = false;
                        }
                    }
                }
            }
            unset($problem);
        }

        // --- 4. Ordenação Manual por Severidade ---
        if ($sortField === 'severity') {
            uasort($problems, function($a, $b) use ($sortOrder) {
                if ($a['severity'] == $b['severity']) return 0;
                if ($sortOrder === ZBX_SORT_UP) {
                    return ($a['severity'] < $b['severity']) ? -1 : 1;
                } else {
                    return ($a['severity'] > $b['severity']) ? -1 : 1;
                }
            });
        }

        // --- 5. Estatísticas e Gráfico ---
        $stats = [
            'total' => count($problems),
            'ack_count' => 0,
            'critical_count' => 0,
            'avg_duration' => 0
        ];
        
        $trend_data = [];
        for ($i = 11; $i >= 0; $i--) {
            $key = date('H:00', strtotime("-$i hours"));
            $trend_data[$key] = 0;
        }

        $total_duration = 0;
        foreach ($problems as $p) {
            if ($p['acknowledged'] == 1) $stats['ack_count']++;
            if ($p['severity'] >= \TRIGGER_SEVERITY_HIGH) $stats['critical_count']++;
            $total_duration += (\time() - (int)$p['clock']);

            $p_hour = date('H:00', (int)$p['clock']);
            if (isset($trend_data[$p_hour])) $trend_data[$p_hour]++;
        }
        
        if ($stats['total'] > 0) {
            $stats['avg_duration'] = \zbx_date2age(0, (int)($total_duration / $stats['total']));
        }

        // --- 6. Dados Finais para a View ---
        
        // Busca Nomes para os Selects
        $data_filter_groups = [];
        if ($filter_groupids) {
            $data_filter_groups = API::HostGroup()->get(['output' => ['groupid', 'name'], 'groupids' => $filter_groupids]);
        }
        
        $data_filter_hosts = [];
        if ($filter_hostids) {
            $data_filter_hosts = API::Host()->get(['output' => ['hostid', 'name'], 'hostids' => $filter_hostids]);
        }

        $data_nonprod_groups = [];
        if ($filter_nonprodids) {
            $data_nonprod_groups = API::HostGroup()->get(['output' => ['groupid', 'name'], 'groupids' => $filter_nonprodids]);
        }

        $data = [
            'problems'          => $problems,
            'stats'             => $stats,
            'trend_data'        => $trend_data,
            // Dados dos Filtros
            'filter_groupids'   => $data_filter_groups,
            'filter_hostids'    => $data_filter_hosts,
            'filter_nonprodids' => $data_nonprod_groups,
            'filter_ack'        => $filter_ack,
            'filter_age'        => $filter_age,
            'filter_severities' => $filter_severities,
            'sort'              => $sortField,
            'sortorder'         => $sortOrder
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('Zentinel: Command Center'));
        $this->setResponse($response);
    }
}