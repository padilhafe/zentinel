<?php declare(strict_types = 1);

/**
 * @var CView $this
 * @var array $data
 */

$page_title = _('Zentinel: Painel de Problemas'); 

// 1. Criar o Filtro
$filter = (new CFilter())
    ->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'zentinel.view')->setArgument('filter_rst', 1)) 
    ->setProfile('web.zentinel.filter') 
    ->setActiveTab(CProfile::get('web.zentinel.filter.active', 1));

// Formulário do Filtro
$filter_form = (new CFormList())
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
    
    ->addRow(_('Acknowledge status'), (new CRadioButtonList('filter_ack', (int)$data['filter_ack']))
        ->addValue(_('Any'), -1)
        ->addValue(_('Yes'), 1)
        ->addValue(_('No'), 0)
        ->setModern(true)
    )

    ->addRow(_('Older than (ex: 7d, 2h)'), 
        (new CTextBox('filter_age', $data['filter_age']))
            ->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH)
            ->setAttribute('placeholder', 'Ex: 1d')
    );

$filter->addFilterTab(_('Filter'), [$filter_form]);

// 2. Criar a Tabela
$table = (new CTableInfo())
    ->setHeader([
        _('Time'),
        _('Severity'),
        _('Host'),
        _('Problem'),
        _('Duration'),
        _('Ack')
    ]);

foreach ($data['problems'] as $problem) {
    $duration = zbx_date2age($problem['clock']);
    
    $severity_cell = new CCol(getSeverityName($problem['severity']));
    $severity_cell->addClass(getSeverityStyle($problem['severity']));

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

// 3. Exibir
(new CHtmlPage())
    ->setTitle($page_title)
    ->addItem($filter)
    ->addItem($table)
    ->show();
