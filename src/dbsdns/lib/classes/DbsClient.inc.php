<?php

require_once __DIR__ . '/DbsRecordCapabilities.inc.php';
require_once __DIR__ . '/DbsRecordNormalizer.inc.php';
require_once __DIR__ . '/DbsCredentialProvider.inc.php';

class DbsClientException extends RuntimeException
{
}

class DbsConfigurationException extends DbsClientException
{
}

class DbsClient
{
    const CONNECTION_TIMEOUT_SECONDS = 10;
    const REQUEST_TIMEOUT_SECONDS = 15;
    const DOMAIN_LIST_MAX_RESULTS = 2000;

    const RETURN_CODE_SUCCESS = 1000;
    const RETURN_CODE_NO_RESULTS = 2003;
    const RETURN_CODE_NOT_FOUND = 2303;

    const ERROR_CONFIGURATION = 10001;
    const ERROR_SOAP_UNAVAILABLE = 10002;
    const ERROR_UNREACHABLE = 10003;
    const ERROR_REQUEST_FAILED = 10004;
    const ERROR_INVALID_RESPONSE = 10005;
    const ERROR_REQUEST_REJECTED = 10006;
    const ERROR_ZONE_NOT_FOUND = 10007;
    const ERROR_INVALID_INPUT = 10008;

    private $soapClient;
    private $recordNormalizer;

    public function __construct($configuration = null)
    {
        $configuration = $this->loadConfiguration($configuration);

        if(!class_exists('SoapClient')) {
            throw new DbsConfigurationException(
                'Die PHP-SOAP-Erweiterung ist für die DBS-Integration nicht verfügbar.',
                self::ERROR_SOAP_UNAVAILABLE
            );
        }

        $options = array(
            'login' => $configuration->getUsername(),
            'password' => $configuration->getPassword(),
            'authentication' => SOAP_AUTHENTICATION_BASIC,
            'soap_version' => SOAP_1_1,
            'encoding' => 'UTF-8',
            'exceptions' => true,
            'trace' => false,
            'connection_timeout' => self::CONNECTION_TIMEOUT_SECONDS,
            'cache_wsdl' => WSDL_CACHE_MEMORY,
            'keep_alive' => false
        );

        try {
            $this->soapClient = $this->runWithRuntimeGuards(function() use ($configuration, $options) {
                return new SoapClient($configuration->getWsdlUrl(), $options);
            });
        } catch (SoapFault $exception) {
            $this->throwCommunicationException($exception);
        } catch (Throwable $exception) {
            $this->throwCommunicationException($exception);
        }
    }

    public function testConnection()
    {
        $request = $this->buildDomainListRequest(1, 0, array('domainName'));
        $response = $this->executeDomainListExtended($request);
        $returnCode = $this->readReturnCode($response);

        if($returnCode === self::RETURN_CODE_SUCCESS || $returnCode === self::RETURN_CODE_NO_RESULTS) {
            return;
        }

        throw new DbsClientException(
            'Der DBS-Verbindungstest wurde vom Domain-Bestellsystem abgelehnt.',
            self::ERROR_REQUEST_REJECTED
        );
    }

    public function listDomains($limit, $offset)
    {
        $limit = (int)$limit;
        $offset = (int)$offset;

        if($limit < 1 || $limit >= self::DOMAIN_LIST_MAX_RESULTS || $offset < 0) {
            throw new DbsClientException('Ungültige DBS-Listenparameter.', self::ERROR_INVALID_INPUT);
        }

        $request = $this->buildDomainListRequest(
            $limit + 1,
            $offset,
            array('domainName', 'domainNameAce', 'domainNameIdn', 'domainStatus')
        );
        $response = $this->executeDomainListExtended($request);
        $returnCode = $this->readReturnCode($response);

        if($returnCode === self::RETURN_CODE_NO_RESULTS) {
            return array(
                'domains' => array(),
                'has_more' => false,
                'empty_inventory_confirmed' => true
            );
        }

        if($returnCode !== self::RETURN_CODE_SUCCESS) {
            throw new DbsClientException(
                'Die DBS-Domainliste wurde vom Domain-Bestellsystem abgelehnt.',
                self::ERROR_REQUEST_REJECTED
            );
        }

        if(!property_exists($response, 'domainInfo')) {
            $this->throwInvalidResponse();
        }

        $domains = $this->parseDomains($response->domainInfo);

        if(count($domains) === 0) {
            $this->throwInvalidResponse();
        }

        $hasMore = count($domains) > $limit;

        if($hasMore) {
            $domains = array_slice($domains, 0, $limit);
        }

        return array(
            'domains' => $domains,
            'has_more' => $hasMore,
            'empty_inventory_confirmed' => false
        );
    }

