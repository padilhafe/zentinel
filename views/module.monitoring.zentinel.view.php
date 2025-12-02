<?php declare(strict_types = 1);

/**
 * @var CView $this
 * @var array $data
 */

$page_title = _('Zentinel: Command Center'); 

// Carrega os Assets CSS e JS
$base_dir = dirname(__DIR__);
$css_file = $base_dir . '/assets/css/zentinel.css';
$js_file  = $base_dir . '/assets/js/zentinel.js';

$css_content = file_exists($css_file) ? file_get_contents($css_file) : '/* Erro: CSS não encontrado em ' . $css_file . ' */';
$js_content  = file_exists($js_file) ? file_get_contents($js_file) : 'console.error("Erro: JS não encontrado em ' . $js_file . '");';

$style_tag = new CTag('style', true, $css_content);

if ($js_content) {
    zbx_add_post_js($js_content);
}

// Filtros
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

// KPI Widgets
$stats_widget = (new CDiv())->addClass('zentinel-stats');
$stats_widget->addItem((new CDiv([(new CSpan($data['stats']['total']))->addClass('zentinel-value'), (new CSpan(_('Active Alerts')))->addClass('zentinel-label')]))->addClass('zentinel-kpi'));
$stats_widget->addItem((new CDiv([(new CSpan($data['stats']['critical_count']))->addClass('zentinel-value kpi-red'), (new CSpan(_('Critical')))->addClass('zentinel-label')]))->addClass('zentinel-kpi'));
$stats_widget->addItem((new CDiv([(new CSpan($data['stats']['ack_count']))->addClass('zentinel-value kpi-green'), (new CSpan(_('Acked')))->addClass('zentinel-label')]))->addClass('zentinel-kpi'));
$stats_widget->addItem((new CDiv([(new CSpan($data['stats']['avg_duration']))->addClass('zentinel-value kpi-orange'), (new CSpan(_('Avg Response Time')))->addClass('zentinel-label')]))->addClass('zentinel-kpi'));

// Trend Graph Widget
$trend_widget = (new CDiv())->addClass('trend-container');

// Botão reset controlado via JS
$trend_widget->addItem((new CDiv('× Clear Time Filter'))
    ->addClass('trend-clear-btn')
    ->setId('js-reset-btn')
    ->setAttribute('onclick', 'ZentinelFilter.reset()')
);

$max_val = 0;
foreach ($data['trend_data'] as $count) $max_val = max($max_val, $count);
$max_val = $max_val ?: 1;

foreach ($data['trend_data'] as $time_label => $count) {
    $height = ($count / $max_val) * 100;
    
    $color = ($count > 0) ? '#0275b8' : '#e0e0e0';
    if ($count > 5) $color = '#e45959';

    $bar = (new CDiv())->addClass('trend-bar')
        ->setAttribute('style', "height: {$height}%; background-color: {$color};");

    $wrapper = (new CDiv([
        (new CSpan($count > 0 ? $count : ''))->addClass('trend-value'),
        $bar,
        (new CSpan($time_label))->addClass('trend-label')
    ]))
    ->addClass('trend-bar-wrapper')
    ->setAttribute('title', "Filter by $time_label")
    ->setAttribute('onclick', "ZentinelFilter.filterByHour('$time_label', this)");

    $trend_widget->addItem($wrapper);
}

// Kanban Maker
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
            $hour_key = date('H:00', (int)$item['clock']); // Chave para filtro JS

            if ($item['acknowledged'] == 1) {
                $ackBtn = (new CLink('✔ Acked', 'javascript:void(0)'))->addClass('btn-small btn-ack-done')->onClick("PopUp('popup.acknowledge.edit', {eventids: ['$eventid']})");
            } else {
                $ackBtn = (new CLink('Acknowledge', 'javascript:void(0)'))->addClass('btn-small')->onClick("PopUp('popup.acknowledge.edit', {eventids: ['$eventid']})");
            }
            
            // CARD com DATA-HOUR
            $card = (new CDiv())->addClass('kanban-card')->setAttribute('data-hour', $hour_key);

            $color = \CSeverityHelper::getColor($sevId);
            $card->addStyle("border-left-color: #$color;");
            
            if ((\time() - (int)$item['clock'] < 3600) && $item['acknowledged'] == 0) $card->addClass('pulse-card');

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

// Abas e Separação
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

// Render
(new CHtmlPage())
    ->setTitle($page_title)
    ->addItem($style_tag)
    ->addItem($filter)
    ->addItem($stats_widget)
    ->addItem($trend_widget)
    ->addItem($tabs)
    ->show();