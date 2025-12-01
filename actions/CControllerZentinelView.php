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
            'filter_nonprodids' => 'array', // Renomeado: Define o que NÃO é prod
            'filter_ack'        => 'in -1,0,1',
            'filter_age'        => 'string',
            'filter_set'        => 'in 1',
            'filter_rst'        => 'in 1',
            'filter_severities' => 'array',
        ];
        return $this->validateInput($fields);
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= \USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        // --- 0. Sanitização ---
        $raw_severities = $this->getInput('filter_severities', []);
        $clean_severities = array_filter($raw_severities, 'is_numeric');
        
        $raw_nonprodids = $this->getInput('filter_nonprodids', []);
        $clean_nonprodids = array_filter($raw_nonprodids);

        // --- 1. Filtros (Salvar/Resetar) ---
        if ($this->hasInput('filter_rst')) {
            CProfile::delete('web.zentinel.filter.nonprodids'); // Alterado
            CProfile::delete('web.zentinel.filter.ack');
            CProfile::delete('web.zentinel.filter.age');
            CProfile::delete('web.zentinel.filter.severities');
            // Limpa lixo antigo se existir
            CProfile::delete('web.zentinel.filter.groupids');
            CProfile::delete('web.zentinel.filter.prodids');
        }
        elseif ($this->hasInput('filter_set')) {
            CProfile::updateArray('web.zentinel.filter.nonprodids', $clean_nonprodids, \PROFILE_TYPE_ID);
            CProfile::updateArray('web.zentinel.filter.severities', $clean_severities, \PROFILE_TYPE_INT); 
            CProfile::update('web.zentinel.filter.ack', $this->getInput('filter_ack', -1), \PROFILE_TYPE_INT);
            CProfile::update('web.zentinel.filter.age', $this->getInput('filter_age', ''), \PROFILE_TYPE_STR);
        }

        // Recupera do perfil
        $filter_nonprodids = CProfile::getArray('web.zentinel.filter.nonprodids', []);
        $filter_severities = CProfile::getArray('web.zentinel.filter.severities', []); 
        $filter_ack        = (int)CProfile::get('web.zentinel.filter.ack', -1);
        $filter_age        = CProfile::get('web.zentinel.filter.age', '');

        // --- 2. Busca de Problemas ---
        $options = [
            'output' => ['eventid', 'objectid', 'name', 'clock', 'severity', 'acknowledged'],
            'sortfield' => ['eventid'], 
            'sortorder' => \ZBX_SORT_DOWN,
            'recent' => false // Apenas ativos
        ];

        // Filtros globais
        if ($filter_ack !== -1) {
            $options['acknowledged'] = ($filter_ack == 1);
        }
        if ($filter_age !== '') {
            $seconds = \timeUnitToSeconds($filter_age);
            if ($seconds > 0) $options['time_till'] = \time() - $seconds;
        }
        if (!empty($filter_severities)) {
            $options['severities'] = $filter_severities;
        }

        $problems = API::Problem()->get($options);
        if ($problems === false) $problems = [];

        // --- 3. Enriquecimento de Dados ---
        if ($problems) {
            $triggerIds = array_column($problems, 'objectid');
            
            // A. Busca Triggers (Hosts + Status)
            $triggers = API::Trigger()->get([
                'output' => ['triggerid'],
                'selectHosts' => ['hostid', 'name', 'status'],
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

            // C. Busca Grupos
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

            // D. Mapeamento e Lógica Invertida (Prod por Padrão)
            foreach ($problems as $key => &$problem) { 
                $tid = $problem['objectid'];
                $problem['host_name'] = 'N/A';
                
                // Padrão: Tudo é Produção, a menos que digamos o contrário
                $problem['is_production'] = true; 

                if (isset($triggers[$tid]) && !empty($triggers[$tid]['hosts'])) {
                    $hostData = $triggers[$tid]['hosts'][0];
                    
                    // Remove hosts desativados
                    if ((int)$hostData['status'] !== \HOST_STATUS_MONITORED) {
                        unset($problems[$key]);
                        continue;
                    }

                    $problem['host_name'] = $hostData['name'];
                    $hid = $hostData['hostid'];

                    // Lógica: Se o host estiver em um grupo "Non-Prod", setamos false
                    if (!empty($filter_nonprodids) && isset($hostGroupsMap[$hid])) {
                        if (array_intersect($hostGroupsMap[$hid], $filter_nonprodids)) {
                            $problem['is_production'] = false; // É Non-Prod/Homolog
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

        // --- 5. Dados para View ---
        $data_nonprod_groups = [];
        if (!empty($filter_nonprodids)) {
            $data_nonprod_groups = API::HostGroup()->get(['output' => ['groupid', 'name'], 'groupids' => $filter_nonprodids]);
        }

        $data = [
            'problems'          => $problems,
            'stats'             => $stats,
            'filter_nonprodids' => $data_nonprod_groups, // Dados para o Multiselect
            'filter_ack'        => $filter_ack,
            'filter_age'        => $filter_age,
            'filter_severities' => $filter_severities,
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('Zentinel: Command Center'));
        $this->setResponse($response);
    }
}