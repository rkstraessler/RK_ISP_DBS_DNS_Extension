<?php

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once __DIR__ . '/lib/classes/DbsZoneAccess.inc.php';
require_once __DIR__ . '/lib/classes/DbsZoneSettingsService.inc.php';
require_once __DIR__ . '/lib/classes/DbsSessionWriteAccess.inc.php';

$app->auth->check_module_permissions('dbsdns');
$language = $app->functions->check_language($_SESSION['s']['language']);
$languageFile = 'web/dbsdns/lib/lang/' . $language . '_dbsdns.lng';

if(!is_file(ISPC_ROOT_PATH . '/' . $languageFile)) {
    $languageFile = 'web/dbsdns/lib/lang/en_dbsdns.lng';
}

$app->load_language_file($languageFile);

if(!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $app->error($app->lng('dbsdns_zone_settings_post_only_txt'));
    exit;
}

$app->auth->csrf_token_check('POST');
$rawCacheId = isset($_POST['id']) && is_scalar($_POST['id'])
    ? (string)$_POST['id']
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

$redirectToZone = function($messageType, $message) use ($cacheId) {
    $_SESSION[$messageType] = $message;
    session_write_close();
    header('Location: zone_view.php?id=' . $cacheId);
    exit;
};

if(!DbsSessionWriteAccess::mayWrite($app, $_SESSION['s']['user'])) {
    $redirectToZone('show_error_msg', $app->lng('client_you_are_locked'));
}

$settings = array(
    'mbox' => isset($_POST['mbox']) && is_scalar($_POST['mbox']) ? (string)$_POST['mbox'] : '',
    'refresh' => isset($_POST['refresh']) && is_scalar($_POST['refresh']) ? (string)$_POST['refresh'] : '',
    'retry' => isset($_POST['retry']) && is_scalar($_POST['retry']) ? (string)$_POST['retry'] : '',
    'expire' => isset($_POST['expire']) && is_scalar($_POST['expire']) ? (string)$_POST['expire'] : '',
    'minimum_ttl' => isset($_POST['minimum_ttl']) && is_scalar($_POST['minimum_ttl']) ? (string)$_POST['minimum_ttl'] : '',
    'ttl' => isset($_POST['ttl']) && is_scalar($_POST['ttl']) ? (string)$_POST['ttl'] : ''
);
$settingsToken = isset($_POST['zone_settings_token']) && is_string($_POST['zone_settings_token'])
    ? $_POST['zone_settings_token']
    : '';

$matcher = new DomainMatcher(array($app->functions, 'idn_encode'));
$zoneAccess = new DbsZoneAccess($app->db, $matcher);

try {
    $context = $zoneAccess->createContextFromIspConfig($app->auth, $_SESSION['s']['user']);
} catch (DbsZoneAccessException $exception) {
    $app->error($app->lng('dbsdns_configuration_required_txt'));
    exit;
}

$settingsIdentity = new DbsZoneSettingsIdentity();
$service = new DbsZoneSettingsService($zoneAccess, $settingsIdentity, function() {
    return new DbsClient();
});

try {
    $result = $service->update($context, $cacheId, $settingsToken, $settings);
} catch (DbsZoneSettingsException $exception) {
    if(
        $exception->getCode() === DbsZoneSettingsService::ERROR_ACCESS_DENIED
        || $exception->getCode() === DbsZoneSettingsService::ERROR_INVALID_ZONE
        || $exception->getCode() === DbsZoneSettingsService::ERROR_INVALID_IDENTIFIER
    ) {
        http_response_code(403);
        $app->error($app->lng('error_no_view_permission'));
        exit;
    }

    if($exception->getCode() === DbsZoneSettingsService::ERROR_STALE_SETTINGS) {
        $redirectToZone('show_error_msg', $app->lng('dbsdns_zone_settings_stale_txt'));
    }

    $app->log(
        'DBS DNS zone settings update failed (code ' . (int)$exception->getCode() . ').',
        LOGLEVEL_ERROR
    );
    $redirectToZone('show_error_msg', $app->lng('dbsdns_zone_settings_invalid_txt'));
} catch (DbsClientException $exception) {
    $app->log(
        'DBS DNS zone settings provider request failed (code ' . (int)$exception->getCode() . ').',
        LOGLEVEL_ERROR
    );
    $redirectToZone('show_error_msg', $app->lng('dbsdns_zone_settings_error_txt'));
} catch (Throwable $exception) {
    $app->log(
        'DBS DNS zone settings update failed (' . get_class($exception) . ').',
        LOGLEVEL_ERROR
    );
    $redirectToZone('show_error_msg', $app->lng('dbsdns_zone_settings_error_txt'));
}

$message = !empty($result['changed'])
    ? $app->lng('dbsdns_zone_settings_success_txt')
    : $app->lng('dbsdns_zone_settings_unchanged_txt');
$redirectToZone('show_info_msg', $message);
