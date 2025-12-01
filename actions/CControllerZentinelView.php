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
            'filter_ack'      => 'in -1,0,1',
            'filter_age'      => 'string',
            'filter_set'      => 'in 1',
            'filter_rst'      => 'in 1'
        ];

        return $this->validateInput($fields);
    }

    protected function checkPermissions(): bool {
        // Verifica permissão de usuário logado
        return $this->getUserType() >= \USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        // --- 1. Gerenciamento de Filtro ---
        if ($this->hasInput('filter_rst')) {
            CProfile::delete('web.zentinel.filter.groupids');
            CProfile::delete('web.zentinel.filter.ack');
            CProfile::delete('web.zentinel.filter.age');
        }
        elseif ($this->hasInput('filter_set')) {
            CProfile::updateArray('web.zentinel.filter.groupids', $this->getInput('filter_groupids', []), \PROFILE_TYPE_ID);
            CProfile::update('web.zentinel.filter.ack', $this->getInput('filter_ack', -1), \PROFILE_TYPE_INT);
            CProfile::update('web.zentinel.filter.age', $this->getInput('filter_age', ''), \PROFILE_TYPE_STR);
        }

        $filter_groupids = CProfile::getArray('web.zentinel.filter.groupids', []);
        $filter_ack      = (int)CProfile::get('web.zentinel.filter.ack', -1);
        $filter_age      = CProfile::get('web.zentinel.filter.age', '');

        // --- 2. Busca de Problemas (Sem selectHosts) ---
        $options = [
            'output' => ['eventid', 'objectid', 'name', 'clock', 'severity', 'acknowledged', 'r_eventid'],
            'sortfield' => ['clock'],
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
            if ($seconds > 0) {
                $options['time_till'] = \time() - $seconds;
            }
        }

        // Executa a busca. Se falhar, retorna array vazio para não quebrar a View.
        $problems = API::Problem()->get($options);
        if ($problems === false) {
            $problems = [];
        }

        // --- 3. Busca de Hosts (Estratégia Two-Step) ---
        // Problemas estão ligados a Triggers (objectid). Vamos buscar os hosts dessas triggers.
        if ($problems) {
            $triggerIds = array_column($problems, 'objectid');
            
            // Busca triggers com seus hosts
            $triggers = API::Trigger()->get([
                'output' => ['triggerid'],
                'selectHosts' => ['name'], // Aqui o selectHosts funciona garantido
                'triggerids' => $triggerIds,
                'preservekeys' => true
            ]);

            // Mapeia o Host de volta para o Problema
            foreach ($problems as &$problem) {
                $triggerId = $problem['objectid'];
                if (isset($triggers[$triggerId]) && !empty($triggers[$triggerId]['hosts'])) {
                    // Pega o primeiro host (padrão Zabbix)
                    $problem['host_name'] = $triggers[$triggerId]['hosts'][0]['name'];
                } else {
                    $problem['host_name'] = 'N/A';
                }
            }
            unset($problem); // Boa prática ao usar referência
        }

        // --- 4. Busca dados para o Filtro (Select de Grupos) ---
        $data_groups = [];
        if ($filter_groupids) {
            $data_groups = API::HostGroup()->get([
                'output' => ['groupid', 'name'],
                'groupids' => $filter_groupids
            ]);
        }

        // --- 5. Envia para a View ---
        $data = [
            'problems'        => $problems,
            'filter_groupids' => $data_groups,
            'filter_ack'      => $filter_ack,
            'filter_age'      => $filter_age
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('Zentinel: Problemas em Foco'));
        $this->setResponse($response);
    }
}