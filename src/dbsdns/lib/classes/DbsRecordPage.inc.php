<?php

require_once __DIR__ . '/DbsRecordService.inc.php';
require_once __DIR__ . '/DbsRecordNormalizer.inc.php';
require_once __DIR__ . '/DbsSessionWriteAccess.inc.php';
require_once __DIR__ . '/DbsZoneAccess.inc.php';

class DbsRecordPage extends tform_actions
{
    private $recordType;
    private $messages;
    private $cacheId;
    private $zoneAccess;
    private $context;
    private $recordIdentity;
    private $recordToken;
    private $isEdit;
    private $recordService;
    private $recordNormalizer;

    public function __construct($recordType, $messages, $recordToken = '')
    {
        $recordType = is_string($recordType) ? strtoupper($recordType) : '';

        if(!DbsRecordCapabilities::isWritable($recordType)) {
            throw new InvalidArgumentException('Nicht unterstützter DBS-Recordtyp.');
        }

        $this->recordType = $recordType;
        $this->messages = is_array($messages) ? $messages : array();
        $this->recordToken = is_string($recordToken) ? $recordToken : '';
        $this->isEdit = $this->recordToken !== '';
        $this->recordNormalizer = new DbsRecordNormalizer();
    }

    public function onShowNew()
    {
        global $app;

        $this->initializeAccess();
        $editableRecord = null;

        if($this->isEdit) {
            try {
                $editableRecord = $this->getRecordService()->loadEditableRecord(
                    $this->context,
                    $this->recordToken
                );
            } catch (DbsRecordException $exception) {
                if(
                    $exception->getCode() === DbsRecordService::ERROR_ACCESS_DENIED
                    || $exception->getCode() === DbsRecordService::ERROR_INVALID_IDENTIFIER
                    || $exception->getCode() === DbsRecordService::ERROR_INVALID_RECORD
                ) {
                    $this->denyAccess();
                }

                $this->redirectToZoneWithMessage(
                    'show_error_msg',
                    $this->message('edit_stale', 'Der DNS-Record wurde zwischenzeitlich geändert oder gelöscht.')
                );
            } catch (DbsClientException $exception) {
                $app->log(
                    'DBS ' . $this->recordType . ' record edit load failed (code ' . (int)$exception->getCode() . ').',
                    LOGLEVEL_ERROR
                );
                $this->redirectToZoneWithMessage(
                    'show_error_msg',
                    $this->message('edit_error', 'Der DBS-DNS-Record konnte nicht geladen werden.')
                );
            }
        }

        if($app->tform->errorMessage === '') {
            parent::onShowNew();
        } else {
            $record = $app->tform->getHTML($this->dataRecord, 'dns', 'EDIT');
            $app->tpl->setVar($record);
        }

        if($editableRecord !== null) {
            $this->dataRecord = array_merge(
                is_array($this->dataRecord) ? $this->dataRecord : array(),
                $this->mapProviderRecordToForm(
                    $editableRecord['record'],
                    $editableRecord['origin']
                )
            );
            $record = $app->tform->getHTML($this->dataRecord, 'dns', 'EDIT');
            $app->tpl->setVar($record);
        }

        $app->tpl->setVar('zone', $this->cacheId);
        $app->tpl->setVar('type', $this->recordType);
        $app->tpl->setVar('record_token', $this->recordToken, true);
        $app->tpl->setVar('btn_save_txt', $app->lng('btn_save_txt'));
        $app->tpl->setVar('btn_cancel_txt', $app->lng('btn_cancel_txt'));
        $app->tpl->setVar('data_label_txt', $this->message('data_label', 'Wert'));
        $app->tpl->setVar('data_hint_txt', $this->message('data_hint', ''));
        $app->tpl->setVar('target_label_txt', $this->message('target_label', 'Ziel-Hostname'));
        $app->tpl->setVar('target_hint_txt', $this->message('target_hint', ''));
        $app->tpl->setVar('name_hint_txt', $this->message('name_hint', ''));
        $app->tpl->setVar('active_hint_txt', $this->message('active_hint', ''));
        $app->tpl->setVar('loading_txt', $this->message('loading', 'Laden...'));
        $app->tpl->setVar('show_srv', $this->recordType === 'SRV' ? 1 : 0);
        $app->tpl->setVar(
            'show_aux',
            in_array($this->recordType, array('MX', 'SRV'), true) ? 1 : 0
        );
    }

