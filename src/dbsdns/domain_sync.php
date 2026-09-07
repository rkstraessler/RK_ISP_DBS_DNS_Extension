<?php

$dbsdnsModuleRoot = __DIR__;
$dbsdnsInterfaceRoot = dirname($dbsdnsModuleRoot, 2);

require_once $dbsdnsModuleRoot . '/lib/classes/DbsRuntime.inc.php';
DbsRuntime::installRequestGuard('Synchronisieren der DNS-Zonen');
require_once $dbsdnsInterfaceRoot . '/lib/config.inc.php';
require_once $dbsdnsInterfaceRoot . '/lib/app.inc.php';

$app->auth->check_module_permissions('dbsdns');

if(!$app->auth->is_admin()) {
    http_response_code(403);
    die('Access denied.');
}

require_once $dbsdnsModuleRoot . '/lib/classes/DbsClient.inc.php';
require_once $dbsdnsModuleRoot . '/lib/classes/DomainMatcher.inc.php';
require_once $dbsdnsModuleRoot . '/lib/classes/ZoneCache.inc.php';
require_once $dbsdnsModuleRoot . '/lib/classes/ZoneInventorySync.inc.php';

$language = $app->functions->check_language($_SESSION['s']['language']);
$languageFile = DbsRuntime::languagePath($language, 'dbsdns');

if(!is_file($languageFile)) {
    $languageFile = DbsRuntime::languagePath('en', 'dbsdns');
}

include $languageFile;

$syncSuccessful = false;
$syncError = '';
$syncResult = array(
    'total' => 0,
    'created' => 0,
    'updated' => 0,
    'missing' => 0,
    'synced_at' => ''
);
$summary = array(
    'total_count' => 0,
    'present_count' => 0,
    'missing_count' => 0,
    'last_synced_at' => ''
);

try {
    $domainMatcher = new DomainMatcher(array($app->functions, 'idn_encode'));
    $zoneCache = new ZoneCache($app->db, $domainMatcher);
    $summary = $zoneCache->getSummary();

    if(isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $app->auth->csrf_token_check();

        if(!isset($_POST['domain_sync']) || $_POST['domain_sync'] !== '1') {
            throw new ZoneInventorySyncException(
                'Ungültige Aktion.',
                ZoneInventorySyncException::ERROR_INVALID_INVENTORY
            );
        }

        $dbsClient = new DbsClient();
        $inventory = $dbsClient->getDomainInventory();
        $inventorySync = new ZoneInventorySync($app->db, $domainMatcher);
        $syncResult = $inventorySync->synchronize(
            $inventory['domains'],
            null,
            $inventory['empty_inventory_confirmed']
        );
        $summary = $zoneCache->getSummary();
        $syncSuccessful = true;
    }
} catch (DbsClientException $exception) {
    $errorMessages = array(
        DbsClient::ERROR_CONFIGURATION => $wb['domain_sync_configuration_error_txt'],
        DbsClient::ERROR_SOAP_UNAVAILABLE => $wb['domain_sync_soap_unavailable_txt'],
        DbsClient::ERROR_UNREACHABLE => $wb['domain_sync_unreachable_txt'],
        DbsClient::ERROR_REQUEST_FAILED => $wb['domain_sync_request_failed_txt'],
        DbsClient::ERROR_INVALID_RESPONSE => $wb['domain_sync_invalid_response_txt'],
        DbsClient::ERROR_REQUEST_REJECTED => $wb['domain_sync_rejected_txt'],
        DbsClient::ERROR_INVALID_INPUT => $wb['domain_sync_error_txt']
    );
    $errorCode = $exception->getCode();
    $syncError = isset($errorMessages[$errorCode])
        ? $errorMessages[$errorCode]
        : $wb['domain_sync_error_txt'];
} catch (ZoneCacheException $exception) {
    $syncError = $wb['domain_sync_schema_error_txt'];
} catch (ZoneInventorySyncException $exception) {
    $syncError = $wb['domain_sync_error_txt'];
} catch (Throwable $exception) {
    DbsRuntime::logUnexpected($app, $exception, 'Synchronisieren der DNS-Zonen');
    $syncError = $wb['domain_sync_error_txt'];
}

$app->uses('tpl');
$app->tpl->newTemplate('form.tpl.htm');
$app->tpl->setInclude('content_tpl', DbsRuntime::templatePath('domain_sync.htm'));
$app->tpl->setVar($wb);
$app->tpl->setVar('sync_successful', $syncSuccessful);
$app->tpl->setVar('sync_error', $syncError, true);
$app->tpl->setVar('sync_total', $syncResult['total']);
$app->tpl->setVar('sync_created', $syncResult['created']);
$app->tpl->setVar('sync_updated', $syncResult['updated']);
$app->tpl->setVar('sync_missing', $syncResult['missing']);
$app->tpl->setVar('inventory_total', $summary['total_count']);
$app->tpl->setVar('inventory_present', $summary['present_count']);
$app->tpl->setVar('inventory_missing', $summary['missing_count']);
$app->tpl->setVar(
    'inventory_last_synced',
    $summary['last_synced_at'] !== ''
        ? $app->functions->htmlentities($summary['last_synced_at'])
        : $wb['domain_sync_never_txt']
);

$csrfToken = $app->auth->csrf_token_get('dbsdns_domain_sync');
$app->tpl->setVar('_csrf_id', $csrfToken['csrf_id']);
$app->tpl->setVar('_csrf_key', $csrfToken['csrf_key']);

$app->tpl_defaults();
$app->tpl->pparse();
