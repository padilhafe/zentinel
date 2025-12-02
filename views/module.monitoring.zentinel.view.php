<?php declare(strict_types = 1);

/**
 * @var CView $this
 * @var array $data
 */

$page_title = _('Zentinel: Command Center'); 

// 1. Configuração e Filtros
$filter = (new CFilter())
    ->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'zentinel.view')->setArgument('filter_rst', 1)) 
    ->setProfile('web.zentinel.filter') 
    ->setActiveTab(CProfile::get('web.zentinel.filter.active', 1))
    ->addVar('action', 'zentinel.view');

// Array para o CheckboxList
$severities = [
    TRIGGER_SEVERITY_NOT_CLASSIFIED => _('Not classified'),
    TRIGGER_SEVERITY_INFORMATION    => _('Information'),
    TRIGGER_SEVERITY_WARNING        => _('Warning'),
    TRIGGER_SEVERITY_AVERAGE        => _('Average'),
    TRIGGER_SEVERITY_HIGH           => _('High'),
    TRIGGER_SEVERITY_DISASTER       => _('Disaster'),
];
$severity_options = [];
foreach ($severities as $severity => $label) {
    $severity_options[] = ['label' => $label, 'value' => $severity];
}

$filter_form = (new CFormList())
    ->addRow(_('Define as Non-Production'), (new CMultiSelect([
        'name' => 'filter_nonprodids[]',
        'object_name' => 'hostGroup',
        'data' => $data['filter_nonprodids'],
        'popup' => [
            'parameters' => [
                'srctbl' => 'host_groups',
                'srcfld1' => 'groupid',
                'dstfrm' => 'zbx_filter',
                'dstfld1' => 'filter_nonprodids_',
                'real_hosts' => 1
            ]
        ]
    ]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH))
    ->addRow(_('Severity'), (new CCheckBoxList('filter_severities'))->setOptions($severity_options)->setChecked($data['filter_severities'])->setColumns(3)->setVertical(true))
    ->addRow(_('Acknowledge status'), (new CRadioButtonList('filter_ack', (int)$data['filter_ack']))->addValue(_('Any'), -1)->addValue(_('Yes'), 1)->addValue(_('No'), 0)->setModern(true))
    ->addRow(_('Older than'), (new CTextBox('filter_age', $data['filter_age']))->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH));

$filter->addFilterTab(_('Configuração de Visualização'), [$filter_form]);

