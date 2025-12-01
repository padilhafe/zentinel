<?php declare(strict_types = 1);

/**
 * @var CView $this
 * @var array $data
 */

$page_title = _('Zentinel: Command Center'); 

// --- 1. Filtro Otimizado ---
$filter = (new CFilter())
    ->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'zentinel.view')->setArgument('filter_rst', 1)) 
    ->setProfile('web.zentinel.filter') 
    ->setActiveTab(CProfile::get('web.zentinel.filter.active', 1))
    ->addVar('action', 'zentinel.view');

$filter_form = (new CFormList())
    // Coluna 1: O que monitorar
    ->addRow(_('Show Host Groups'), (new CMultiSelect([
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
    
    // Coluna 2: Definição de Produção
    ->addRow(_('Define as Production'), (new CMultiSelect([
        'name' => 'filter_prodids[]',
        'object_name' => 'hostGroup',
        'data' => $data['filter_prodids'],
        'popup' => [
            'parameters' => [
                'srctbl' => 'host_groups',
                'srcfld1' => 'groupid',
                'dstfrm' => 'zbx_filter',
                'dstfld1' => 'filter_prodids_',
                'real_hosts' => 1
            ]
        ]
    ]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH))

    // Coluna 3: Filtros de Estado
    ->addRow(_('Acknowledge status'), (new CRadioButtonList('filter_ack', (int)$data['filter_ack']))
        ->addValue(_('Any'), -1)
        ->addValue(_('Yes'), 1)
        ->addValue(_('No'), 0)
        ->setModern(true)
    )
    ->addRow(_('Older than'), (new CTextBox('filter_age', $data['filter_age']))->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH));

$filter->addFilterTab(_('Configuração de Visualização'), [$filter_form]);

// --- 2. CSS Customizado para KPIs (In-line para simplicidade) ---
// Em produção real, isso iria para um assets/css/style.css
$css = '
    .zentinel-stats { display: flex; gap: 10px; margin-bottom: 10px; }
    .zentinel-card { 
        flex: 1; 
        background: #fff; 
        border: 1px solid #dbe1e5; 
        padding: 15px; 
        border-radius: 3px; 
        text-align: center;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    .dark-theme .zentinel-card { background: #2b2b2b; border-color: #383838; color: #f2f2f2; }
    .zentinel-value { font-size: 24px; font-weight: bold; display: block; margin-bottom: 5px; }
    .zentinel-label { font-size: 12px; text-transform: uppercase; color: #768d99; }
    .c-red { color: #e45959; }
    .c-green { color: #59db8f; }
    .c-orange { color: #f24f1d; }
';
$this->includeCss($css);

// --- 3. Cards de Estatísticas (KPIs) ---
$stats_widget = (new CDiv())
    ->addClass('zentinel-stats')
    ->addItem([
        (new CDiv([
            (new CSpan($data['stats']['total']))->addClass('zentinel-value'),
            (new CSpan(_('Total Problems')))->addClass('zentinel-label')
        ]))->addClass('zentinel-card'),
        
        (new CDiv([
            (new CSpan($data['stats']['critical_count']))->addClass('zentinel-value c-red'),
            (new CSpan(_('High/Disaster')))->addClass('zentinel-label')
        ]))->addClass('zentinel-card'),

        (new CDiv([
            (new CSpan($data['stats']['ack_count']))->addClass('zentinel-value c-green'),
            (new CSpan(_('Acknowledged')))->addClass('zentinel-label')
        ]))->addClass('zentinel-card'),

        (new CDiv([
            (new CSpan($data['stats']['avg_duration']))->addClass('zentinel-value c-orange'),
            (new CSpan(_('Avg Duration')))->addClass('zentinel-label')
        ]))->addClass('zentinel-card'),
    ]);


// --- 4. Função Auxiliar para Criar Tabelas ---
$createTable = function($problems) {
    $table = (new CTableInfo())
        ->setHeader([_('Time'), _('Severity'), _('Host'), _('Problem'), _('Duration'), _('Ack')]);
    
    if (empty($problems)) {
        return $table->setNoDataMessage(_('No problems found in this context.'));
    }

    foreach ($problems as $problem) {
        $severity_cell = new CCol(\CSeverityHelper::getName((int)$problem['severity']));
        $severity_cell->addClass(\CSeverityHelper::getStyle((int)$problem['severity']));

        $ack_status = ($problem['acknowledged'] == 1) 
            ? (new CSpan(_('Yes')))->addClass(ZBX_STYLE_GREEN) 
            : (new CSpan(_('No')))->addClass(ZBX_STYLE_RED);

        $table->addRow([
            zbx_date2str(DATE_TIME_FORMAT_SECONDS, $problem['clock']),
            $severity_cell,
            $problem['host_name'] ?? 'N/A',
            $problem['name'],
            zbx_date2age($problem['clock']),
            $ack_status
        ]);
    }
    return $table;
};

// Separar os dados
$prod_problems = [];
$non_prod_problems = [];

if (is_array($data['problems'])) {
    foreach ($data['problems'] as $p) {
        if ($p['is_production']) {
            $prod_problems[] = $p;
        } else {
            $non_prod_problems[] = $p;
        }
    }
}

// --- 5. Montar as Abas (Tabs) ---
$tabs = (new CTabView())
    ->addTab('tab_prod', 
        _('Production Environment') . ' (' . count($prod_problems) . ')', 
        $createTable($prod_problems)
    )
    ->addTab('tab_nonprod', 
        _('Non-Production / Others') . ' (' . count($non_prod_problems) . ')', 
        $createTable($non_prod_problems)
    )
    ->setSelected(0); // Abre na aba de Produção por padrão

// --- 6. Renderizar Página Final ---
(new CHtmlPage())
    ->setTitle($page_title)
    ->addItem($filter)
    ->addItem($stats_widget) // Adiciona os cards
    ->addItem($tabs)         // Adiciona as abas com as tabelas
    ->show();