    public function listAllDomains($pageSize = null)
    {
        $inventory = $this->getDomainInventory($pageSize);

        return $inventory['domains'];
    }

    public function getDomainInventory($pageSize = null)
    {
        $pageSize = $pageSize === null ? self::DOMAIN_LIST_MAX_RESULTS - 1 : (int)$pageSize;

        if($pageSize < 1 || $pageSize >= self::DOMAIN_LIST_MAX_RESULTS) {
            throw new DbsClientException('Ungültige DBS-Listenparameter.', self::ERROR_INVALID_INPUT);
        }

        $offset = 0;
        $domains = array();

        do {
            $result = $this->listDomains($pageSize, $offset);
            $pageDomains = $result['domains'];

            if($result['empty_inventory_confirmed']) {
                if($offset !== 0 || count($domains) !== 0) {
                    $this->throwInvalidResponse();
                }

                return array(
                    'domains' => array(),
                    'empty_inventory_confirmed' => true
                );
            }

            if($result['has_more'] && count($pageDomains) === 0) {
                $this->throwInvalidResponse();
            }

            foreach($pageDomains as $domain) {
                $domains[] = $domain;
            }

            $offset += count($pageDomains);
        } while($result['has_more']);

        return array(
            'domains' => $domains,
            'empty_inventory_confirmed' => false
        );
    }

    public function getZoneInfo($origin)
    {
        $origin = $this->normalizeOrigin($origin);

        if($origin === false) {
            throw new DbsClientException('Ungültige DNS-Zone.', self::ERROR_INVALID_INPUT);
        }

        $request = array(
            'soaOrigin' => $origin,
            'clientTRID' => '',
            'forReseller' => ''
        );
        $response = $this->executeNameserverZoneInfo($request);
        $returnCode = $this->readReturnCode($response);

        if($returnCode === self::RETURN_CODE_NOT_FOUND) {
            throw new DbsClientException(
                'Für diese Domain ist im Domain-Bestellsystem keine DNS-Zone vorhanden.',
                self::ERROR_ZONE_NOT_FOUND
            );
        }

        if($returnCode !== self::RETURN_CODE_SUCCESS) {
            throw new DbsClientException(
                'Die DBS-DNS-Zone wurde vom Domain-Bestellsystem abgelehnt.',
                self::ERROR_REQUEST_REJECTED
            );
        }

        $responseOrigin = $this->normalizeOrigin($this->readStringProperty($response, 'origin'));

        if($responseOrigin === false || $responseOrigin !== $origin) {
            $this->throwInvalidResponse();
        }

        $zone = array(
            'origin' => $responseOrigin,
            'refresh' => $this->readStringProperty($response, 'refresh'),
            'retry' => $this->readStringProperty($response, 'retry'),
            'expire' => $this->readStringProperty($response, 'expire'),
            'ttl' => $this->readStringProperty($response, 'ttl'),
            'minimum_ttl' => $this->readStringProperty($response, 'minimumTtl'),
            'mbox' => $this->readOptionalStringProperty($response, 'mBox'),
            'primary' => $this->readOptionalStringProperty($response, 'primary'),
            'records' => array()
        );

        if(property_exists($response, 'rrList')) {
            $zone['records'] = $this->parseResourceRecords($response->rrList, $responseOrigin);
        }

        return $zone;
    }

    public function createRecord($origin, $record)
    {
        $origin = $this->normalizeOrigin($origin);

        if($origin === false) {
            throw new DbsClientException('Ungültige DNS-Zone.', self::ERROR_INVALID_INPUT);
        }

        $record = $this->buildResourceRecord($record, $origin, true);
        $request = array(
            'soaOrigin' => $origin,
            'rr' => array(
                'item' => array($record)
            ),
            'clientTRID' => '',
            'forReseller' => ''
        );
        $response = $this->executeNameserverRRCreate($request);

        if($this->readReturnCode($response) !== self::RETURN_CODE_SUCCESS) {
            throw new DbsClientException(
                'Der DNS-Record wurde vom Domain-Bestellsystem nicht angelegt.',
                self::ERROR_REQUEST_REJECTED
            );
        }
    }

