<?php

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once __DIR__ . '/lib/classes/DomainMatcher.inc.php';
require_once __DIR__ . '/lib/classes/DbsZoneAccess.inc.php';

$app->auth->check_module_permissions('dbsdns');
$app->uses('listform_actions');
require_once __DIR__ . '/lib/classes/DbsZoneListActions.inc.php';
$language = $app->functions->check_language($_SESSION['s']['language']);
$languageFile = 'web/dbsdns/lib/lang/' . $language . '_dbsdns.lng';

if(!is_file(ISPC_ROOT_PATH . '/' . $languageFile)) {
    $languageFile = 'web/dbsdns/lib/lang/en_dbsdns.lng';
}

$app->load_language_file($languageFile);

$list_def_file = 'list/zone.list.php';
$accessibleCacheIds = array();

try {
    $matcher = new DomainMatcher(array($app->functions, 'idn_encode'));
    $zoneAccess = new DbsZoneAccess($app->db, $matcher);
    $context = $zoneAccess->createContextFromIspConfig($app->auth, $_SESSION['s']['user']);

    foreach($zoneAccess->getAccessibleZones($context) as $zone) {
        $accessibleCacheIds[] = (int)$zone['cache_id'];
    }
} catch (DbsZoneAccessException $exception) {
    $app->error($app->lng('dbsdns_configuration_required_txt'));
    exit;
} catch (Throwable $exception) {
    $app->log('DBS DNS zone list failed (' . get_class($exception) . ').', LOGLEVEL_ERROR);
    $app->error($app->lng('dbsdns_zone_list_error_txt'));
    exit;
}

$listActions = new DbsZoneListActions($accessibleCacheIds);
$listActions->SQLOrderBy = 'ORDER BY dbsdns_zone_list.origin';
$listActions->onLoad();
