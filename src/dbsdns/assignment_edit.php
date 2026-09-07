<?php

$dbsdnsModuleRoot = __DIR__;
$dbsdnsInterfaceRoot = dirname($dbsdnsModuleRoot, 2);

require_once $dbsdnsModuleRoot . '/lib/classes/DbsRuntime.inc.php';
DbsRuntime::installRequestGuard('Laden der Zuordnungsbearbeitung');
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
require_once $dbsdnsModuleRoot . '/lib/classes/ZoneCache.inc.php';

$language = $app->functions->check_language($_SESSION['s']['language']);
$languageFile = DbsRuntime::languagePath($language, 'dbsdns');

if(!is_file($languageFile)) {
    $languageFile = DbsRuntime::languagePath('en', 'dbsdns');
}

include $languageFile;

$isPost = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
$requestedAction = isset($_GET['action']) && is_string($_GET['action'])
    ? $_GET['action']
    : '';
$originInput = $isPost && isset($_POST['origin']) && is_string($_POST['origin'])
    ? $_POST['origin']
    : (isset($_GET['origin']) && is_string($_GET['origin']) ? $_GET['origin'] : '');

if($isPost) {
    $app->auth->csrf_token_check();
}

$assignmentError = '';
$assignmentSuccess = '';
$assignmentFound = false;
$canAssign = false;
$canConfirm = false;
$hasUnresolvedRelation = false;
$candidateRecords = array();
$clientGroupOptions = '';
$originDisplay = trim($originInput);
$detectedStatusLabel = '';
$detectedStatusClass = 'label label-default';
$detectedClientLabel = '-';
$detectedResellerLabel = '-';
$detectedSourcesLabel = '-';
$nativeStatusLabel = '-';
$nativeStatusClass = 'label label-default';
$nativeClientLabel = '-';
$nativeResellerLabel = '-';