    public function onSubmit()
    {
        global $app;

        $app->auth->csrf_token_check('POST');
        $this->initializeAccess();

        if(!$this->sessionMayWrite()) {
            $this->onError();
            return;
        }

        $rawRecord = $this->prepareRecordForValidation($this->dataRecord);
        $validatedRecord = $this->validateWithTform($rawRecord);
        $this->dataRecord = array_merge($rawRecord, $validatedRecord);

        if($app->tform->errorMessage !== '') {
            $this->onError();
            return;
        }

        if($this->recordType === 'SRV') {
            $validatedRecord['data'] = $validatedRecord['weight'] . ' '
                . $validatedRecord['port'] . ' ' . $validatedRecord['target'];
            $this->dataRecord['data'] = $validatedRecord['data'];
        }

        $submittedType = isset($validatedRecord['type']) && is_scalar($validatedRecord['type'])
            ? strtoupper((string)$validatedRecord['type'])
            : '';

        if($submittedType !== $this->recordType) {
            $app->tform->errorMessage .= $this->message('invalid_record', 'Der DNS-Record ist ungültig.') . '<br />';
            $this->onError();
            return;
        }

        $record = array(
            'name' => isset($validatedRecord['name']) && is_scalar($validatedRecord['name'])
                ? (string)$validatedRecord['name']
                : '',
            'type' => $submittedType,
            'data' => isset($validatedRecord['data']) && is_scalar($validatedRecord['data'])
                ? (string)$validatedRecord['data']
                : '',
            'aux' => in_array($this->recordType, array('MX', 'SRV'), true)
                && isset($validatedRecord['aux']) && is_scalar($validatedRecord['aux'])
                    ? (string)$validatedRecord['aux']
                    : '0',
            'ttl' => isset($validatedRecord['ttl']) && is_scalar($validatedRecord['ttl'])
                ? (string)$validatedRecord['ttl']
                : '',
            'active' => isset($validatedRecord['active']) && is_scalar($validatedRecord['active'])
                ? (string)$validatedRecord['active']
                : ''
        );
        $service = $this->getRecordService();

        try {
            $result = $this->isEdit
                ? $service->replace($this->context, $this->recordToken, $record)
                : $service->create($this->context, -$this->cacheId, $record);
        } catch (DbsRecordException $exception) {
            if(
                $exception->getCode() === DbsRecordService::ERROR_ACCESS_DENIED
                || $exception->getCode() === DbsRecordService::ERROR_INVALID_ZONE
                || $exception->getCode() === DbsRecordService::ERROR_INVALID_IDENTIFIER
            ) {
                $this->denyAccess();
            }

            if($exception->getCode() === DbsRecordService::ERROR_STALE_RECORD) {
                $this->redirectToZoneWithMessage(
                    'show_error_msg',
                    $this->message('edit_stale', 'Der DNS-Record wurde zwischenzeitlich geändert oder gelöscht.')
                );
            }

            if($exception->getCode() === DbsRecordService::ERROR_RECORD_ALREADY_EXISTS) {
                $this->redirectToZoneWithMessage(
                    'show_error_msg',
                    $this->message('create_duplicate', 'Ein identischer DNS-Record ist bereits vorhanden.')
                );
            }

            if($exception->getCode() === DbsRecordService::ERROR_VERIFICATION_FAILED) {
                $this->redirectToZoneWithMessage(
                    'show_error_msg',
                    $this->message(
                        $this->isEdit ? 'edit_verification' : 'create_verification',
                        $this->isEdit
                            ? 'Der DBS-DNS-Record konnte nicht bestätigt werden.'
                            : 'Der DBS-DNS-Record konnte nicht bestätigt werden.'
                    )
                );
            }

            if($exception->getCode() === DbsRecordService::ERROR_CREATE_FAILED_ROLLED_BACK) {
                $this->redirectToZoneWithMessage(
                    'show_error_msg',
                    $this->message('edit_rollback', 'Die Änderung ist fehlgeschlagen; der ursprüngliche DNS-Record wurde wiederhergestellt.')
                );
            }

            if($exception->getCode() === DbsRecordService::ERROR_ROLLBACK_FAILED) {
                $app->log(
                    'CRITICAL: DBS ' . $this->recordType . ' record replace rollback failed for cache ID ' . $this->cacheId . '.',
                    LOGLEVEL_ERROR
                );
                $this->redirectToZoneWithMessage(
                    'show_error_msg',
                    $this->message('edit_rollback_failed', 'Änderung und Wiederherstellung sind fehlgeschlagen.')
                );
            }

            $this->appendValidationError($exception);
            $this->onError();
            return;
        } catch (DbsClientException $exception) {
            $app->log(
                'DBS ' . $this->recordType . ' record ' . ($this->isEdit ? 'replace' : 'create') . ' failed (code ' . (int)$exception->getCode() . ').',
                LOGLEVEL_ERROR
            );
            $app->tform->errorMessage .= $this->message(
                $this->isEdit ? 'edit_error' : 'create_error',
                $this->isEdit
                    ? 'Der DBS-DNS-Record konnte nicht geändert werden.'
                    : 'Der DBS-DNS-Record konnte nicht angelegt werden.'
            ) . '<br />';
            $this->onError();
            return;
        } catch (Throwable $exception) {
            $app->log(
                'DBS ' . $this->recordType . ' record ' . ($this->isEdit ? 'replace' : 'create') . ' failed (' . get_class($exception) . ').',
                LOGLEVEL_ERROR
            );
            $app->tform->errorMessage .= $this->message(
                $this->isEdit ? 'edit_error' : 'create_error',
                $this->isEdit
                    ? 'Der DBS-DNS-Record konnte nicht geändert werden.'
                    : 'Der DBS-DNS-Record konnte nicht angelegt werden.'
            ) . '<br />';
            $this->onError();
            return;
        }

        if($this->isEdit) {
            $_SESSION['show_info_msg'] = !empty($result['changed'])
                ? $this->message('edit_success', 'Der DBS-DNS-Record wurde geändert.')
                : $this->message('edit_unchanged', 'Der DBS-DNS-Record war unverändert.');
        } else {
            $_SESSION['show_info_msg'] = $this->message('create_success', 'Der DBS-DNS-Record wurde angelegt.');
        }
        $this->redirectToZone();
    }

