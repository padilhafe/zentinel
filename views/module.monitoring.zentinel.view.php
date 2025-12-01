<?php declare(strict_types = 1);

use CControllerZentinelView;

/**
 * @var CView $this
 * @var array $data
 */

$page_title = _('Zentinel: Painel de Problemas'); 

// 1. Criar o Filtro
// Ajustado para usar a ação 'zentinel.view' e o perfil 'web.zentinel.filter'
$filter = (new CFilter())
    ->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'zentinel.view')->setArgument('filter_rst', 1)) 
    ->setProfile('web.zentinel.filter') 
    ->setActiveTab(CProfile::get('web.zentinel.filter.active', 1));

// Formulário do Filtro
$filter_form = (new CFormList())
    // Filtro de Grupos (MultiSelect Nativo)
    ->addRow(_('Host Groups'), (new CMultiSelect([
        'name' => 'filter_groupids[]',
        'object_name' => 'hostGroup',
        'data' => $data['filter_groupids'],
        'popup' => [
            'parameters' => [
                'srctbl' => 'host_groups',
                'srcfld1' => 'groupid',
                'dstfrm' => 'zbx_filter',
                'dstfld1' => 'filter_groupids_',
                'real_hosts' => 1
            ]
        ]
    ]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH))
    
    // Filtro de Ack
    ->addRow(_('Acknowledge status'), (new CRadioButtonList('filter_ack', (int)$data['filter_ack']))
        ->addValue(_('Any'), -1)
        ->addValue(_('Yes'), 1)
        ->addValue(_('No'), 0)
        ->setModern(true)
    )

    // Filtro de Idade (Older than)
    ->addRow(_('Older than (ex: 7d, 2h)'), 
        (new CTextBox('filter_age', $data['filter_age']))
            ->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH)
            ->setAttribute('placeholder', 'Ex: 1d')
    );

$filter->addFilterTab(_('Filter'), [$filter_form]);

// 2. Criar a Tabela de Resultados
$table = (new CTableInfo())
    ->setHeader([
        _('Time'),
        _('Severity'),
        _('Host'),
        _('Problem'),
        _('Duration'),
        _('Ack')
    ]);

// Preencher a tabela com os dados processados pelo Controller
foreach ($data['problems'] as $problem) {
    // Calcula a duração do problema
    $duration = zbx_date2age($problem['clock']);
    
    // Cor e rótulo da Severidade
    $severity_cell = new CCol(getSeverityName($problem['severity']));
    $severity_cell->addClass(getSeverityStyle($problem['severity']));

    // Status do Ack
    $ack_status = ($problem['acknowledged'] == 1) 
        ? (new CSpan(_('Yes')))->addClass(ZBX_STYLE_GREEN) 
        : (new CSpan(_('No')))->addClass(ZBX_STYLE_RED);

    $host_name = $problem['hosts'][0]['name'] ?? 'N/A';

    $table->addRow([
        zbx_date2str(DATE_TIME_FORMAT_SECONDS, $problem['clock']),
        $severity_cell,
        $host_name,
        $problem['name'],
        $duration,
        $ack_status
    ]);
}

// Montar a Página Final
(new CHtmlPage())
    ->setTitle($page_title)
    ->addItem($filter)
    ->addItem($table)
    ->show();