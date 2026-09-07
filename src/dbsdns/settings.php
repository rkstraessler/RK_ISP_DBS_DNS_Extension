<?php

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';

$app->auth->check_module_permissions('dbsdns');

if(!$app->auth->is_admin()) {
    http_response_code(403);
    die('Access denied.');
}

require_once 'lib/classes/DbsClient.inc.php';
require_once 'lib/classes/DbsModuleAccess.inc.php';
require_once 'lib/classes/DbsSettingsService.inc.php';

$language = $app->functions->check_language($_SESSION['s']['language']);
$languageFile = 'lib/lang/' . $language . '_dbsdns.lng';

if(!is_file($languageFile)) {
    $languageFile = 'lib/lang/en_dbsdns.lng';
}

include $languageFile;

$settingsError = '';
$settingsSuccessful = false;
$connectionTestSuccessful = false;
$connectionTestError = '';
$postedWsdlUrl = null;
$postedUsername = null;
$formState = array(
    'configured' => false,
    'source' => '',
    'wsdl_url' => DbsConfiguration::DEFAULT_WSDL_URL,
    'username' => '',
    'password_configured' => false,
    'configuration_invalid' => false,
    'connection_status' => DbsSettingsRepository::STATUS_UNTESTED,
    'last_tested_at' => ''
);

try {
    $settingsService = new DbsSettingsService($app->db);
    $synchronizeModuleAccess = function() use ($app) {
        try {
            (new DbsModuleAccess($app->db))->synchronizeEligibleUsers();
        } catch (Throwable $exception) {
            $app->log('DBS DNS module permission synchronization failed.', LOGLEVEL_ERROR);
        }
    };
    $isPost = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';

    if($isPost) {
        $app->auth->csrf_token_check();
        $action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '';
        $postedWsdlUrl = isset($_POST['wsdl_url']) && is_string($_POST['wsdl_url'])
            ? $_POST['wsdl_url']
            : '';
        $postedUsername = isset($_POST['username']) && is_string($_POST['username'])
            ? $_POST['username']
            : '';
        $postedPassword = isset($_POST['password']) && is_string($_POST['password'])
            ? $_POST['password']
            : '';

        if($action === 'save') {
            try {
                $settingsService->save($postedWsdlUrl, $postedUsername, $postedPassword);
                $synchronizeModuleAccess();
                $settingsSuccessful = true;
                $postedWsdlUrl = null;
                $postedUsername = null;
            } catch (DbsCredentialException $exception) {
                $settingsError = $wb['settings_secure_storage_error_txt'];
            } catch (RuntimeException $exception) {
                $settingsError = $exception->getCode() === DbsSettingsService::ERROR_PASSWORD_REQUIRED
                    ? $wb['settings_password_required_txt']
                    : $wb['settings_invalid_txt'];
            } catch (Throwable $exception) {
                $settingsError = $wb['settings_save_error_txt'];
            }
        } elseif($action === 'test') {
            $persistSource = '';

            try {
                $testContext = $settingsService->buildTestConfiguration(
                    $postedWsdlUrl,
                    $postedUsername,
                    $postedPassword
                );
                $persistSource = $testContext['persist_source'];
                $dbsClient = new DbsClient($testContext['configuration']);
                $dbsClient->testConnection();
                $connectionTestSuccessful = true;

                try {
                    $settingsService->saveTestResult(true, $persistSource);
                    $synchronizeModuleAccess();
                } catch (Throwable $exception) {
                }
            } catch (DbsClientException $exception) {
                $errorMessages = array(
                    DbsClient::ERROR_CONFIGURATION => $wb['connection_test_configuration_error_txt'],
                    DbsClient::ERROR_SOAP_UNAVAILABLE => $wb['connection_test_soap_unavailable_txt'],
                    DbsClient::ERROR_UNREACHABLE => $wb['connection_test_unreachable_txt'],
                    DbsClient::ERROR_REQUEST_FAILED => $wb['connection_test_request_failed_txt'],
                    DbsClient::ERROR_INVALID_RESPONSE => $wb['connection_test_invalid_response_txt'],
                    DbsClient::ERROR_REQUEST_REJECTED => $wb['connection_test_rejected_txt']
                );
                $connectionTestError = isset($errorMessages[$exception->getCode()])
                    ? $errorMessages[$exception->getCode()]
                    : $wb['connection_test_error_txt'];

                try {
                    $settingsService->saveTestResult(false, $persistSource);
                    $synchronizeModuleAccess();
                } catch (Throwable $statusException) {
                }
            } catch (DbsCredentialException $exception) {
                $connectionTestError = $wb['connection_test_configuration_error_txt'];
            } catch (RuntimeException $exception) {
                $connectionTestError = $exception->getCode() === DbsSettingsService::ERROR_PASSWORD_REQUIRED
                    ? $wb['settings_password_required_txt']
                    : $wb['settings_invalid_txt'];
            } catch (Throwable $exception) {
                $connectionTestError = $wb['connection_test_error_txt'];
            }
        } else {
            $settingsError = $wb['settings_invalid_txt'];
        }
    }

    $formState = $settingsService->getFormState();
} catch (DbsCredentialException $exception) {
    $settingsError = $wb['settings_storage_error_txt'];
} catch (Throwable $exception) {
    $settingsError = $wb['settings_storage_error_txt'];
}

