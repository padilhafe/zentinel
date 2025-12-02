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

// 2. CSS + Gráfico
$css_content = '
    .zentinel-stats { display: flex; gap: 15px; margin-bottom: 20px; }
    .zentinel-card { 
        flex: 1; background: #fff; border: 1px solid #dbe1e5; border-left: 4px solid #0275b8; 
        padding: 20px; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); 
    }
    .dark-theme .zentinel-card { background: #2b2b2b; border-color: #383838; color: #f2f2f2; }
    .zentinel-value { font-size: 28px; font-weight: 800; display: block; margin-bottom: 5px; }
    .zentinel-label { font-size: 11px; text-transform: uppercase; color: #768d99; font-weight: 600; }
    .c-red { color: #e45959 !important; border-left-color: #e45959 !important; }
    .c-green { color: #59db8f !important; border-left-color: #59db8f !important; }
    .c-orange { color: #f24f1d !important; border-left-color: #f24f1d !important; }

    /* Graph Styles */
    .trend-container {
        display: flex; align-items: flex-end; justify-content: space-between;
        height: 80px; padding: 10px; background: #fff; border: 1px solid #dbe1e5;
        border-radius: 4px; margin-bottom: 20px; gap: 5px;
    }
    .dark-theme .trend-container { background: #2b2b2b; border-color: #383838; }
    .trend-bar-wrapper {
        flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%;
    }
    .trend-bar {
        width: 80%; background: #0275b8; border-radius: 2px 2px 0 0; min-height: 2px;
        transition: height 0.5s ease;
    }
    .trend-label { font-size: 9px; color: #768d99; margin-top: 5px; }
    .trend-value { font-size: 10px; font-weight: bold; margin-bottom: 2px; }

    /* Table Styles */
    .pulse-new { animation: new-entry-pulse 2s infinite; font-weight: bold; }
    @keyframes new-entry-pulse {
        0% { box-shadow: inset 3px 0 0 0 #0275b8; }
        50% { box-shadow: inset 3px 0 0 0 #4f9fcf; background-color: rgba(2, 117, 184, 0.05); }
        100% { box-shadow: inset 3px 0 0 0 #0275b8; }
    }
    .duration-long { color: #f24f1d; font-weight: bold; }
    .btn-ack { cursor: pointer; padding: 3px 8px; border: 1px solid #768d99; border-radius: 3px; color: #768d99; font-size: 11px; }
    .ack-done { border-color: #59db8f; color: #59db8f; }
';
$style_tag = new CTag('style', true, $css_content);

// 3. KPI Widgets
$stats_widget = (new CDiv())
    ->addClass('zentinel-stats')
    ->addItem([
        (new CDiv([(new CSpan($data['stats']['total']))->addClass('zentinel-value'), (new CSpan(_('Active Alerts')))->addClass('zentinel-label')]))->addClass('zentinel-card'),
        (new CDiv([(new CSpan($data['stats']['critical_count']))->addClass('zentinel-value c-red'), (new CSpan(_('Critical / High')))->addClass('zentinel-label')]))->addClass('zentinel-card c-red'),
        (new CDiv([(new CSpan($data['stats']['ack_count']))->addClass('zentinel-value c-green'), (new CSpan(_('Acknowledged')))->addClass('zentinel-label')]))->addClass('zentinel-card c-green'),
        (new CDiv([(new CSpan($data['stats']['avg_duration']))->addClass('zentinel-value c-orange'), (new CSpan(_('Avg Response Time')))->addClass('zentinel-label')]))->addClass('zentinel-card c-orange'),
    ]);

// 4. Trend Graph Widget (Visualização por Hora)
$trend_widget = (new CDiv())->addClass('trend-container');
$max_val = max($data['trend_data']) ?: 1; // Evita divisão por zero
foreach ($data['trend_data'] as $time => $count) {
    $height = ($count / $max_val) * 100; // Porcentagem da altura
    $color = ($count > 0) ? '#0275b8' : '#e0e0e0';
    
    // Se volume for alto, fica vermelho
    if ($count > 5) $color = '#e45959';

    $bar = (new CDiv())->addClass('trend-bar')
        ->setAttribute('style', "height: {$height}%; background-color: {$color};")
        ->setAttribute('title', "$count alerts at $time");
    
    $trend_widget->addItem((new CDiv([
        (new CSpan($count > 0 ? $count : ''))->addClass('trend-value'),
        $bar,
        (new CSpan($time))->addClass('trend-label')
    ]))->addClass('trend-bar-wrapper'));
}


// 5. Tabela com Ordenação
$createTable = function($problems) use ($data) {
    $sortLink = 'zabbix.php?action=zentinel.view'; // URL Base para ordenação

    $table = (new CTableInfo())
        ->setHeader([
            // Headers Clicáveis!
            make_sorting_header(_('Time'), 'clock', $data['sort'], $data['sortorder'], $sortLink),
            make_sorting_header(_('Severity'), 'severity', $data['sort'], $data['sortorder'], $sortLink),
            _('Host'), 
            _('Problem'), 
            _('Duration'), 
            _('Actions')
        ]);
    
    if (empty($problems)) {
        return $table->setNoDataMessage(_('All systems operational. No active alerts.'));
    }

    foreach ($problems as $problem) {
        $eventid = $problem['eventid'];
        
        $severity_cell = new CCol(\CSeverityHelper::getName((int)$problem['severity']));
        $severity_cell->addClass(\CSeverityHelper::getStyle((int)$problem['severity']));

        $duration = \zbx_date2age($problem['clock']);
        $seconds_ago = \time() - (int)$problem['clock'];
        $duration_class = ($seconds_ago > 86400) ? 'duration-long' : '';

        $row_class = '';
        if ($seconds_ago < 3600 && $problem['acknowledged'] == 0) $row_class = 'pulse-new';

        if ($problem['acknowledged'] == 1) {
            $ack_btn = (new CLink(_('Acked'), 'javascript:void(0)'))->addClass('btn-ack ack-done')->onClick("PopUp('popup.acknowledge.edit', {eventids: ['$eventid']})");
        } else {
            $ack_btn = (new CLink(_('Acknowledge'), 'javascript:void(0)'))->addClass('btn-ack')->onClick("PopUp('popup.acknowledge.edit', {eventids: ['$eventid']})");
        }
        $history_link = (new CLink(_('History'), 'tr_events.php?triggerid='.$problem['objectid'].'&eventid='.$eventid))->addClass('btn-ack')->setAttribute('target', '_blank');

        $row = $table->addRow([
            zbx_date2str(DATE_TIME_FORMAT_SECONDS, $problem['clock']),
            $severity_cell,
            (new CLink($problem['host_name'] ?? 'N/A', 'zabbix.php?action=host.dashboard.view&hostid=0'))->setTarget('_blank'),
            new CLink($problem['name'], 'tr_events.php?triggerid='.$problem['objectid'].'&eventid='.$eventid),
            (new CSpan($duration))->addClass($duration_class),
            (new CDiv([$ack_btn, ' ', $history_link]))
        ]);

        if ($row_class) $row->addClass($row_class);
    }
    return $table;
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
    ->addTab('tab_prod', _('Production Environment') . ' (' . count($prod_problems) . ')', $createTable($prod_problems))
    ->addTab('tab_nonprod', _('Non-Production / Others') . ' (' . count($non_prod_problems) . ')', $createTable($non_prod_problems))
    ->setSelected(0);

(new CHtmlPage())
    ->setTitle($page_title)
    ->addItem($style_tag)
    ->addItem($filter)
    ->addItem($stats_widget)
    ->addItem($trend_widget) // Adiciona o Gráfico aqui
    ->addItem($tabs)
    ->show();