    private function mapProviderRecordToForm($record, $origin)
    {
        $formRecord = $this->recordNormalizer->normalizeProviderToForm($record, $origin);
        $formRecord['zone'] = (string)$this->cacheId;

        return $formRecord;
    }

    private function prepareRecordForValidation($record)
    {
        if(!is_array($record)) {
            return array();
        }

        if($this->recordType === 'SRV') {
            $weight = isset($record['weight']) && is_scalar($record['weight'])
                ? $this->normalizeSrvFormInteger((string)$record['weight'])
                : '';
            $port = isset($record['port']) && is_scalar($record['port'])
                ? $this->normalizeSrvFormInteger((string)$record['port'])
                : '';
            $target = isset($record['target']) && is_scalar($record['target'])
                ? trim((string)$record['target'])
                : '';
            $record['data'] = $weight . ' ' . $port . ' ' . $target;
        }

        return $record;
    }

    private function normalizeSrvFormInteger($value)
    {
        if(preg_match('/\A[0-9]{1,10}\z/', $value) === 1 && (int)$value <= 65535) {
            return (string)(int)$value;
        }

        return $value;
    }

    private function validateWithTform($record)
    {
        global $app;

        if(
            !is_array($record)
            || !isset($app->tform->formDef['tabs']['dns']['fields'])
            || !is_array($app->tform->formDef['tabs']['dns']['fields'])
        ) {
            $app->tform->errorMessage .= $this->message(
                'invalid_record',
                'Der DNS-Record ist ungültig.'
            ) . '<br />';
            return array();
        }

        $validated = array();

        foreach($app->tform->formDef['tabs']['dns']['fields'] as $fieldName => $field) {
            $value = isset($record[$fieldName]) && is_scalar($record[$fieldName])
                ? (string)$record[$fieldName]
                : '';

            if(isset($field['filters']) && is_array($field['filters'])) {
                $value = $app->tform->filterField(
                    $fieldName,
                    $value,
                    $field['filters'],
                    'SAVE'
                );
            }

            if(isset($field['validators']) && is_array($field['validators'])) {
                $app->tform->validateField($fieldName, $value, $field['validators']);
            }

            if(
                isset($field['datatype'])
                && $field['datatype'] === 'INTEGER'
                && preg_match('/\A[0-9]{1,10}\z/', $value) === 1
            ) {
                $value = (string)(int)$value;
            }

            $validated[$fieldName] = $value;
        }

        return $validated;
    }