try {
    $domainMatcher = new DomainMatcher(array($app->functions, 'idn_encode'));
    $domainAccess = new DomainAccess($app->db, $domainMatcher);
    $accessContext = $domainAccess->createContextFromIspConfig($app->auth, $_SESSION['s']['user']);

    if(!$domainAccess->canManageAssignments($accessContext)) {
        http_response_code(403);
        die('Access denied.');
    }

    $normalizedOrigin = $domainMatcher->normalizeDomain($originInput);

    if($normalizedOrigin === false) {
        throw new DomainAssignmentException(
            'Ungültige Domain.',
            DomainAssignmentException::ERROR_INVALID_DOMAIN
        );
    }

    $zoneCache = new ZoneCache($app->db, $domainMatcher);
    $cachedDomain = $zoneCache->getPresentDomainByName($normalizedOrigin);

    if($cachedDomain === null) {
        throw new DomainAssignmentException(
            'Die Domain gehört nicht zum aktuellen DBS-Domaininventar.',
            DomainAssignmentException::ERROR_INVALID_DOMAIN
        );
    }

    $matches = $domainMatcher->matchDbsDomains($app->db, array($cachedDomain));
    $selectedMatch = null;

    foreach($matches as $match) {
        if($match['normalized_domain'] === $normalizedOrigin) {
            $selectedMatch = $match;
            break;
        }
    }

    if($selectedMatch === null) {
        throw new DomainAssignmentException(
            'Die Domain gehört nicht zum DBS-Account.',
            DomainAssignmentException::ERROR_INVALID_DOMAIN
        );
    }

    $domainAssignment = new DomainAssignment($app->db, $domainMatcher);

    if($isPost) {
        $targetGroupId = 0;

        if($requestedAction === 'confirm_detected') {
            if(
                $selectedMatch['status'] !== DomainMatcher::STATUS_ASSIGNED ||
                (int)$selectedMatch['group_id'] <= 0
            ) {
                throw new DomainAssignmentException(
                    'Die Domain ist nicht eindeutig erkannt.',
                    DomainAssignmentException::ERROR_INVALID_CLIENT_GROUP
                );
            }

            $targetGroupId = (int)$selectedMatch['group_id'];
        } elseif($requestedAction === 'assign_selected') {
            $targetGroupId = isset($_POST['client_group_id'])
                ? filter_var(
                    $_POST['client_group_id'],
                    FILTER_VALIDATE_INT,
                    array('options' => array('min_range' => 1))
                )
                : false;

            if($targetGroupId === false) {
                throw new DomainAssignmentException(
                    'Ungültige ISPConfig-Kundengruppe.',
                    DomainAssignmentException::ERROR_INVALID_CLIENT_GROUP
                );
            }
        } else {
            throw new DomainAssignmentException(
                'Ungültige Aktion.',
                DomainAssignmentException::ERROR_INVALID_DOMAIN
            );
        }

        $adminUserId = isset($_SESSION['s']['user']['userid'])
            ? (int)$_SESSION['s']['user']['userid']
            : 0;
        $writeResult = $domainAssignment->assign(
            $normalizedOrigin,
            $targetGroupId,
            $adminUserId
        );

        if($writeResult['status'] === DomainAssignment::RESULT_CREATED) {
            $assignmentSuccess = $wb['assignment_created_txt'];
        } elseif($writeResult['status'] === DomainAssignment::RESULT_ALREADY_ASSIGNED) {
            $assignmentSuccess = $wb['assignment_already_assigned_txt'];
        } else {
            $assignmentError = $wb['assignment_existing_conflict_txt'];
        }

        if($writeResult['status'] !== DomainAssignment::RESULT_CONFLICT) {
            try {
                $moduleAccess = new DbsModuleAccess($app->db);
                $moduleAccess->synchronizeEligibleUsers();
            } catch (Throwable $exception) {
                DbsRuntime::logUnexpected($app, $exception, 'Synchronisieren der Modulberechtigungen');
            }
        }
    }

    $annotatedMatches = $domainAssignment->annotateMatches(array($selectedMatch));
    $selectedMatch = $annotatedMatches[0];
    $assignmentFound = true;
    $originDisplay = $selectedMatch['domain_name'];
    $canAssign = $selectedMatch['native_assignment'] === null;
    $canConfirm = $canAssign && $selectedMatch['status'] === DomainMatcher::STATUS_ASSIGNED;
    $hasUnresolvedRelation = !empty($selectedMatch['has_unresolved_relation']);

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
    $detectedSources = array();

    foreach($selectedMatch['sources'] as $source) {
        if(isset($sourceLabels[$source])) {
            $detectedSources[] = $sourceLabels[$source];
        }
    }

    $detectedStatusLabel = $statusLabels[$selectedMatch['status']];
    $detectedStatusClass = $statusClasses[$selectedMatch['status']];
    $detectedClientLabel = $selectedMatch['status'] === DomainMatcher::STATUS_ASSIGNED
        ? $selectedMatch['client_label']
        : '-';
    $detectedResellerLabel = $selectedMatch['status'] === DomainMatcher::STATUS_ASSIGNED
        && $selectedMatch['reseller_label'] !== ''
        ? $selectedMatch['reseller_label']
        : '-';
    $detectedSourcesLabel = count($detectedSources) > 0
        ? implode(' + ', $detectedSources)
        : '-';
    $nativeStatusLabel = $nativeStatusLabels[$selectedMatch['native_status']];
    $nativeStatusClass = $nativeStatusClasses[$selectedMatch['native_status']];

    if($selectedMatch['native_assignment'] !== null) {
        if($selectedMatch['native_assignment']['client_label'] !== '') {
            $nativeClientLabel = $selectedMatch['native_assignment']['client_label'];
        }

        if($selectedMatch['native_assignment']['reseller_label'] !== '') {
            $nativeResellerLabel = $selectedMatch['native_assignment']['reseller_label'];
        }
    }

    foreach($selectedMatch['candidates'] as $candidate) {
        $candidateSources = array();

        foreach($candidate['sources'] as $source) {
            if(isset($sourceLabels[$source])) {
                $candidateSources[] = $sourceLabels[$source];
            }
        }

        $candidateRecords[] = $app->functions->htmlentities(array(
            'group_label' => '#' . (int)$candidate['group_id']
                . ($candidate['group_name'] !== '' ? ' · ' . $candidate['group_name'] : ''),
            'client_label' => $candidate['client_label'],
            'reseller_label' => $candidate['reseller_label'] !== ''
                ? $candidate['reseller_label']
                : '-',
            'sources_label' => count($candidateSources) > 0
                ? implode(' + ', $candidateSources)
                : '-'
        ));
    }

    $selectedGroupId = isset($_POST['client_group_id'])
        ? (int)$_POST['client_group_id']
        : ($selectedMatch['status'] === DomainMatcher::STATUS_ASSIGNED
            ? (int)$selectedMatch['group_id']
            : 0);
    $clientGroups = $domainAssignment->getClientGroups();
    $escapedOptionTexts = $app->functions->htmlentities(array(
        'placeholder' => $wb['assignment_select_client_txt']
    ));
    $clientGroupOptions = '<option value="">' . $escapedOptionTexts['placeholder'] . '</option>';

    foreach($clientGroups as $clientGroup) {
        $escapedGroup = $app->functions->htmlentities(array(
            'label' => $clientGroup['client_label'],
            'group_name' => isset($clientGroup['group_name'])
                ? $clientGroup['group_name']
                : ''
        ));
        $selected = (int)$clientGroup['groupid'] === $selectedGroupId
            ? " selected='selected'"
            : '';
        $clientGroupOptions .= "<option value='" . (int)$clientGroup['groupid'] . "'" . $selected . ">"
            . $escapedGroup['label'] . ' [#' . (int)$clientGroup['groupid']
            . ($escapedGroup['group_name'] !== '' ? ' · ' . $escapedGroup['group_name'] : '')
            . ']</option>';
    }
} catch (ZoneCacheException $exception) {
    $assignmentError = $wb['domain_sync_schema_error_txt'];
} catch (DomainMatcherException $exception) {
    $assignmentError = $wb['assignment_error_txt'];
} catch (DomainAssignmentException $exception) {
    if($exception->getCode() === DomainAssignmentException::ERROR_INVALID_CLIENT_GROUP) {
        $assignmentError = $wb['assignment_client_invalid_txt'];
    } elseif($exception->getCode() === DomainAssignmentException::ERROR_INVALID_DOMAIN) {
        $assignmentError = $wb['assignment_domain_invalid_txt'];
    } else {
        $assignmentError = $wb['assignment_write_error_txt'];
    }
} catch (Throwable $exception) {
    DbsRuntime::logUnexpected($app, $exception, 'Laden der Zuordnungsbearbeitung');
    $assignmentError = $wb['assignment_error_txt'];
}

