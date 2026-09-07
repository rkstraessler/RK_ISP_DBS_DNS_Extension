<?php

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once __DIR__ . '/lib/classes/DbsClient.inc.php';
require_once __DIR__ . '/lib/classes/DbsRecordListRenderer.inc.php';
require_once __DIR__ . '/lib/classes/DbsZoneAccess.inc.php';
require_once __DIR__ . '/lib/classes/DbsZoneSettingsIdentity.inc.php';

$app->auth->check_module_permissions('dbsdns');
$app->uses('tpl');
$language = $app->functions->check_language($_SESSION['s']['language']);
$languageFile = 'web/dbsdns/lib/lang/' . $language . '_dbsdns.lng';

if(!is_file(ISPC_ROOT_PATH . '/' . $languageFile)) {
    $languageFile = 'web/dbsdns/lib/lang/en_dbsdns.lng';
}

$app->load_language_file($languageFile);
$wb = array();
include ISPC_ROOT_PATH . '/' . $languageFile;

if(isset($wb['dbsdns_readonly_notice_txt']) && is_scalar($wb['dbsdns_readonly_notice_txt'])) {
    $wb['dbsdns_readonly_notice_txt'] = trim((string)$wb['dbsdns_readonly_notice_txt']);
}

$rawCacheId = isset($_REQUEST['id']) && is_scalar($_REQUEST['id'])
    ? (string)$_REQUEST['id']
    : '';

if(preg_match('/\A[1-9][0-9]*\z/', $rawCacheId) !== 1) {
    http_response_code(403);
    $app->error($app->lng('error_no_view_permission'));
    exit;
}

$cacheId = (int)$rawCacheId;

if($cacheId <= 0 || (string)$cacheId !== $rawCacheId) {
    http_response_code(403);
    $app->error($app->lng('error_no_view_permission'));
    exit;
}

try {
    $matcher = new DomainMatcher(array($app->functions, 'idn_encode'));
    $zoneAccess = new DbsZoneAccess($app->db, $matcher);
    $context = $zoneAccess->createContextFromIspConfig($app->auth, $_SESSION['s']['user']);
    $cachedZone = $zoneAccess->getAccessibleZoneByCacheId($context, $cacheId);

    if($cachedZone === null) {
        http_response_code(403);
        $app->error($app->lng('error_no_view_permission'));
        exit;
    }

    $client = new DbsClient();
    $zone = $client->getZoneInfo($cachedZone['normalized_domain']);
    $zoneSettingsIdentity = new DbsZoneSettingsIdentity();
    $zoneSettingsToken = $zoneSettingsIdentity->sign($cacheId, $zone['origin'], array(
        'mbox' => $zone['mbox'],
        'refresh' => $zone['refresh'],
        'retry' => $zone['retry'],
        'expire' => $zone['expire'],
        'minimum_ttl' => $zone['minimum_ttl'],
        'ttl' => $zone['ttl']
    ));
} catch (DbsZoneAccessException $exception) {
    $app->error($app->lng('dbsdns_configuration_required_txt'));
    exit;
} catch (DbsClientException $exception) {
    $app->log('DBS DNS zone view failed (code ' . (int)$exception->getCode() . ').', LOGLEVEL_ERROR);
    $app->error($app->lng('dbsdns_zone_load_error_txt'));
    exit;
} catch (Throwable $exception) {
    $app->log('DBS DNS zone view failed (' . get_class($exception) . ').', LOGLEVEL_ERROR);
    $app->error($app->lng('dbsdns_zone_load_error_txt'));
    exit;
}

$app->tpl->newTemplate('templates/zone_view.htm');
$app->tpl->setVar($wb);
$app->tpl->setVar('cache_id', $cacheId);
$app->tpl->setVar('origin', $app->functions->htmlentities($zone['origin']));
$app->tpl->setVar('primary', $app->functions->htmlentities($zone['primary']));
$app->tpl->setVar('mbox', $app->functions->htmlentities($zone['mbox']));
$app->tpl->setVar('refresh', $app->functions->htmlentities($zone['refresh']));
$app->tpl->setVar('retry', $app->functions->htmlentities($zone['retry']));
$app->tpl->setVar('expire', $app->functions->htmlentities($zone['expire']));
$app->tpl->setVar('minimum_ttl', $app->functions->htmlentities($zone['minimum_ttl']));
$app->tpl->setVar('ttl', $app->functions->htmlentities($zone['ttl']));
$app->tpl->setVar('server', 'DBS');
$app->tpl->setVar('btn_save_txt', $app->lng('btn_save_txt'));
$app->tpl->setVar('btn_cancel_txt', $app->lng('btn_cancel_txt'));
$zoneSettingsCsrf = $app->auth->csrf_token_get('dbsdns_zone_settings');
$app->tpl->setVar('_csrf_id', $zoneSettingsCsrf['csrf_id']);
$app->tpl->setVar('_csrf_key', $zoneSettingsCsrf['csrf_key']);
$app->tpl->setVar('zone_settings_token', $zoneSettingsToken, true);

$clientLabel = '';

if(
    isset($cachedZone['native_assignment']['client_label'])
    && is_string($cachedZone['native_assignment']['client_label'])
) {
    $clientLabel = $cachedZone['native_assignment']['client_label'];
}

$app->tpl->setVar('client', $app->functions->htmlentities($clientLabel));
$recordRenderer = new DbsRecordListRenderer();
$app->tpl->setVar(
    'records_html',
    $recordRenderer->render($zone['records'], $cacheId, $zone['origin'])
);

foreach(array('show_info_msg', 'show_warning_msg', 'show_error_msg') as $messageName) {
    if(!isset($_SESSION[$messageName])) {
        continue;
    }

    $message = is_scalar($_SESSION[$messageName])
        ? trim((string)$_SESSION[$messageName])
        : '';
    unset($_SESSION[$messageName]);

    if($message !== '') {
        $app->tpl->setVar($messageName, $app->functions->htmlentities($message));
    }
}

$app->tpl_defaults();
$app->tpl->pparse();
