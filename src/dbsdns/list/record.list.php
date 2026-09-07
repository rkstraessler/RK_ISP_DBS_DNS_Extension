<?php

$liste['name'] = 'dbsdns_record';
$liste['table'] = 'dbsdns_live_record';
$liste['table_idx'] = 'id';
$liste['search_prefix'] = 'search_';
$liste['records_per_page'] = '15';
$liste['file'] = 'zone_view.php';
$liste['edit_file'] = '';
$liste['delete_file'] = 'record_delete.php';
$liste['paging_tpl'] = 'templates/paging.tpl.htm';
$liste['auth'] = 'no';

$liste['item'][] = array(
    'field' => 'active', 'datatype' => 'VARCHAR', 'formtype' => 'SELECT',
    'op' => '=', 'prefix' => '', 'suffix' => '', 'width' => '',
    'value' => array('Y' => $app->lng('yes_txt'))
);
$liste['item'][] = array(
    'field' => 'type', 'datatype' => 'VARCHAR', 'formtype' => 'TEXT',
    'op' => 'like', 'prefix' => '%', 'suffix' => '%', 'width' => '', 'value' => ''
);

foreach(array('name', 'data', 'aux', 'ttl') as $fieldName) {
    $liste['item'][] = array(
        'field' => $fieldName, 'datatype' => 'VARCHAR', 'formtype' => 'TEXT',
        'op' => 'like', 'prefix' => '%', 'suffix' => '%', 'width' => '', 'value' => ''
    );
}

