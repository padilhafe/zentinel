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
        // CORREÇÃO DEFINITIVA: 
        // Verifica se o usuário logado tem permissão de "User" ou superior.
        // A constante correta é USER_TYPE_ZABBIX_USER e precisa do '\' por ser global.
        return $this->getUserType() >= \USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        if ($this->hasInput('filter_rst')) {
            CProfile::delete('web.zentinel.filter.groupids');
            CProfile::delete('web.zentinel.filter.ack');
            CProfile::delete('web.zentinel.filter.age');
        }
        elseif ($this->hasInput('filter_set')) {
            // Adicionado '\' em todas as constantes globais
            CProfile::updateArray('web.zentinel.filter.groupids', $this->getInput('filter_groupids', []), \PROFILE_TYPE_ID);
            CProfile::update('web.zentinel.filter.ack', $this->getInput('filter_ack', -1), \PROFILE_TYPE_INT);
            CProfile::update('web.zentinel.filter.age', $this->getInput('filter_age', ''), \PROFILE_TYPE_STR);
        }

        $filter_groupids = CProfile::getArray('web.zentinel.filter.groupids', []);
        $filter_ack      = (int)CProfile::get('web.zentinel.filter.ack', -1);
        $filter_age      = CProfile::get('web.zentinel.filter.age', '');

        $options = [
            'output' => ['eventid', 'name', 'clock', 'severity', 'acknowledged', 'r_eventid'],
            'selectHosts' => ['hostid', 'name'],
            'sortfield' => ['clock'],
            'sortorder' => \ZBX_SORT_DOWN, // Adicionado '\'
            'recent' => true
        ];

        if ($filter_groupids) {
            $options['groupids'] = $filter_groupids;
        }

        if ($filter_ack !== -1) {
            $options['acknowledged'] = ($filter_ack == 1);
        }

        if ($filter_age !== '') {
            // timeUnitToSeconds e time são globais, precisam do '\'
            $seconds = \timeUnitToSeconds($filter_age);
            if ($seconds > 0) {
                $options['time_till'] = \time() - $seconds;
            }
        }

        $problems = API::Problem()->get($options);

        $data_groups = [];
        if ($filter_groupids) {
            $data_groups = API::HostGroup()->get([
                'output' => ['groupid', 'name'],
                'groupids' => $filter_groupids
            ]);
        }

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