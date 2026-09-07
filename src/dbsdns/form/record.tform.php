<?php

require_once __DIR__ . '/../lib/classes/DbsRecordCapabilities.inc.php';

$recordType = isset($dbsdnsRecordType) && is_string($dbsdnsRecordType)
    ? strtoupper($dbsdnsRecordType)
    : (isset($_REQUEST['type']) && is_scalar($_REQUEST['type'])
        ? strtoupper((string)$_REQUEST['type'])
        : '');

if(!DbsRecordCapabilities::isWritable($recordType)) {
    $recordType = '';
}

$isRecordEdit = isset($_REQUEST['record_token'])
    && is_string($_REQUEST['record_token'])
    && $_REQUEST['record_token'] !== '';

$form['title'] = 'DNS ' . $recordType;
$form['description'] = '';
$form['name'] = 'dbsdns_record';
$form['action'] = 'record_edit.php';
$form['db_table'] = 'dbsdns_virtual_record';
$form['db_table_idx'] = 'id';
$form['db_history'] = 'no';
$form['tab_default'] = 'dns';
$form['list_default'] = 'zone_list.php';
$form['auth'] = 'no';
$form['tabs']['dns'] = array(
    'title' => 'DNS ' . $recordType,
    'width' => 100,
    'template' => 'templates/record_edit.htm',
    'fields' => array()
);

$fields = &$form['tabs']['dns']['fields'];
$fields['zone'] = array(
    'datatype' => 'INTEGER', 'formtype' => 'TEXT',
    'default' => isset($_REQUEST['zone']) ? (int)$_REQUEST['zone'] : 0,
    'value' => '', 'width' => '30', 'maxlength' => '20'
);
$fields['name'] = array(
    'datatype' => 'VARCHAR', 'formtype' => 'TEXT',
    'filters' => array(
        array('event' => 'SAVE', 'type' => 'IDNTOASCII'),
        array('event' => 'SHOW', 'type' => 'IDNTOUTF8'),
        array('event' => 'SAVE', 'type' => 'TOLOWER')
    ),
    'default' => '', 'value' => '', 'width' => '30', 'maxlength' => '255'
);

if($recordType === 'CNAME') {
    $fields['name']['validators'] = array(
        array('type' => 'NOTEMPTY', 'errmsg' => 'cname_name_error_invalid')
    );
} elseif($recordType === 'SRV') {
    $fields['name']['validators'] = array(
        array('type' => 'NOTEMPTY', 'errmsg' => 'srv_name_error_invalid')
    );
}
$fields['type'] = array(
    'datatype' => 'VARCHAR', 'formtype' => 'TEXT',
    'default' => $recordType, 'value' => '', 'width' => '5', 'maxlength' => '5'
);
if($recordType !== 'SRV') {
    $dataEmptyError = $recordType === 'A'
        ? 'ipv4_error_invalid'
        : ($recordType === 'AAAA'
            ? 'ipv6_error_invalid'
            : ($recordType === 'CNAME'
                ? 'cname_target_error_invalid'
                : ($recordType === 'MX'
                    ? 'mx_target_error_invalid'
                    : ($recordType === 'NS' ? 'ns_target_error_invalid' : 'txt_error_invalid'))));
    $fields['data'] = array(
        'datatype' => 'VARCHAR', 'formtype' => 'TEXT',
        'validators' => array(array('type' => 'NOTEMPTY', 'errmsg' => $dataEmptyError)),
        'default' => '', 'value' => '', 'width' => '30',
        'maxlength' => $recordType === 'TXT' ? ($isRecordEdit ? '2048' : '512') : '255'
    );

    if($recordType === 'A') {
        $fields['data']['validators'][] = array('type' => 'ISIPV4', 'errmsg' => 'ipv4_error_invalid');
    } elseif($recordType === 'AAAA') {
        $fields['data']['validators'][] = array('type' => 'ISIPV6', 'errmsg' => 'ipv6_error_invalid');
    } elseif(in_array($recordType, array('CNAME', 'MX', 'NS'), true)) {
        $fields['data']['filters'] = array(
            array('event' => 'SAVE', 'type' => 'IDNTOASCII'),
            array('event' => 'SHOW', 'type' => 'IDNTOUTF8'),
            array('event' => 'SAVE', 'type' => 'TOLOWER')
        );
    }
}

$integerFields = array();

if(in_array($recordType, array('MX', 'SRV'), true)) {
    $integerFields[] = 'aux';
}

if($recordType === 'SRV') {
    $integerFields[] = 'weight';
    $integerFields[] = 'port';
}

foreach($integerFields as $integerField) {
    $integerError = $integerField === 'weight'
        ? 'weight_error_invalid'
        : ($integerField === 'port' ? 'port_error_invalid' : 'priority_error_invalid');
    $fields[$integerField] = array(
        'datatype' => 'INTEGER', 'formtype' => 'TEXT',
        'validators' => array(
            array('type' => 'ISINT', 'errmsg' => $integerError),
            array('type' => 'RANGE', 'range' => '0:65535', 'errmsg' => $integerError)
        ),
        'default' => $integerField === 'aux' && $recordType === 'MX' ? '10' : '0',
        'value' => '', 'width' => '10', 'maxlength' => '10'
    );
}

if($recordType === 'SRV') {
    $fields['target'] = array(
        'datatype' => 'VARCHAR', 'formtype' => 'TEXT',
        'filters' => array(
            array('event' => 'SAVE', 'type' => 'IDNTOASCII'),
            array('event' => 'SHOW', 'type' => 'IDNTOUTF8'),
            array('event' => 'SAVE', 'type' => 'TOLOWER')
        ),
        'validators' => array(array('type' => 'NOTEMPTY', 'errmsg' => 'srv_target_error_invalid')),
        'default' => '', 'value' => '', 'width' => '30', 'maxlength' => '255'
    );
}

$fields['ttl'] = array(
    'datatype' => 'INTEGER', 'formtype' => 'TEXT',
    'validators' => array(
        array('type' => 'ISINT', 'errmsg' => $isRecordEdit ? 'ttl_edit_error_invalid' : 'ttl_error_invalid'),
        array('type' => 'RANGE', 'range' => $isRecordEdit ? '1:' : '60:', 'errmsg' => $isRecordEdit ? 'ttl_edit_error_invalid' : 'ttl_error_invalid')
    ),
    'default' => '3600', 'value' => '', 'width' => '10', 'maxlength' => '10'
);
$fields['active'] = array(
    'datatype' => 'VARCHAR', 'formtype' => 'CHECKBOX',
    'default' => 'Y', 'value' => array(0 => 'N', 1 => 'Y')
);