    private function initializeAccess()
    {
        global $app;

        if($this->zoneAccess !== null) {
            return;
        }

        $rawRecordId = isset($_REQUEST['id']) && is_scalar($_REQUEST['id'])
            ? (string)$_REQUEST['id']
            : '';

        if($rawRecordId !== '' && $rawRecordId !== '0') {
            $this->denyAccess();
        }

        $this->recordIdentity = new DbsRecordIdentity();

        if($this->isEdit) {
            try {
                $identity = $this->recordIdentity->verify($this->recordToken);
            } catch (DbsRecordIdentityException $exception) {
                $this->denyAccess();
            }

            if($identity['record']['type'] !== $this->recordType) {
                $this->denyAccess();
            }

            $this->cacheId = (int)$identity['cache_id'];
        } else {
            $rawZoneId = isset($_REQUEST['zone']) && is_scalar($_REQUEST['zone'])
                ? (string)$_REQUEST['zone']
                : '';

            if(preg_match('/\A[1-9][0-9]*\z/', $rawZoneId) !== 1) {
                $this->denyAccess();
            }

            $this->cacheId = (int)$rawZoneId;

            if($this->cacheId <= 0 || (string)$this->cacheId !== $rawZoneId) {
                $this->denyAccess();
            }
        }

        $matcher = new DomainMatcher(array($app->functions, 'idn_encode'));
        $this->zoneAccess = new DbsZoneAccess($app->db, $matcher);
        try {
            $this->context = $this->zoneAccess->createContextFromIspConfig(
                $app->auth,
                $_SESSION['s']['user']
            );
        } catch (DbsZoneAccessException $exception) {
            http_response_code(403);
            $app->error($app->lng('dbsdns_configuration_required_txt'));
            exit;
        }

        $accessibleZone = $this->zoneAccess->getAccessibleZoneByCacheId(
            $this->context,
            $this->cacheId
        );

        if($accessibleZone === null) {
            $this->denyAccess();
        }
    }

    private function getRecordService()
    {
        if($this->recordService === null) {
            $this->recordService = new DbsRecordService(
                $this->zoneAccess,
                $this->recordIdentity,
                function() {
                    return new DbsClient();
                }
            );
        }

        return $this->recordService;
    }

    private function sessionMayWrite()
    {
        global $app;

        if(!DbsSessionWriteAccess::mayWrite($app, $_SESSION['s']['user'])) {
            $app->tform->errorMessage .= $app->lng('client_you_are_locked') . '<br />';
            return false;
        }

        return true;
    }

    private function appendValidationError($exception)
    {
        global $app;

        $specificMessage = '';
        $previous = $exception instanceof Throwable ? $exception->getPrevious() : null;

        if($previous instanceof DbsRecordNormalizationException) {
            $specificMessage = $this->message('validation_' . $previous->getReason(), '');
        }

        if($specificMessage !== '') {
            $app->tform->errorMessage .= $specificMessage . '<br />';
        }

        $app->tform->errorMessage .= $this->message(
            'invalid_record',
            'Der DNS-Record ist ungültig.'
        ) . '<br />';
    }

    private function denyAccess()
    {
        global $app;

        http_response_code(403);
        $app->error($this->message('access_denied', 'Kein Zugriff auf diese DBS-Zone.'));
        exit;
    }

    private function redirectToZone()
    {
        session_write_close();
        header('Location: zone_view.php?id=' . $this->cacheId);
        exit;
    }

    private function redirectToZoneWithMessage($messageType, $message)
    {
        $_SESSION[$messageType] = $message;
        $this->redirectToZone();
    }

    private function message($key, $fallback)
    {
        return isset($this->messages[$key]) && is_string($this->messages[$key])
            ? $this->messages[$key]
            : $fallback;
    }
}
