<?php

$dbsdnsModuleRoot = __DIR__;
$dbsdnsInterfaceRoot = dirname($dbsdnsModuleRoot, 2);

require_once $dbsdnsModuleRoot . '/lib/classes/DbsRuntime.inc.php';
DbsRuntime::installRequestGuard('Laden der Zuordnungsliste');
require_once $dbsdnsInterfaceRoot . '/lib/config.inc.php';
require_once $dbsdnsInterfaceRoot . '/lib/app.inc.php';

$app->auth->check_module_permissions('dbsdns');

if(!$app->auth->is_admin()) {
    http_response_code(403);
    die('Access denied.');
}

require_once $dbsdnsModuleRoot . '/lib/classes/DomainMatcher.inc.php';
require_once $dbsdnsModuleRoot . '/lib/classes/DomainAssignment.inc.php';
require_once $dbsdnsModuleRoot . '/lib/classes/DomainAccess.inc.php';
require_once $dbsdnsModuleRoot . '/lib/classes/DbsModuleAccess.inc.php';
require_once $dbsdnsModuleRoot . '/lib/classes/DbsAssignmentListView.inc.php';
require_once $dbsdnsModuleRoot . '/lib/classes/ZoneCache.inc.php';
require_once $dbsdnsModuleRoot . '/lib/classes/DbsSettingsService.inc.php';

$language = $app->functions->check_language($_SESSION['s']['language']);
$languageFile = DbsRuntime::languagePath($language, 'dbsdns');

if(!is_file($languageFile)) {
    $languageFile = DbsRuntime::languagePath('en', 'dbsdns');
}

include $languageFile;

$allowedFilters = array(
    'all',
    'saved',
    DomainMatcher::STATUS_ASSIGNED,
    DomainMatcher::STATUS_CONFLICT,
    DomainMatcher::STATUS_UNASSIGNED
);
$statusFilter = isset($_REQUEST['status']) && is_string($_REQUEST['status'])
    ? $_REQUEST['status']
    : 'all';

if(!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'all';
}

$page = isset($_REQUEST['page']) ? filter_var(
    $_REQUEST['page'],
    FILTER_VALIDATE_INT,
    array('options' => array('min_range' => 1, 'max_range' => 1000000))
) : 1;

if($page === false) {
    $page = 1;
}

$isPost = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
$requestedAction = isset($_REQUEST['action']) && is_string($_REQUEST['action'])
    ? $_REQUEST['action']
    : '';

if($isPost) {
    $app->auth->csrf_token_check();
}

$pageSizes = array(10, 25, 50, 100);
$pageSize = isset($_REQUEST['page_size']) && is_scalar($_REQUEST['page_size'])
    ? (int)$_REQUEST['page_size']
    : 25;

if(!in_array($pageSize, $pageSizes, true)) {
    $pageSize = 25;
}

$searchTerm = isset($_REQUEST['search_term']) && is_scalar($_REQUEST['search_term'])
    ? trim((string)$_REQUEST['search_term'])
    : '';

if(strlen($searchTerm) > 253) {
    $searchTerm = substr($searchTerm, 0, 253);
}

$allowedSortFields = array('domain', 'status', 'client', 'reseller');
$sortField = isset($_REQUEST['sort']) && is_scalar($_REQUEST['sort'])
    ? strtolower((string)$_REQUEST['sort'])
    : 'domain';
$sortDirection = isset($_REQUEST['dir']) && is_scalar($_REQUEST['dir'])
    ? strtolower((string)$_REQUEST['dir'])
    : 'asc';

if(!in_array($sortField, $allowedSortFields, true)) {
    $sortField = 'domain';
}

if(!in_array($sortDirection, array('asc', 'desc'), true)) {
    $sortDirection = 'asc';
}
$assignmentError = '';
$assignmentSuccess = '';
$assignmentWarning = '';
$records = array();
$matches = array();
$filteredMatches = array();
$bulkEligibleCount = 0;
$savedCount = 0;
$statistics = array(
    'total' => 0,
    DomainMatcher::STATUS_ASSIGNED => 0,
    DomainMatcher::STATUS_CONFLICT => 0,
    DomainMatcher::STATUS_UNASSIGNED => 0
);
$inventorySummary = array(
    'total_count' => 0,
    'present_count' => 0,
    'missing_count' => 0,
    'last_synced_at' => ''
);