    public function deleteRecord($origin, $record)
    {
        $origin = $this->normalizeOrigin($origin);

        if($origin === false) {
            throw new DbsClientException('Ungültige DNS-Zone.', self::ERROR_INVALID_INPUT);
        }

        $record = $this->buildResourceRecord($record, $origin, false);
        $request = array(
            'soaOrigin' => $origin,
            'rr' => $record,
            'clientTRID' => '',
            'forReseller' => ''
        );
        $response = $this->executeNameserverRRDelete($request);

        if($this->readReturnCode($response) !== self::RETURN_CODE_SUCCESS) {
            throw new DbsClientException(
                'Der DNS-Record wurde vom Domain-Bestellsystem nicht gelöscht.',
                self::ERROR_REQUEST_REJECTED
            );
        }
    }

    public function restoreRecord($origin, $record)
    {
        $origin = $this->normalizeOrigin($origin);

        if($origin === false) {
            throw new DbsClientException('Ungültige DNS-Zone.', self::ERROR_INVALID_INPUT);
        }

        $record = $this->buildResourceRecord($record, $origin, false);
        $request = array(
            'soaOrigin' => $origin,
            'rr' => array(
                'item' => array($record)
            ),
            'clientTRID' => '',
            'forReseller' => ''
        );
        $response = $this->executeNameserverRRCreate($request);

        if($this->readReturnCode($response) !== self::RETURN_CODE_SUCCESS) {
            throw new DbsClientException(
                'Der ursprüngliche DNS-Record wurde vom Domain-Bestellsystem nicht wiederhergestellt.',
                self::ERROR_REQUEST_REJECTED
            );
        }
    }

    public function updateZoneSettings($origin, $settings)
    {
        $origin = $this->normalizeOrigin($origin);

        if($origin === false || !is_array($settings)) {
            throw new DbsClientException('Ungültige DNS-Zoneneinstellungen.', self::ERROR_INVALID_INPUT);
        }

        $expectedKeys = array('mbox', 'refresh', 'retry', 'expire', 'minimum_ttl', 'ttl');

        if(array_keys($settings) !== $expectedKeys) {
            throw new DbsClientException('Ungültige DNS-Zoneneinstellungen.', self::ERROR_INVALID_INPUT);
        }

        foreach($expectedKeys as $key) {
            if(!is_string($settings[$key])) {
                throw new DbsClientException('Ungültige DNS-Zoneneinstellungen.', self::ERROR_INVALID_INPUT);
            }
        }

        if(
            $settings['mbox'] === ''
            || strlen($settings['mbox']) > 254
            || preg_match('/[\x00-\x20\x7f]/', $settings['mbox'])
        ) {
            throw new DbsClientException('Ungültige DNS-Zoneneinstellungen.', self::ERROR_INVALID_INPUT);
        }

        foreach(array('refresh', 'retry', 'expire', 'minimum_ttl', 'ttl') as $key) {
            if(!$this->validRecordInteger($settings[$key], 0, 2147483647)) {
                throw new DbsClientException('Ungültige DNS-Zoneneinstellungen.', self::ERROR_INVALID_INPUT);
            }
        }

        $request = array(
            'soaOrigin' => $origin,
            'soaExpire' => $settings['expire'],
            'soaMbox' => $settings['mbox'],
            'soaMinimum' => $settings['minimum_ttl'],
            'soaRefresh' => $settings['refresh'],
            'soaRetry' => $settings['retry'],
            'soaTtl' => $settings['ttl'],
            'clientTRID' => '',
            'forReseller' => ''
        );
        $response = $this->executeNameserverSOAUpdate($request);

        if($this->readReturnCode($response) !== self::RETURN_CODE_SUCCESS) {
            throw new DbsClientException(
                'Die DNS-Zoneneinstellungen wurden vom Domain-Bestellsystem nicht aktualisiert.',
                self::ERROR_REQUEST_REJECTED
            );
        }
    }

    private function buildDomainListRequest($limit, $offset, $fields)
    {
        return array(
            'level' => 'own',
            'limit' => (string)$limit,
            'offset' => (string)$offset,
            'filter' => array(
                'item' => array()
            ),
            'fields' => array(
                'item' => $fields
            ),
            'clientTRID' => '',
            'forReseller' => ''
        );
    }

    private function executeDomainListExtended($request)
    {
        try {
            return $this->runWithRuntimeGuards(function() use ($request) {
                return $this->soapClient->domainListExtended($request);
            });
        } catch (SoapFault $exception) {
            $this->throwCommunicationException($exception);
        } catch (Throwable $exception) {
            $this->throwCommunicationException($exception);
        }
    }

    private function executeNameserverZoneInfo($request)
    {
        try {
            return $this->runWithRuntimeGuards(function() use ($request) {
                return $this->soapClient->nameserverZoneInfo($request);
            });
        } catch (SoapFault $exception) {
            $this->throwCommunicationException($exception);
        } catch (Throwable $exception) {
            $this->throwCommunicationException($exception);
        }
    }