// 2. CSS Avançado (UX Improvements)
$css_content = '
    /* KPI Cards */
    .zentinel-stats { display: flex; gap: 15px; margin-bottom: 20px; }
    .zentinel-card { 
        flex: 1; 
        background: #fff; 
        border: 1px solid #dbe1e5; 
        border-left: 4px solid #0275b8; /* Zabbix Blue Accent */
        padding: 20px; 
        border-radius: 4px; 
        text-align: left;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        transition: transform 0.2s;
    }
    .zentinel-card:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
    .dark-theme .zentinel-card { background: #2b2b2b; border-color: #383838; border-left-color: #0275b8; color: #f2f2f2; }
    
    .zentinel-value { font-size: 28px; font-weight: 800; display: block; margin-bottom: 5px; color: #1f2c33; }
    .dark-theme .zentinel-value { color: #fff; }
    .zentinel-label { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #768d99; font-weight: 600; }
    
    /* Cores de KPI */
    .c-red { color: #e45959 !important; border-left-color: #e45959 !important; }
    .c-green { color: #59db8f !important; border-left-color: #59db8f !important; }
    .c-orange { color: #f24f1d !important; border-left-color: #f24f1d !important; }

    /* Tabela e Animações */
    .pulse-new {
        animation: new-entry-pulse 2s infinite;
        font-weight: bold;
    }
    @keyframes new-entry-pulse {
        0% { box-shadow: inset 3px 0 0 0 #0275b8; }
        50% { box-shadow: inset 3px 0 0 0 #4f9fcf; background-color: rgba(2, 117, 184, 0.05); }
        100% { box-shadow: inset 3px 0 0 0 #0275b8; }
    }
    .duration-long { color: #f24f1d; font-weight: bold; }
    
    /* Botões de Ação */
    .btn-ack {
        cursor: pointer;
        padding: 4px 10px;
        background: #fff;
        border: 1px solid #768d99;
        border-radius: 3px;
        color: #768d99;
        font-size: 11px;
        text-decoration: none;
        transition: all 0.2s;
    }
    .btn-ack:hover { background: #768d99; color: #fff; border-color: #768d99; text-decoration: none; }
    .dark-theme .btn-ack { background: transparent; border-color: #acb6bf; color: #acb6bf; }
    .dark-theme .btn-ack:hover { background: #acb6bf; color: #000; }
    
    .ack-done { border-color: #59db8f; color: #59db8f; }
    .ack-done:hover { background: #59db8f; color: #fff; }
';
$style_tag = new CTag('style', true, $css_content);

// 3. Widgets KPI
$stats_widget = (new CDiv())
    ->addClass('zentinel-stats')
    ->addItem([
        (new CDiv([
            (new CSpan($data['stats']['total']))->addClass('zentinel-value'),
            (new CSpan(_('Active Alerts')))->addClass('zentinel-label')
        ]))->addClass('zentinel-card'),
        
        (new CDiv([
            (new CSpan($data['stats']['critical_count']))->addClass('zentinel-value c-red'),
            (new CSpan(_('Critical / High')))->addClass('zentinel-label')
        ]))->addClass('zentinel-card c-red'),

        (new CDiv([
            (new CSpan($data['stats']['ack_count']))->addClass('zentinel-value c-green'),
            (new CSpan(_('Acknowledged')))->addClass('zentinel-label')
        ]))->addClass('zentinel-card c-green'),

        (new CDiv([
            (new CSpan($data['stats']['avg_duration']))->addClass('zentinel-value c-orange'),
            (new CSpan(_('Avg Response Time')))->addClass('zentinel-label')
        ]))->addClass('zentinel-card c-orange'),
    ]);

// 4. Helper de Tabela (Com Javascript Nativo do Zabbix)
$createTable = function($problems) {
    $table = (new CTableInfo())
        ->setHeader([
            _('Time'), 
            _('Severity'), 
            _('Host'), 
            _('Problem'), 
            _('Duration'), 
            _('Actions') // Nova coluna de Ação
        ]);
    
    if (empty($problems)) {
        return $table->setNoDataMessage(_('All systems operational. No active alerts.'));
    }

    foreach ($problems as $problem) {
        $eventid = $problem['eventid'];
        
        // UX: Severidade como Badge
        $severity_cell = new CCol(\CSeverityHelper::getName((int)$problem['severity']));
        $severity_cell->addClass(\CSeverityHelper::getStyle((int)$problem['severity']));

        // UX: Duração
        $duration = \zbx_date2age($problem['clock']);
        $seconds_ago = \time() - (int)$problem['clock'];
        $duration_class = ($seconds_ago > 86400) ? 'duration-long' : ''; // Vermelho se > 24h

        // UX: Animação de entrada (se < 1 hora e não Ack)
        $row_class = '';
        if ($seconds_ago < 3600 && $problem['acknowledged'] == 0) {
            $row_class = 'pulse-new';
        }

        // UX: Botão de Acknowledge
        // Usamos o PopUp nativo do Zabbix ('popup.acknowledge.edit')
        if ($problem['acknowledged'] == 1) {
            $ack_btn = (new CLink(_('Acked'), 'javascript:void(0)'))
                ->addClass('btn-ack ack-done')
                ->onClick("PopUp('popup.acknowledge.edit', {eventids: ['$eventid']})");
        } else {
            $ack_btn = (new CLink(_('Acknowledge'), 'javascript:void(0)'))
                ->addClass('btn-ack')
                ->onClick("PopUp('popup.acknowledge.edit', {eventids: ['$eventid']})");
        }

        // Link para Detalhes
        $history_link = (new CLink(_('History'), 
            'tr_events.php?triggerid='.$problem['objectid'].'&eventid='.$eventid
        ))->addClass('btn-ack')->setAttribute('target', '_blank');

        $actions_col = (new CDiv([$ack_btn, ' ', $history_link]));

        // Adiciona linha
        $row = $table->addRow([
            zbx_date2str(DATE_TIME_FORMAT_SECONDS, $problem['clock']),
            $severity_cell,
            (new CLink($problem['host_name'] ?? 'N/A', 'zabbix.php?action=host.dashboard.view&hostid=0'))->setTarget('_blank'), // Link placeholder
            new CLink($problem['name'], 'tr_events.php?triggerid='.$problem['objectid'].'&eventid='.$eventid),
            (new CSpan($duration))->addClass($duration_class),
            $actions_col
        ]);

        if ($row_class) {
            $row->addClass($row_class);
        }
    }
    return $table;
};

// 5. Separação de Dados
$prod_problems = [];
$non_prod_problems = [];
if (is_array($data['problems'])) {
    foreach ($data['problems'] as $p) {
        if ($p['is_production']) $prod_problems[] = $p;
        else $non_prod_problems[] = $p;
    }
}

// 6. Abas
$tabs = (new CTabView())
    ->addTab('tab_prod', 
        _('Production Environment') . ' (' . count($prod_problems) . ')', 
        $createTable($prod_problems)
    )
    ->addTab('tab_nonprod', 
        _('Non-Production / Others') . ' (' . count($non_prod_problems) . ')', 
        $createTable($non_prod_problems)
    )
    ->setSelected(0);

// 7. Render
(new CHtmlPage())
    ->setTitle($page_title)
    ->addItem($style_tag)
    ->addItem($filter)
    ->addItem($stats_widget)
    ->addItem($tabs)
    ->show();