try {
    $domainMatcher = new DomainMatcher(array($app->functions, 'idn_encode'));
    $domainAccess = new DomainAccess($app->db, $domainMatcher);
    $accessContext = $domainAccess->createContextFromIspConfig($app->auth, $_SESSION['s']['user']);

    if(!$domainAccess->canManageAssignments($accessContext)) {
        http_response_code(403);
        die('Access denied.');
    }

    if($isPost && !in_array($requestedAction, array('bulk_assign', 'filter'), true)) {
        throw new DomainAssignmentException(
            'Ungültige Aktion.',
            DomainAssignmentException::ERROR_INVALID_DOMAIN
        );
    }

    $zoneCache = new ZoneCache($app->db, $domainMatcher);
    $inventorySummary = $zoneCache->getSummary();
    $dbsDomains = $zoneCache->getPresentDomains();
    $matches = $domainMatcher->matchDbsDomains($app->db, $dbsDomains);
    $domainAssignment = new DomainAssignment($app->db, $domainMatcher);

    if($isPost && $requestedAction === 'bulk_assign') {
        $adminUserId = isset($_SESSION['s']['user']['userid'])
            ? (int)$_SESSION['s']['user']['userid']
            : 0;
        $bulkResult = $domainAssignment->assignUniqueMatches($matches, $adminUserId);
        $assignmentSuccess = sprintf(
            $wb['assignment_bulk_result_txt'],
            $bulkResult['created'],
            $bulkResult['already_assigned'],
            $bulkResult['conflicts'],
            $bulkResult['skipped']
        );

        if($bulkResult['failed'] > 0) {
            $assignmentWarning = sprintf(
                $wb['assignment_bulk_failed_txt'],
                $bulkResult['failed']
            );
        }

        try {
            $moduleAccess = new DbsModuleAccess($app->db);
            $moduleAccess->synchronizeEligibleUsers();
        } catch (Throwable $exception) {
            DbsRuntime::logUnexpected($app, $exception, 'Synchronisieren der Modulberechtigungen');
        }
    }

    $matches = $domainAssignment->annotateMatches($matches);

    foreach($matches as $match) {
        $effectiveStatus = $match['effective_status'];
        $statistics['total']++;
        $statistics[$effectiveStatus]++;

        if($match['native_assignment'] !== null) {
            $savedCount++;
        }

        if($match['bulk_eligible']) {
            $bulkEligibleCount++;
        }

    }
} catch (ZoneCacheException $exception) {
    $assignmentError = $wb['domain_sync_schema_error_txt'];
} catch (DomainMatcherException $exception) {
    $assignmentError = $wb['assignment_error_txt'];
} catch (DomainAssignmentException $exception) {
    $assignmentError = $wb['assignment_write_error_txt'];
} catch (Throwable $exception) {
    DbsRuntime::logUnexpected($app, $exception, 'Laden der Zuordnungsliste');
    $assignmentError = $wb['assignment_error_txt'];
}

$connectionOverviewStatus = DbsSettingsRepository::STATUS_UNTESTED;
$connectionOverviewConfigured = false;

try {
    $settingsFormState = (new DbsSettingsService($app->db))->getFormState();
    $connectionOverviewStatus = $settingsFormState['connection_status'];
    $connectionOverviewConfigured = $settingsFormState['configured'];
} catch (Throwable $exception) {
    DbsRuntime::logUnexpected($app, $exception, 'Laden des Verbindungsstatus');
}

$connectionOverviewLabels = array(
    DbsSettingsRepository::STATUS_SUCCESS => $wb['settings_status_success_txt'],
    DbsSettingsRepository::STATUS_FAILURE => $wb['settings_status_failure_txt'],
    DbsSettingsService::STATUS_INVALID => $wb['settings_status_invalid_txt'],
    DbsSettingsRepository::STATUS_UNTESTED => $connectionOverviewConfigured
        ? $wb['settings_status_untested_txt']
        : $wb['settings_status_not_configured_txt']
);
$connectionOverviewClasses = array(
    DbsSettingsRepository::STATUS_SUCCESS => 'label label-success',
    DbsSettingsRepository::STATUS_FAILURE => 'label label-warning',
    DbsSettingsService::STATUS_INVALID => 'label label-danger',
    DbsSettingsRepository::STATUS_UNTESTED => 'label label-default'
);

