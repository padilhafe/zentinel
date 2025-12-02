<?php declare(strict_types = 1);

/**
 * @var CView $this
 * @var array $data
 */

$page_title = _('Zentinel: Command Center'); 

// 1. Filtros
$filter = (new CFilter())
    ->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'zentinel.view')->setArgument('filter_rst', 1)) 
    ->setProfile('web.zentinel.filter') 
    ->setActiveTab(CProfile::get('web.zentinel.filter.active', 1))
    ->addVar('action', 'zentinel.view');

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
    // Filtros de Escopo
    ->addRow(_('Host groups'), (new CMultiSelect([
        'name' => 'filter_groupids[]',
        'object_name' => 'hostGroup',
        'data' => $data['filter_groupids'],
        'popup' => [
            'parameters' => [
                'srctbl' => 'host_groups',
                'srcfld1' => 'groupid',
                'dstfrm' => 'zbx_filter',
                'dstfld1' => 'filter_groupids_',
                'real_hosts' => 1,
                'enrich_parent_groups' => true
            ]
        ]
    ]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH))
    
    ->addRow(_('Hosts'), (new CMultiSelect([
        'name' => 'filter_hostids[]',
        'object_name' => 'hosts',
        'data' => $data['filter_hostids'],
        'popup' => [
            'parameters' => [
                'srctbl' => 'hosts',
                'srcfld1' => 'hostid',
                'dstfrm' => 'zbx_filter',
                'dstfld1' => 'filter_hostids_',
                'real_hosts' => 1
            ]
        ]
    ]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH))
    
    // Filtros de Classificação
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

// 2. CSS Profissional
$css_content = '
    .zentinel-stats { display: flex; gap: 15px; margin-bottom: 20px; }
    .zentinel-kpi { flex: 1; background: #fff; padding: 15px; border-radius: 4px; border: 1px solid #dbe1e5; text-align: center; }
    .zentinel-value { font-size: 24px; font-weight: bold; display: block; }
    .zentinel-label { font-size: 10px; text-transform: uppercase; color: #768d99; }
    
    /* Cores KPI Texto */
    .kpi-red .zentinel-value { color: #e45959; }
    .kpi-green .zentinel-value { color: #59db8f; }
    .kpi-orange .zentinel-value { color: #f24f1d; }

    /* Graph Styles */
    .trend-container {
        display: flex; align-items: flex-end; justify-content: space-between;
        height: 80px; padding: 10px; background: #fff; border: 1px solid #dbe1e5;
        border-radius: 4px; margin-bottom: 20px; gap: 5px;
    }
    .dark-theme .trend-container { background: #2b2b2b; border-color: #383838; }
    .trend-bar-wrapper { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; }
    .trend-bar { width: 80%; background: #0275b8; border-radius: 2px 2px 0 0; min-height: 2px; transition: height 0.5s ease; }
    .trend-label { font-size: 9px; color: #768d99; margin-top: 5px; }
    .trend-value { font-size: 10px; font-weight: bold; margin-bottom: 2px; }

    /* KANBAN BOARD */
    .kanban-board { display: flex; gap: 15px; align-items: flex-start; overflow-x: auto; padding-bottom: 10px; }
    .kanban-col { 
        flex: 1; min-width: 300px; background: #f4f4f4; border-radius: 6px; padding: 10px; border-top: 4px solid #ccc;
    }
    .dark-theme .kanban-col { background: #2b2b2b; border-color: #383838; }

    .col-disaster { border-top-color: #e45959; background: rgba(228, 89, 89, 0.05); }
    .col-high { border-top-color: #e45959; background: rgba(228, 89, 89, 0.02); }
    .col-average { border-top-color: #ffc859; }
    .col-warning { border-top-color: #ffc859; }
    .col-info { border-top-color: #0275b8; }

    .kanban-header { 
        font-weight: bold; text-transform: uppercase; color: #555; margin-bottom: 10px; 
        display: flex; justify-content: space-between; font-size: 11px;
    }
    .dark-theme .kanban-header { color: #acb6bf; }

    .kanban-card {
        background: #fff; border: 1px solid #dbe1e5; border-radius: 3px; 
        padding: 12px; margin-bottom: 10px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        border-left: 3px solid transparent;
        transition: transform 0.2s;
    }
    .kanban-card:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
    .dark-theme .kanban-card { background: #2b2b2b; border-color: #383838; color: #f2f2f2; }

    .card-header { display: flex; justify-content: space-between; font-size: 10px; color: #768d99; margin-bottom: 5px; }
    .card-title { font-weight: bold; font-size: 12px; margin-bottom: 5px; display: block; color: #1f2c33; text-decoration: none; }
    .dark-theme .card-title { color: #f2f2f2; }
    .card-host { font-size: 11px; color: #0275b8; margin-bottom: 8px; font-weight: 600; }
    
    .card-actions { display: flex; justify-content: space-between; align-items: center; margin-top: 8px; border-top: 1px solid #f0f0f0; padding-top: 8px; }
    .dark-theme .card-actions { border-top-color: #383838; }

    .pulse-card { animation: pulse-border 2s infinite; }
    @keyframes pulse-border { 0% { box-shadow: 0 0 0 0 rgba(2, 117, 184, 0.4); } 70% { box-shadow: 0 0 0 6px rgba(2, 117, 184, 0); } 100% { box-shadow: 0 0 0 0 rgba(2, 117, 184, 0); } }

    .btn-small { font-size: 10px; padding: 2px 6px; border: 1px solid #ccc; border-radius: 3px; color: #555; cursor: pointer; }
    .btn-ack-done { color: #59db8f; border-color: #59db8f; }
';
$style_tag = new CTag('style', true, $css_content);

// 3. KPI Widgets (RESTAURADO)
$stats_widget = (new CDiv())->addClass('zentinel-stats');

// Card 1: Total
$stats_widget->addItem((new CDiv([
    (new CSpan($data['stats']['total']))->addClass('zentinel-value'), 
    (new CSpan(_('Active Alerts')))->addClass('zentinel-label')
]))->addClass('zentinel-kpi'));

// Card 2: Críticos
$stats_widget->addItem((new CDiv([
    (new CSpan($data['stats']['critical_count']))->addClass('zentinel-value kpi-red'), 
    (new CSpan(_('Critical')))->addClass('zentinel-label')
]))->addClass('zentinel-kpi'));

// Card 3: Acked
$stats_widget->addItem((new CDiv([
    (new CSpan($data['stats']['ack_count']))->addClass('zentinel-value kpi-green'), 
    (new CSpan(_('Acked')))->addClass('zentinel-label')
]))->addClass('zentinel-kpi'));

// Card 4: Duração Média (RESTAURADO)
$stats_widget->addItem((new CDiv([
    (new CSpan($data['stats']['avg_duration']))->addClass('zentinel-value kpi-orange'), 
    (new CSpan(_('Avg Response Time')))->addClass('zentinel-label')
]))->addClass('zentinel-kpi'));


// 4. Trend Graph Widget
$trend_widget = (new CDiv())->addClass('trend-container');
$max_val = max($data['trend_data']) ?: 1;
foreach ($data['trend_data'] as $time => $count) {
    $height = ($count / $max_val) * 100;
    $color = ($count > 0) ? '#0275b8' : '#e0e0e0';
    if ($count > 5) $color = '#e45959';
    $bar = (new CDiv())->addClass('trend-bar')->setAttribute('style', "height: {$height}%; background-color: {$color};")->setAttribute('title', "$count alerts");
    $trend_widget->addItem((new CDiv([(new CSpan($count > 0 ? $count : ''))->addClass('trend-value'), $bar, (new CSpan($time))->addClass('trend-label')]))->addClass('trend-bar-wrapper'));
}

// 5. Kanban Maker
$createKanban = function($problems) {
    if (empty($problems)) {
        return (new CDiv(_('All systems operational. Have a coffee! ☕')))->addStyle('padding: 20px; text-align: center; color: green;');
    }
    $columns = [
        TRIGGER_SEVERITY_DISASTER => ['label' => _('DISASTER'), 'class' => 'col-disaster', 'items' => []],
        TRIGGER_SEVERITY_HIGH     => ['label' => _('HIGH'),     'class' => 'col-high',     'items' => []],
        TRIGGER_SEVERITY_AVERAGE  => ['label' => _('AVERAGE'),  'class' => 'col-average',  'items' => []],
        TRIGGER_SEVERITY_WARNING  => ['label' => _('WARNING'),  'class' => 'col-warning',  'items' => []],
        TRIGGER_SEVERITY_INFORMATION => ['label' => _('INFO'),   'class' => 'col-info',     'items' => []],
    ];
    foreach ($problems as $p) {
        $sev = (int)$p['severity'];
        if (isset($columns[$sev])) $columns[$sev]['items'][] = $p;
        else $columns[TRIGGER_SEVERITY_INFORMATION]['items'][] = $p;
    }
    $board = (new CDiv())->addClass('kanban-board');
    foreach ($columns as $sevId => $col) {
        if (empty($col['items']) && $sevId < TRIGGER_SEVERITY_AVERAGE) continue;
        $colDiv = (new CDiv())->addClass('kanban-col ' . $col['class']);
        $colDiv->addItem((new CDiv([(new CSpan($col['label'])), (new CSpan(count($col['items'])))->addClass('trend-value')]))->addClass('kanban-header'));
        foreach ($col['items'] as $item) {
            $eventid = $item['eventid'];
            $duration = \zbx_date2age($item['clock']);
            $is_new = (\time() - (int)$item['clock'] < 3600);
            if ($item['acknowledged'] == 1) {
                $ackBtn = (new CLink('✔ Acked', 'javascript:void(0)'))->addClass('btn-small btn-ack-done')->onClick("PopUp('popup.acknowledge.edit', {eventids: ['$eventid']})");
            } else {
                $ackBtn = (new CLink('Acknowledge', 'javascript:void(0)'))->addClass('btn-small')->onClick("PopUp('popup.acknowledge.edit', {eventids: ['$eventid']})");
            }
            $card = (new CDiv())->addClass('kanban-card');
            $color = \CSeverityHelper::getColor($sevId);
            $card->addStyle("border-left-color: #$color;");
            if ($is_new && $item['acknowledged'] == 0) $card->addClass('pulse-card');
            $card->addItem((new CDiv([(new CSpan(zbx_date2str('H:i', $item['clock'])))->setAttribute('title', zbx_date2str(DATE_TIME_FORMAT_SECONDS, $item['clock'])), (new CSpan($duration))->addClass('trend-value')]))->addClass('card-header'));
            $card->addItem((new CDiv($item['host_name']))->addClass('card-host'));
            $card->addItem(new CLink($item['name'], 'tr_events.php?triggerid='.$item['objectid'].'&eventid='.$eventid, 'card-title'));
            $card->addItem((new CDiv([$ackBtn, (new CLink('Hist', 'tr_events.php?triggerid='.$item['objectid'].'&eventid='.$eventid))->setAttribute('target', '_blank')->addClass('btn-small')]))->addClass('card-actions'));
            $colDiv->addItem($card);
        }
        $board->addItem($colDiv);
    }
    return $board;
};

$prod_problems = [];
$non_prod_problems = [];
if (is_array($data['problems'])) {
    foreach ($data['problems'] as $p) {
        if ($p['is_production']) $prod_problems[] = $p;
        else $non_prod_problems[] = $p;
    }
}

$tabs = (new CTabView())
    ->addTab('tab_prod', _('Production Environment'), $createKanban($prod_problems))
    ->addTab('tab_nonprod', _('Non-Production / Others'), $createKanban($non_prod_problems))
    ->setSelected(0);

(new CHtmlPage())
    ->setTitle($page_title)
    ->addItem($style_tag)
    ->addItem($filter)
    ->addItem($stats_widget)
    ->addItem($trend_widget)
    ->addItem($tabs)
    ->show();