    private function executeNameserverRRCreate($request)
    {
        try {
            return $this->runWithRuntimeGuards(function() use ($request) {
                return $this->soapClient->nameserverRRCreate($request);
            });
        } catch (SoapFault $exception) {
            $this->throwCommunicationException($exception);
        } catch (Throwable $exception) {
            $this->throwCommunicationException($exception);
        }
    }

    private function executeNameserverRRDelete($request)
    {
        try {
            return $this->runWithRuntimeGuards(function() use ($request) {
                return $this->soapClient->nameserverRRDelete($request);
            });
        } catch (SoapFault $exception) {
            $this->throwCommunicationException($exception);
        } catch (Throwable $exception) {
            $this->throwCommunicationException($exception);
        }
    }

    private function executeNameserverSOAUpdate($request)
    {
        try {
            return $this->runWithRuntimeGuards(function() use ($request) {
                return $this->soapClient->nameserverSOAUpdate($request);
            });
        } catch (SoapFault $exception) {
            $this->throwCommunicationException($exception);
        } catch (Throwable $exception) {
            $this->throwCommunicationException($exception);
        }
    }

    private function buildResourceRecord($record, $origin, $isCreate)
    {
        try {
            return $this->getRecordNormalizer()->normalizeProviderRecord(
                $record,
                $origin,
                $isCreate
            );
        } catch (DbsRecordNormalizationException $exception) {
            throw new DbsClientException('Ungültiger DNS-Record.', self::ERROR_INVALID_INPUT);
        }
    }

    private function validRecordInteger($value, $minimum, $maximum)
    {
        return is_string($value)
            && preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/', $value) === 1
            && (int)$value >= $minimum
            && (int)$value <= $maximum;
    }

    private function readReturnCode($response)
    {
        if(!is_object($response) || !property_exists($response, 'returnCode')) {
            $this->throwInvalidResponse();
        }

        $returnCode = $response->returnCode;

        if(is_int($returnCode)) {
            return $returnCode;
        }

        if(is_string($returnCode) && preg_match('/\A-?[0-9]+\z/', $returnCode)) {
            return (int)$returnCode;
        }

        $this->throwInvalidResponse();
    }

    private function parseDomains($domainInfo)
    {
        $domains = array();

        foreach($this->normalizeSoapItems($domainInfo) as $domainEntry) {
            if(!is_object($domainEntry) || !property_exists($domainEntry, 'domainInfoItem')) {
                $this->throwInvalidResponse();
            }

            $fields = array();

            foreach($this->normalizeSoapItems($domainEntry->domainInfoItem) as $field) {
                if(
                    !is_object($field) ||
                    !property_exists($field, 'key') ||
                    !is_scalar($field->key) ||
                    !property_exists($field, 'value') ||
                    ($field->value !== null && !is_scalar($field->value))
                ) {
                    $this->throwInvalidResponse();
                }

                $fields[(string)$field->key] = $field->value === null ? '' : (string)$field->value;
            }

            $originCandidate = isset($fields['domainNameAce']) && $fields['domainNameAce'] !== ''
                ? $fields['domainNameAce']
                : (isset($fields['domainName']) ? $fields['domainName'] : '');
            $origin = $this->normalizeOrigin($originCandidate);

            if($origin === false) {
                $this->throwInvalidResponse();
            }

            $displayName = isset($fields['domainNameIdn']) && $fields['domainNameIdn'] !== ''
                ? $fields['domainNameIdn']
                : (isset($fields['domainName']) && $fields['domainName'] !== '' ? $fields['domainName'] : $origin);

            $domains[] = array(
                'domain_name' => $displayName,
                'origin' => $origin,
                'status' => isset($fields['domainStatus']) ? $fields['domainStatus'] : ''
            );
        }

        return $domains;
    }

    private function parseResourceRecords($rrList, $origin)
    {
        $records = array();

        foreach($this->normalizeSoapItems($rrList) as $record) {
            if(!is_object($record)) {
                $this->throwInvalidResponse();
            }

            $providerRecord = array(
                'type' => $this->readStringProperty($record, 'type'),
                'name' => $this->readStringProperty($record, 'name'),
                'data' => $this->readStringProperty($record, 'data'),
                'aux' => $this->readStringProperty($record, 'aux'),
                'ttl' => $this->readStringProperty($record, 'ttl')
            );

            if(DbsRecordCapabilities::isWritable(strtoupper($providerRecord['type']))) {
                try {
                    $providerRecord = $this->getRecordNormalizer()->normalizeProviderRecord(
                        $providerRecord,
                        $origin
                    );
                } catch (DbsRecordNormalizationException $exception) {
                    // Ungültige Providerrecords bleiben sichtbar, erhalten aber keine Schreibaktion.
                }
            }

            $records[] = $providerRecord;
        }

        return $records;
    }