if($postedWsdlUrl !== null) {
    $formState['wsdl_url'] = $postedWsdlUrl;
}

if($postedUsername !== null) {
    $formState['username'] = $postedUsername;
}

$sourceLabels = array(
    DbsConfiguration::SOURCE_WEB => $wb['settings_source_web_txt'],
    DbsConfiguration::SOURCE_ENVIRONMENT => $wb['settings_source_environment_txt'],
    '' => $wb['settings_source_none_txt']
);
$statusLabels = array(
    DbsSettingsRepository::STATUS_SUCCESS => $wb['settings_status_success_txt'],
    DbsSettingsRepository::STATUS_FAILURE => $wb['settings_status_failure_txt'],
    DbsSettingsService::STATUS_INVALID => $wb['settings_status_invalid_txt'],
    DbsSettingsRepository::STATUS_UNTESTED => $formState['configured']
        ? $wb['settings_status_untested_txt']
        : $wb['settings_status_not_configured_txt']
);
$statusClasses = array(
    DbsSettingsRepository::STATUS_SUCCESS => 'alert alert-success',
    DbsSettingsRepository::STATUS_FAILURE => 'alert alert-warning',
    DbsSettingsService::STATUS_INVALID => 'alert alert-danger',
    DbsSettingsRepository::STATUS_UNTESTED => 'alert alert-info'
);

$app->uses('tpl');
$app->tpl->newTemplate('form.tpl.htm');
$app->tpl->setInclude('content_tpl', 'templates/settings.htm');
$app->tpl->setVar($wb);
$app->tpl->setVar('settings_error', $settingsError, true);
$app->tpl->setVar('settings_successful', $settingsSuccessful);
$app->tpl->setVar('connection_test_successful', $connectionTestSuccessful);
$app->tpl->setVar('connection_test_error', $connectionTestError, true);
$app->tpl->setVar('wsdl_url', $app->functions->htmlentities($formState['wsdl_url']));
$app->tpl->setVar('username', $app->functions->htmlentities($formState['username']));
$app->tpl->setVar('configuration_source', $sourceLabels[$formState['source']]);
$app->tpl->setVar('connection_status', $statusLabels[$formState['connection_status']]);
$app->tpl->setVar('connection_status_class', $statusClasses[$formState['connection_status']]);
$app->tpl->setVar(
    'password_placeholder',
    $formState['password_configured'] && empty($formState['configuration_invalid'])
        ? $wb['settings_password_preserved_txt']
        : $wb['settings_password_placeholder_txt']
);

$csrfToken = $app->auth->csrf_token_get('dbsdns_settings');
$app->tpl->setVar('_csrf_id', $csrfToken['csrf_id']);
$app->tpl->setVar('_csrf_key', $csrfToken['csrf_key']);

$app->tpl_defaults();
$app->tpl->pparse();
