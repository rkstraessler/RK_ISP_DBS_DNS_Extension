<?php

$liste['name'] = 'dbsdns_zone';
$liste['table'] = 'dbsdns_zone_list';
$liste['table_idx'] = 'id';
$liste['search_prefix'] = 'search_';
$liste['records_per_page'] = '15';
$liste['file'] = 'zone_list.php';
$liste['edit_file'] = 'zone_view.php';
$liste['delete_file'] = '';
$liste['paging_tpl'] = 'templates/paging.tpl.htm';
$liste['auth'] = 'no';

$liste['item'][] = array(
    'field' => 'active',
    'datatype' => 'VARCHAR',
    'formtype' => 'SELECT',
    'op' => '=',
    'prefix' => '',
    'suffix' => '',
    'width' => '',
    'value' => array('Y' => $app->lng('yes_txt'), 'N' => $app->lng('no_txt'))
);

$liste['item'][] = array(
    'field' => 'server_id',
    'datatype' => 'VARCHAR',
    'formtype' => 'SELECT',
    'op' => '=',
    'prefix' => '',
    'suffix' => '',
    'width' => '',
    'value' => array('DBS' => 'DBS')
);

$liste['item'][] = array(
    'field' => 'client_name',
    'datatype' => 'VARCHAR',
    'formtype' => 'TEXT',
    'op' => 'like',
    'prefix' => '%',
    'suffix' => '%',
    'width' => '',
    'value' => ''
);

$liste['item'][] = array(
    'field' => 'origin',
    'datatype' => 'VARCHAR',
    'filters' => array(
        array('event' => 'SHOW', 'type' => 'IDNTOUTF8')
    ),
    'formtype' => 'TEXT',
    'op' => 'like',
    'prefix' => '%',
    'suffix' => '%',
    'width' => '',
    'value' => ''
);

$liste['item'][] = array(
    'field' => 'provider_status',
    'datatype' => 'VARCHAR',
    'formtype' => 'TEXT',
    'op' => 'like',
    'prefix' => '%',
    'suffix' => '%',
    'width' => '',
    'value' => ''
);

