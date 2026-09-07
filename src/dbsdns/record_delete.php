<?php

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once __DIR__ . '/lib/classes/DbsRecordService.inc.php';
require_once __DIR__ . '/lib/classes/DbsSessionWriteAccess.inc.php';
require_once __DIR__ . '/lib/classes/DbsZoneAccess.inc.php';

$app->auth->check_module_permissions('dbsdns');
$language = $app->functions->check_language($_SESSION['s']['language']);
$languageFile = 'web/dbsdns/lib/lang/' . $language . '_dbsdns.lng';

if(!is_file(ISPC_ROOT_PATH . '/' . $languageFile)) {
    $languageFile = 'web/dbsdns/lib/lang/en_dbsdns.lng';
}

$app->load_language_file($languageFile);

if(!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$app->auth->csrf_token_check('POST');

$token = isset($_POST['id']) && is_string($_POST['id'])
    ? $_POST['id']
    : '';
$identity = new DbsRecordIdentity();

try {
    $verifiedIdentity = $identity->verify($token);
} catch (DbsRecordIdentityException $exception) {
    http_response_code(403);
    $app->error($app->lng('dbsdns_record_access_denied_txt'));
    exit;
}

$cacheId = (int)$verifiedIdentity['cache_id'];
$redirectToZone = function($messageType, $message) use ($cacheId) {
    $_SESSION[$messageType] = $message;
    session_write_close();
    header('Location: zone_view.php?id=' . $cacheId);
    exit;
};

if(!DbsSessionWriteAccess::mayWrite($app, $_SESSION['s']['user'])) {
    $redirectToZone('show_error_msg', $app->lng('client_you_are_locked'));
}

$matcher = new DomainMatcher(array($app->functions, 'idn_encode'));
$zoneAccess = new DbsZoneAccess($app->db, $matcher);

try {
    $context = $zoneAccess->createContextFromIspConfig($app->auth, $_SESSION['s']['user']);
} catch (DbsZoneAccessException $exception) {
    $app->error($app->lng('dbsdns_configuration_required_txt'));
    exit;
}

$service = new DbsRecordService(
    $zoneAccess,
    $identity,
    function() {
        return new DbsClient();
    }
);

try {
    $service->delete($context, $token);
} catch (DbsRecordException $exception) {
    if(
        $exception->getCode() === DbsRecordService::ERROR_ACCESS_DENIED
        || $exception->getCode() === DbsRecordService::ERROR_INVALID_ZONE
        || $exception->getCode() === DbsRecordService::ERROR_INVALID_IDENTIFIER
        || $exception->getCode() === DbsRecordService::ERROR_INVALID_RECORD
    ) {
        http_response_code(403);
        $app->error($app->lng('dbsdns_record_access_denied_txt'));
        exit;
    }

    if(
        $exception->getCode() === DbsRecordService::ERROR_RECORD_NOT_FOUND
        || $exception->getCode() === DbsRecordService::ERROR_STALE_RECORD
    ) {
        $redirectToZone('show_error_msg', $app->lng('dbsdns_record_delete_missing_txt'));
    }

    $app->log(
        'DBS DNS record delete verification failed (code ' . (int)$exception->getCode() . ').',
        LOGLEVEL_ERROR
    );
    $redirectToZone('show_error_msg', $app->lng('dbsdns_record_delete_error_txt'));
} catch (DbsClientException $exception) {
    $app->log('DBS DNS record delete failed (code ' . (int)$exception->getCode() . ').', LOGLEVEL_ERROR);
    $redirectToZone('show_error_msg', $app->lng('dbsdns_record_delete_error_txt'));
} catch (Throwable $exception) {
    $app->log('DBS DNS record delete failed (' . get_class($exception) . ').', LOGLEVEL_ERROR);
    $redirectToZone('show_error_msg', $app->lng('dbsdns_record_delete_error_txt'));
}

$redirectToZone('show_info_msg', $app->lng('dbsdns_record_delete_success_txt'));