    private function getRecordNormalizer()
    {
        if($this->recordNormalizer === null) {
            $this->recordNormalizer = new DbsRecordNormalizer();
        }

        return $this->recordNormalizer;
    }

    private function normalizeSoapItems($container)
    {
        if($container === null) {
            return array();
        }

        if(is_object($container) && property_exists($container, 'item')) {
            $container = $container->item;
        }

        if(is_object($container) && count(get_object_vars($container)) === 0) {
            return array();
        }

        if($container === null) {
            return array();
        }

        return is_array($container) ? array_values($container) : array($container);
    }

    private function readStringProperty($object, $property)
    {
        if(!is_object($object) || !property_exists($object, $property) || !is_scalar($object->$property)) {
            $this->throwInvalidResponse();
        }

        return (string)$object->$property;
    }

    private function readOptionalStringProperty($object, $property)
    {
        if(!is_object($object) || !property_exists($object, $property) || $object->$property === null) {
            return '';
        }

        if(!is_scalar($object->$property)) {
            $this->throwInvalidResponse();
        }

        return (string)$object->$property;
    }

    private function normalizeOrigin($origin)
    {
        if(!is_string($origin)) {
            return false;
        }

        $origin = trim($origin);

        if(substr($origin, -1) === '.') {
            $origin = substr($origin, 0, -1);
        }

        if(
            $origin === '' ||
            strlen($origin) > 253 ||
            !preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/i', $origin)
        ) {
            return false;
        }

        return strtolower($origin);
    }

    private function throwInvalidResponse()
    {
        throw new DbsClientException(
            'Das Domain-Bestellsystem hat eine ungültige Antwort geliefert.',
            self::ERROR_INVALID_RESPONSE
        );
    }

    private function loadConfiguration($configuration)
    {
        if($configuration instanceof DbsConfiguration) {
            return $configuration;
        }

        if($configuration !== null) {
            throw new DbsConfigurationException(
                'DBS-Konfiguration unvollständig.',
                self::ERROR_CONFIGURATION
            );
        }

        try {
            return DbsCredentialProvider::fromGlobals()->resolve();
        } catch (DbsCredentialException $exception) {
            throw new DbsConfigurationException(
                'DBS-Konfiguration unvollständig.',
                self::ERROR_CONFIGURATION
            );
        } catch (Throwable $exception) {
            throw new DbsConfigurationException(
                'DBS-Konfiguration unvollständig.',
                self::ERROR_CONFIGURATION
            );
        }
    }

    private function runWithRuntimeGuards($callback)
    {
        $previousTimeout = ini_get('default_socket_timeout');
        $timeoutChanged = false;

        if($previousTimeout !== false) {
            $timeoutChanged = @ini_set('default_socket_timeout', (string)self::REQUEST_TIMEOUT_SECONDS) !== false;
        }

        set_error_handler(function($severity, $message, $file, $line) {
            return true;
        });

        try {
            return call_user_func($callback);
        } finally {
            restore_error_handler();

            if($timeoutChanged) {
                @ini_set('default_socket_timeout', (string)$previousTimeout);
            }
        }
    }

    private function throwCommunicationException($exception)
    {
        if($this->isTransportFailure($exception)) {
            throw new DbsClientException(
                'Das Domain-Bestellsystem ist derzeit nicht erreichbar.',
                self::ERROR_UNREACHABLE
            );
        }

        throw new DbsClientException(
            'Die Anfrage an das Domain-Bestellsystem ist fehlgeschlagen.',
            self::ERROR_REQUEST_FAILED
        );
    }

    private function isTransportFailure($exception)
    {
        $message = strtolower($exception->getMessage());
        $transportErrors = array(
            'could not connect',
            'failed to connect',
            'could not resolve host',
            'getaddrinfo failed',
            'network is unreachable',
            'connection reset',
            'connection timed out',
            'operation timed out',
            'timed out',
            'error fetching http headers',
            'couldn\'t load from',
            'failed to open stream',
            'http request failed',
            'ssl operation failed'
        );

        foreach($transportErrors as $transportError) {
            if(strpos($message, $transportError) !== false) {
                return true;
            }
        }

        return false;
    }
}