$listView = new DbsAssignmentListView();
$listState = $listView->build(
    $matches,
    $statusFilter,
    $searchTerm,
    $sortField,
    $sortDirection,
    $page,
    $pageSize
);
$filteredMatches = $listState['filtered'];
$visibleMatches = $listState['visible'];
$filteredCount = $listState['filtered_count'];
$totalPages = $listState['total_pages'];
$page = $listState['page'];
$statusLabels = array(
    DomainMatcher::STATUS_ASSIGNED => $wb['assignment_status_assigned_txt'],
    DomainMatcher::STATUS_CONFLICT => $wb['assignment_status_conflict_txt'],
    DomainMatcher::STATUS_UNASSIGNED => $wb['assignment_status_unassigned_txt']
);
$statusClasses = array(
    DomainMatcher::STATUS_ASSIGNED => 'label label-success',
    DomainMatcher::STATUS_CONFLICT => 'label label-danger',
    DomainMatcher::STATUS_UNASSIGNED => 'label label-default'
);
$nativeStatusLabels = array(
    DomainAssignment::NATIVE_MISSING => $wb['assignment_native_missing_txt'],
    DomainAssignment::NATIVE_SAME => $wb['assignment_native_same_txt'],
    DomainAssignment::NATIVE_EXISTING => $wb['assignment_native_existing_txt'],
    DomainAssignment::NATIVE_CONFLICT => $wb['assignment_native_conflict_txt']
);
$nativeStatusClasses = array(
    DomainAssignment::NATIVE_MISSING => 'label label-default',
    DomainAssignment::NATIVE_SAME => 'label label-success',
    DomainAssignment::NATIVE_EXISTING => 'label label-success',
    DomainAssignment::NATIVE_CONFLICT => 'label label-danger'
);
$sourceLabels = array(
    DomainMatcher::SOURCE_WEB => $wb['assignment_source_web_txt'],
    DomainMatcher::SOURCE_MAIL => $wb['assignment_source_mail_txt'],
    DomainMatcher::SOURCE_DNS => $wb['assignment_source_dns_txt']
);

foreach($visibleMatches as $match) {
    $sources = array();
    $effectiveStatus = $match['effective_status'];

    foreach($match['sources'] as $source) {
        if(isset($sourceLabels[$source])) {
            $sources[] = $sourceLabels[$source];
        }
    }

    $nativeClientLabel = '-';

    if(
        $match['native_assignment'] !== null &&
        isset($match['native_assignment']['client_label']) &&
        $match['native_assignment']['client_label'] !== ''
    ) {
        $nativeClientLabel = $match['native_assignment']['client_label'];
    }

    $record = $app->functions->htmlentities(array(
        'domain_name' => $match['domain_name'],
        'origin_query' => rawurlencode($match['origin']),
        'status_label' => $statusLabels[$effectiveStatus],
        'native_status_label' => $nativeStatusLabels[$match['native_status']],
        'client_label' => $match['status'] === DomainMatcher::STATUS_ASSIGNED
            ? $match['client_label']
            : '-',
        'native_client_label' => $nativeClientLabel,
        'reseller_label' => $match['status'] === DomainMatcher::STATUS_ASSIGNED && $match['reseller_label'] !== ''
            ? $match['reseller_label']
            : '-',
        'sources_label' => count($sources) > 0 ? implode(' + ', $sources) : '-'
    ));
    $record['status_class'] = $statusClasses[$effectiveStatus];
    $record['native_status_class'] = $nativeStatusClasses[$match['native_status']];
    $records[] = $record;
}

$filterClasses = array(
    'all' => 'btn btn-default formbutton-default',
    'saved' => 'btn btn-default formbutton-default',
    DomainMatcher::STATUS_ASSIGNED => 'btn btn-default formbutton-default',
    DomainMatcher::STATUS_CONFLICT => 'btn btn-default formbutton-default',
    DomainMatcher::STATUS_UNASSIGNED => 'btn btn-default formbutton-default'
);
$filterClasses[$statusFilter] = 'btn btn-primary';