$safeValues = $app->functions->htmlentities(array(
    'origin_display' => $originDisplay,
    'origin_value' => isset($normalizedOrigin) && $normalizedOrigin !== false
        ? $normalizedOrigin
        : trim($originInput),
    'detected_status_label' => $detectedStatusLabel,
    'detected_client_label' => $detectedClientLabel,
    'detected_reseller_label' => $detectedResellerLabel,
    'detected_sources_label' => $detectedSourcesLabel,
    'native_status_label' => $nativeStatusLabel,
    'native_client_label' => $nativeClientLabel,
    'native_reseller_label' => $nativeResellerLabel
));

$app->uses('tpl');
$app->tpl->newTemplate('form.tpl.htm');
$app->tpl->setInclude('content_tpl', DbsRuntime::templatePath('assignment_edit.htm'));
$app->tpl->setVar($wb);
$app->tpl->setVar($safeValues);
$app->tpl->setVar('assignment_error', $assignmentError, true);
$app->tpl->setVar('assignment_success', $assignmentSuccess, true);
$app->tpl->setVar('assignment_found', $assignmentFound);
$app->tpl->setVar('can_assign', $canAssign);
$app->tpl->setVar('can_confirm', $canConfirm);
$app->tpl->setVar('has_unresolved_relation', $hasUnresolvedRelation);
$app->tpl->setVar('has_candidates', count($candidateRecords) > 0);
$app->tpl->setVar('detected_status_class', $detectedStatusClass);
$app->tpl->setVar('native_status_class', $nativeStatusClass);
$app->tpl->setVar('client_group_options', $clientGroupOptions);
$app->tpl->setLoop('candidates', $candidateRecords);

$csrfToken = $app->auth->csrf_token_get('dbsdns_assignment_edit');
$app->tpl->setVar('_csrf_id', $csrfToken['csrf_id']);
$app->tpl->setVar('_csrf_key', $csrfToken['csrf_key']);

$app->tpl_defaults();
$app->tpl->pparse();
