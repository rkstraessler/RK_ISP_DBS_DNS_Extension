<?php

$tform_def_file = 'form/record.tform.php';

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once __DIR__ . '/lib/classes/DbsRecordCapabilities.inc.php';
require_once __DIR__ . '/lib/classes/DbsRecordIdentity.inc.php';

$app->auth->check_module_permissions('dbsdns');
$recordTokenProvided = array_key_exists('record_token', $_REQUEST);

if($recordTokenProvided && !is_string($_REQUEST['record_token'])) {
    http_response_code(403);
    $app->error($app->lng('error_no_view_permission'));
    exit;
}

$recordToken = $recordTokenProvided ? $_REQUEST['record_token'] : '';
$recordType = '';

if($recordToken !== '') {
    try {
        $recordIdentity = new DbsRecordIdentity();
        $verifiedRecord = $recordIdentity->verify($recordToken);
        $recordType = $verifiedRecord['record']['type'];
        $_REQUEST['zone'] = (string)$verifiedRecord['cache_id'];
        $_REQUEST['type'] = $recordType;
        $_REQUEST['id'] = '0';
    } catch (DbsRecordIdentityException $exception) {
        http_response_code(403);
        $app->error($app->lng('error_no_view_permission'));
        exit;
    }
} else {
    $recordType = isset($_REQUEST['type']) && is_scalar($_REQUEST['type'])
        ? strtoupper((string)$_REQUEST['type'])
        : '';
}

if(!DbsRecordCapabilities::isWritable($recordType)) {
    http_response_code(403);
    $app->error($app->lng('error_no_view_permission'));
    exit;
}

$dbsdnsRecordType = $recordType;

$language = $app->functions->check_language($_SESSION['s']['language']);
$languageFile = 'web/dbsdns/lib/lang/' . $language . '_dbsdns.lng';

if(!is_file(ISPC_ROOT_PATH . '/' . $languageFile)) {
    $languageFile = 'web/dbsdns/lib/lang/en_dbsdns.lng';
}

$app->load_language_file($languageFile);
$recordLanguageFile = 'web/dbsdns/lib/lang/' . $language . '_dbsdns_record.lng';

if(!is_file(ISPC_ROOT_PATH . '/' . $recordLanguageFile)) {
    $recordLanguageFile = 'web/dbsdns/lib/lang/en_dbsdns_record.lng';
}

$app->load_language_file($recordLanguageFile);
$app->uses('tpl,tform,tform_actions,validate_dns');
$app->load('tform_actions');
require_once __DIR__ . '/lib/classes/DbsRecordPage.inc.php';
$formProfile = DbsRecordCapabilities::formProfile($recordType);

if($formProfile === null) {
    http_response_code(403);
    $app->error($app->lng('error_no_view_permission'));
    exit;
}

$page = new DbsRecordPage($recordType, array(
    'access_denied' => $app->lng('dbsdns_record_access_denied_txt'),
    'invalid_record' => $app->lng('dbsdns_record_invalid_txt'),
    'create_success' => $app->lng('dbsdns_record_create_success_txt'),
    'create_error' => $app->lng('dbsdns_record_create_error_txt'),
    'create_verification' => $app->lng('dbsdns_record_create_verification_txt'),
    'create_duplicate' => $app->lng('dbsdns_record_create_duplicate_txt'),
    'edit_success' => $app->lng('dbsdns_record_edit_success_txt'),
    'edit_unchanged' => $app->lng('dbsdns_record_edit_unchanged_txt'),
    'edit_stale' => $app->lng('dbsdns_record_edit_stale_txt'),
    'edit_rollback' => $app->lng('dbsdns_record_edit_rollback_txt'),
    'edit_rollback_failed' => $app->lng('dbsdns_record_edit_rollback_failed_txt'),
    'edit_error' => $app->lng('dbsdns_record_edit_error_txt'),
    'edit_verification' => $app->lng('dbsdns_record_edit_verification_txt'),
    'data_label' => $app->lng($formProfile['data_label']),
    'target_label' => $app->lng($formProfile['data_label']),
    'name_hint' => $app->lng($formProfile['name_hint']),
    'data_hint' => $formProfile['data_hint'] === '' ? '' : $app->lng($formProfile['data_hint']),
    'target_hint' => $formProfile['data_hint'] === '' ? '' : $app->lng($formProfile['data_hint']),
    'active_hint' => $app->lng('active_immutable_hint_txt'),
    'loading' => $app->lng('loading_txt'),
    'validation_record' => $app->lng('record_error_invalid'),
    'validation_type' => $app->lng('record_error_invalid'),
    'validation_name' => $app->lng('name_error_invalid'),
    'validation_cname_name' => $app->lng('cname_name_error_invalid'),
    'validation_srv_name' => $app->lng('srv_name_error_invalid'),
    'validation_ipv4' => $app->lng('ipv4_error_invalid'),
    'validation_ipv6' => $app->lng('ipv6_error_invalid'),
    'validation_cname_target' => $app->lng('cname_target_error_invalid'),
    'validation_mx_target' => $app->lng('mx_target_error_invalid'),
    'validation_ns_target' => $app->lng('ns_target_error_invalid'),
    'validation_srv_target' => $app->lng('srv_target_error_invalid'),
    'validation_txt' => $app->lng('txt_error_invalid'),
    'validation_priority' => $app->lng('priority_error_invalid'),
    'validation_weight' => $app->lng('weight_error_invalid'),
    'validation_port' => $app->lng('port_error_invalid'),
    'validation_ttl' => $app->lng('ttl_error_invalid'),
    'validation_active' => $app->lng('active_error_invalid')
), $recordToken);
$page->onLoad();