$app->uses('tpl');
$app->tpl->newTemplate('form.tpl.htm');
$app->tpl->setInclude('content_tpl', DbsRuntime::templatePath('assignment_list.htm'));
$app->tpl->setVar($wb);
$app->tpl->setVar('assignment_error', $assignmentError, true);
$app->tpl->setVar('assignment_success', $assignmentSuccess, true);
$app->tpl->setVar('assignment_warning', $assignmentWarning, true);
$app->tpl->setVar('connection_overview_label', $connectionOverviewLabels[$connectionOverviewStatus]);
$app->tpl->setVar('connection_overview_class', $connectionOverviewClasses[$connectionOverviewStatus]);
$app->tpl->setVar('count_total', $statistics['total']);
$app->tpl->setVar('count_assigned', $statistics[DomainMatcher::STATUS_ASSIGNED]);
$app->tpl->setVar('count_conflict', $statistics[DomainMatcher::STATUS_CONFLICT]);
$app->tpl->setVar('count_unassigned', $statistics[DomainMatcher::STATUS_UNASSIGNED]);
$app->tpl->setVar('count_saved', $savedCount);
$app->tpl->setVar('inventory_total_count', $inventorySummary['total_count']);
$app->tpl->setVar('inventory_present_count', $inventorySummary['present_count']);
$app->tpl->setVar('inventory_missing_count', $inventorySummary['missing_count']);
$app->tpl->setVar(
    'inventory_last_synced_at',
    $inventorySummary['last_synced_at'] !== ''
        ? $app->functions->htmlentities($inventorySummary['last_synced_at'])
        : $wb['domain_sync_never_txt']
);
$app->tpl->setVar('bulk_eligible_count', $bulkEligibleCount);
$app->tpl->setVar('can_bulk_assign', $bulkEligibleCount > 0 && $assignmentError === '');
$app->tpl->setVar('filter_all_class', $filterClasses['all']);
$app->tpl->setVar('filter_saved_class', $filterClasses['saved']);
$app->tpl->setVar('filter_assigned_class', $filterClasses[DomainMatcher::STATUS_ASSIGNED]);
$app->tpl->setVar('filter_conflict_class', $filterClasses[DomainMatcher::STATUS_CONFLICT]);
$app->tpl->setVar('filter_unassigned_class', $filterClasses[DomainMatcher::STATUS_UNASSIGNED]);
$app->tpl->setVar('current_filter', $statusFilter, true);
$app->tpl->setVar('current_page', $page);
$app->tpl->setVar('previous_page', max(1, $page - 1));
$app->tpl->setVar('next_page', min($totalPages, $page + 1));
$app->tpl->setVar('has_previous', $page > 1);
$app->tpl->setVar('has_more', $page < $totalPages);
$app->tpl->setVar('show_pagination', $totalPages > 1);
$app->tpl->setVar('filtered_count', $filteredCount);
$app->tpl->setVar('search_term', $app->functions->htmlentities($searchTerm));
$app->tpl->setVar('page_size', $pageSize);
$app->tpl->setVar('page_size_10_selected', $pageSize === 10 ? ' selected="selected"' : '');
$app->tpl->setVar('page_size_25_selected', $pageSize === 25 ? ' selected="selected"' : '');
$app->tpl->setVar('page_size_50_selected', $pageSize === 50 ? ' selected="selected"' : '');
$app->tpl->setVar('page_size_100_selected', $pageSize === 100 ? ' selected="selected"' : '');

$listQuery = '&status=' . rawurlencode($statusFilter)
    . '&search_term=' . rawurlencode($searchTerm)
    . '&page_size=' . $pageSize
    . '&sort=' . rawurlencode($sortField)
    . '&dir=' . rawurlencode($sortDirection);
$app->tpl->setVar('list_query', $listQuery, true);

foreach($allowedSortFields as $allowedSortField) {
    $nextDirection = $sortField === $allowedSortField && $sortDirection === 'asc'
        ? 'desc'
        : 'asc';
    $sortLink = 'dbsdns/assignment_list.php?status=' . rawurlencode($statusFilter)
        . '&search_term=' . rawurlencode($searchTerm)
        . '&page_size=' . $pageSize
        . '&sort=' . rawurlencode($allowedSortField)
        . '&dir=' . $nextDirection;
    $app->tpl->setVar('sort_' . $allowedSortField . '_link', $sortLink, true);
}
$app->tpl->setLoop('records', $records);

$csrfToken = $app->auth->csrf_token_get('dbsdns_assignment_bulk');
$app->tpl->setVar('_csrf_id', $csrfToken['csrf_id']);
$app->tpl->setVar('_csrf_key', $csrfToken['csrf_key']);

$app->tpl_defaults();
$app->tpl->pparse();
