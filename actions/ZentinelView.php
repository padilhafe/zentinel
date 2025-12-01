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
        // Valida os campos de filtro que serão recebidos via GET/POST
        $fields = [
            'filter_groupids' => 'array_db hosts_groups.groupid',
            'filter_ack'      => 'in -1,0,1', // -1: Todos, 0: Não Ack, 1: Ack
            'filter_age'      => 'string',     // Ex: "7d", "24h"
            'filter_set'      => 'in 1',       // Botão Aplicar
            'filter_rst'      => 'in 1'        // Botão Resetar
        ];
        return $this->validateInput($fields);
    }

    protected function checkPermissions(): bool {
        // Permite acesso a qualquer usuário Zabbix logado
        return $this->checkAccess(CROLE_USER_TYPE_ZABBIX_USER);
    }

    protected function doAction(): void {
        // 1. Gerenciar Perfil de Filtro (Salvar/Resetar)
        // Usa "zentinel" como chave de perfil
        if ($this->hasInput('filter_rst')) {
            CProfile::delete('web.zentinel.filter.groupids');
            CProfile::delete('web.zentinel.filter.ack');
            CProfile::delete('web.zentinel.filter.age');
        }
        elseif ($this->hasInput('filter_set')) {
            CProfile::updateArray('web.zentinel.filter.groupids', $this->getInput('filter_groupids', []), PROFILE_TYPE_ID);
            CProfile::update('web.zentinel.filter.ack', $this->getInput('filter_ack', -1), PROFILE_TYPE_INT);
            CProfile::update('web.zentinel.filter.age', $this->getInput('filter_age', ''), PROFILE_TYPE_STR);
        }

        // 2. Ler valores atuais (do input ou do perfil salvo)
        $filter_groupids = CProfile::getArray('web.zentinel.filter.groupids', []);
        $filter_ack      = (int)CProfile::get('web.zentinel.filter.ack', -1);
        $filter_age      = CProfile::get('web.zentinel.filter.age', '');

        // 3. Montar a consulta da API
        $options = [
            'output' => ['eventid', 'name', 'clock', 'severity', 'acknowledged', 'r_eventid'],
            'selectHosts' => ['hostid', 'name'],
            'sortfield' => ['clock'],
            'sortorder' => ZBX_SORT_DOWN,
            'recent' => true // Apenas problemas não resolvidos (ou resolvidos há pouco tempo)
        ];

        // Filtro: Grupos de Host
        if ($filter_groupids) {
            $options['groupids'] = $filter_groupids;
        }

        // Filtro: Acknowledge (Se não for -1/Todos)
        if ($filter_ack !== -1) {
            $options['acknowledged'] = ($filter_ack == 1);
        }

        // Filtro: Mais antigas que X tempo (time_till)
        if ($filter_age !== '') {
            $seconds = timeUnitToSeconds($filter_age);
            if ($seconds > 0) {
                // time_till busca problemas criados ANTES de (agora - tempo)
                $options['time_till'] = time() - $seconds;
            }
        }

        // Busca dados dos problemas
        $problems = API::Problem()->get($options);

        // Prepara dados dos grupos para popular o multiselect
        $data_groups = [];
        if ($filter_groupids) {
            $data_groups = API::HostGroup()->get([
                'output' => ['groupid', 'name'],
                'groupids' => $filter_groupids
            ]);
        }

        // Passar tudo para a View
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