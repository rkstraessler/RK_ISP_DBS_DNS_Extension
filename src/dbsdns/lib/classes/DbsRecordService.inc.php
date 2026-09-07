<?php

require_once __DIR__ . '/DbsClient.inc.php';
require_once __DIR__ . '/DbsRecordCapabilities.inc.php';
require_once __DIR__ . '/DbsRecordIdentity.inc.php';
require_once __DIR__ . '/DbsRecordNormalizer.inc.php';

class DbsRecordException extends RuntimeException
{
}

class DbsRecordService
{
    const ERROR_ACCESS_DENIED = 11001;
    const ERROR_INVALID_ZONE = 11002;
    const ERROR_INVALID_RECORD = 11003;
    const ERROR_INVALID_IDENTIFIER = 11004;
    const ERROR_RECORD_NOT_FOUND = 11005;
    const ERROR_STALE_RECORD = 11006;
    const ERROR_CREATE_FAILED_ROLLED_BACK = 11007;
    const ERROR_ROLLBACK_FAILED = 11008;
    const ERROR_RECORD_ALREADY_EXISTS = 11009;
    const ERROR_VERIFICATION_FAILED = 11010;

    private $zoneAccess;
    private $recordIdentity;
    private $clientFactory;
    private $recordNormalizer;

    public function __construct(
        $zoneAccess,
        DbsRecordIdentity $recordIdentity,
        $clientFactory = null,
        $recordNormalizer = null
    )
    {
        if(!is_object($zoneAccess) || !method_exists($zoneAccess, 'getAccessibleZoneByCacheId')) {
            throw new InvalidArgumentException('Ungültige DBS-Zugriffsprüfung.');
        }

        if($clientFactory !== null && !is_callable($clientFactory)) {
            throw new InvalidArgumentException('Ungültige DBS-Client-Factory.');
        }

        $this->zoneAccess = $zoneAccess;
        $this->recordIdentity = $recordIdentity;
        $this->clientFactory = $clientFactory;
        $this->recordNormalizer = $recordNormalizer === null
            ? new DbsRecordNormalizer()
            : $recordNormalizer;

        if(
            !is_object($this->recordNormalizer)
            || !method_exists($this->recordNormalizer, 'normalizeFormToProviderRecord')
            || !method_exists($this->recordNormalizer, 'normalizeProviderRecord')
        ) {
            throw new InvalidArgumentException('Ungültige DBS-Record-Normalisierung.');
        }
    }

    public function create($context, $virtualZoneId, $record)
    {
        $cacheId = $this->cacheIdFromVirtualZone($virtualZoneId);
        $zone = $this->resolveAccessibleZone($context, $cacheId);
        $record = $this->normalizeFormRecord($record, $zone['normalized_domain']);
        $client = $this->createClient();
        $liveZone = $client->getZoneInfo($zone['normalized_domain']);
        $this->assertLiveZone($liveZone, $zone['normalized_domain']);

        if($this->liveZoneContainsRecord($liveZone, $zone['normalized_domain'], $record)) {
            throw new DbsRecordException(
                'Ein identischer DNS-Record ist beim Provider bereits vorhanden.',
                self::ERROR_RECORD_ALREADY_EXISTS
            );
        }

        $client->createRecord($zone['normalized_domain'], $record);
        $updatedZone = $client->getZoneInfo($zone['normalized_domain']);
        $this->assertLiveZone($updatedZone, $zone['normalized_domain']);

        if(!$this->liveZoneContainsRecord($updatedZone, $zone['normalized_domain'], $record)) {
            throw new DbsRecordException(
                'Der angelegte DNS-Record konnte beim Provider nicht bestätigt werden.',
                self::ERROR_VERIFICATION_FAILED
            );
        }

        return array(
            'cache_id' => $cacheId,
            'virtual_zone_id' => -$cacheId,
            'origin' => $zone['normalized_domain'],
            'record' => $record,
            'zone' => $updatedZone
        );
    }

    public function delete($context, $token)
    {
        $identity = $this->verifyIdentity($token);
        $record = $this->normalizeCanonicalRecord($identity['record']);
        $zone = $this->resolveAccessibleZone($context, $identity['cache_id']);
        $client = $this->createClient();
        $liveZone = $client->getZoneInfo($zone['normalized_domain']);
        $this->assertLiveZone($liveZone, $zone['normalized_domain']);

        if(!$this->liveZoneContainsRecord($liveZone, $zone['normalized_domain'], $record)) {
            throw new DbsRecordException(
                'Der DNS-Record ist in dieser DBS-Zone nicht vorhanden.',
                self::ERROR_RECORD_NOT_FOUND
            );
        }

        $client->deleteRecord($zone['normalized_domain'], $record);
        $updatedZone = $client->getZoneInfo($zone['normalized_domain']);
        $this->assertLiveZone($updatedZone, $zone['normalized_domain']);

        if($this->liveZoneContainsRecord($updatedZone, $zone['normalized_domain'], $record)) {
            throw new DbsRecordException(
                'Der gelöschte DNS-Record ist beim Provider weiterhin vorhanden.',
                self::ERROR_VERIFICATION_FAILED
            );
        }

        return array(
            'cache_id' => $identity['cache_id'],
            'virtual_zone_id' => -$identity['cache_id'],
            'origin' => $zone['normalized_domain'],
            'record' => $record,
            'zone' => $updatedZone
        );
    }

    public function loadEditableRecord($context, $token)
    {
        $identity = $this->verifyIdentity($token);
        $record = $identity['record'];
        $record = $this->normalizeCanonicalRecord($record);
        $zone = $this->resolveAccessibleZone($context, $identity['cache_id']);
        $client = $this->createClient();
        $liveZone = $client->getZoneInfo($zone['normalized_domain']);
        $this->assertLiveZone($liveZone, $zone['normalized_domain']);

        if(!$this->liveZoneContainsRecord($liveZone, $zone['normalized_domain'], $record)) {
            throw new DbsRecordException(
                'Der DNS-Record wurde zwischenzeitlich geändert oder gelöscht.',
                self::ERROR_STALE_RECORD
            );
        }

        return array(
            'cache_id' => $identity['cache_id'],
            'virtual_zone_id' => -$identity['cache_id'],
            'origin' => $zone['normalized_domain'],
            'record' => $record,
            'zone' => $liveZone
        );
    }

    public function replace($context, $token, $record)
    {
        $identity = $this->verifyIdentity($token);
        $oldRecord = $identity['record'];
        $oldRecord = $this->normalizeCanonicalRecord($oldRecord);
        $zone = $this->resolveAccessibleZone($context, $identity['cache_id']);

        try {
            $newRecord = $this->normalizeFormRecord($record, $zone['normalized_domain']);
        } catch (DbsRecordException $createValidationException) {
            $providerCompatibleRecord = $this->normalizeFormRecord(
                $record,
                $zone['normalized_domain'],
                true
            );

            if($providerCompatibleRecord !== $oldRecord) {
                throw $createValidationException;
            }

            $newRecord = $providerCompatibleRecord;
        }

        if($newRecord['type'] !== $oldRecord['type']) {
            $this->throwInvalidRecord();
        }

        $client = $this->createClient();
        $liveZone = $client->getZoneInfo($zone['normalized_domain']);
        $this->assertLiveZone($liveZone, $zone['normalized_domain']);

        if(!$this->liveZoneContainsRecord($liveZone, $zone['normalized_domain'], $oldRecord)) {
            throw new DbsRecordException(
                'Der DNS-Record wurde zwischenzeitlich geändert oder gelöscht.',
                self::ERROR_STALE_RECORD
            );
        }

        if($newRecord === $oldRecord) {
            return array(
                'cache_id' => $identity['cache_id'],
                'virtual_zone_id' => -$identity['cache_id'],
                'origin' => $zone['normalized_domain'],
                'record' => $oldRecord,
                'changed' => false
            );
        }

        $client->deleteRecord($zone['normalized_domain'], $oldRecord);

        try {
            $client->createRecord($zone['normalized_domain'], $newRecord);
            $updatedZone = $client->getZoneInfo($zone['normalized_domain']);
            $this->assertLiveZone($updatedZone, $zone['normalized_domain']);

            if(
                !$this->liveZoneContainsRecord($updatedZone, $zone['normalized_domain'], $newRecord)
                || $this->liveZoneContainsRecord($updatedZone, $zone['normalized_domain'], $oldRecord)
            ) {
                throw new DbsRecordException(
                    'Der ersetzte DNS-Record konnte beim Provider nicht eindeutig bestätigt werden.',
                    self::ERROR_STALE_RECORD
                );
            }
        } catch (Throwable $createException) {
            $this->restoreAfterFailedReplace(
                $client,
                $zone['normalized_domain'],
                $oldRecord,
                $newRecord,
                $createException
            );
        }

        return array(
            'cache_id' => $identity['cache_id'],
            'virtual_zone_id' => -$identity['cache_id'],
            'origin' => $zone['normalized_domain'],
            'record' => $newRecord,
            'changed' => true
        );
    }

    private function verifyIdentity($token)
    {
        try {
            return $this->recordIdentity->verify($token);
        } catch (DbsRecordIdentityException $exception) {
            throw new DbsRecordException(
                'Der DBS-Record-Identifier ist ungültig.',
                self::ERROR_INVALID_IDENTIFIER
            );
        }
    }

    private function restoreAfterFailedReplace($client, $origin, $oldRecord, $newRecord, $createException)
    {
        try {
            $reconciledZone = $client->getZoneInfo($origin);
        } catch (Throwable $reconciliationException) {
            throw new DbsRecordException(
                'Der Providerzustand nach der fehlgeschlagenen Änderung ist unbestimmt.',
                self::ERROR_ROLLBACK_FAILED,
                $reconciliationException
            );
        }

        $oldExists = $this->liveZoneContainsRecord($reconciledZone, $origin, $oldRecord);
        $newExists = $this->liveZoneContainsRecord($reconciledZone, $origin, $newRecord);

        if($oldExists && !$newExists) {
            throw new DbsRecordException(
                'Die Änderung ist fehlgeschlagen; der ursprüngliche DNS-Record ist weiterhin vorhanden.',
                self::ERROR_CREATE_FAILED_ROLLED_BACK,
                $createException
            );
        }

        if($oldExists || $newExists) {
            throw new DbsRecordException(
                'Der Providerzustand nach der fehlgeschlagenen Änderung ist nicht eindeutig wiederherstellbar.',
                self::ERROR_ROLLBACK_FAILED,
                $createException
            );
        }

        try {
            if(!method_exists($client, 'restoreRecord')) {
                throw new RuntimeException('DBS-Restore ist nicht verfügbar.');
            }

            $client->restoreRecord($origin, $oldRecord);
            $restoredZone = $client->getZoneInfo($origin);

            if(
                !$this->liveZoneContainsRecord($restoredZone, $origin, $oldRecord)
                || $this->liveZoneContainsRecord($restoredZone, $origin, $newRecord)
            ) {
                throw new RuntimeException('Der wiederhergestellte DBS-Record konnte nicht bestätigt werden.');
            }
        } catch (Throwable $restoreException) {
            try {
                $client->getZoneInfo($origin);
            } catch (Throwable $reloadException) {
                // Der Controller lädt die Zone nach der Fehlermeldung nochmals live.
            }

            throw new DbsRecordException(
                'Die Änderung und die Wiederherstellung des ursprünglichen DNS-Records sind fehlgeschlagen.',
                self::ERROR_ROLLBACK_FAILED,
                $restoreException
            );
        }

        throw new DbsRecordException(
            'Die Änderung ist fehlgeschlagen; der ursprüngliche DNS-Record wurde wiederhergestellt.',
            self::ERROR_CREATE_FAILED_ROLLED_BACK,
            $createException
        );
    }

    private function cacheIdFromVirtualZone($virtualZoneId)
    {
        if(is_int($virtualZoneId)) {
            $normalizedZoneId = $virtualZoneId;
        } elseif(is_string($virtualZoneId) && preg_match('/\A-[1-9][0-9]*\z/', $virtualZoneId)) {
            $normalizedZoneId = (int)$virtualZoneId;

            if((string)$normalizedZoneId !== $virtualZoneId) {
                throw new DbsRecordException('Ungültige virtuelle DBS-Zone.', self::ERROR_INVALID_ZONE);
            }
        } else {
            throw new DbsRecordException('Ungültige virtuelle DBS-Zone.', self::ERROR_INVALID_ZONE);
        }

        if($normalizedZoneId >= 0 || $normalizedZoneId === PHP_INT_MIN) {
            throw new DbsRecordException('Ungültige virtuelle DBS-Zone.', self::ERROR_INVALID_ZONE);
        }

        return -$normalizedZoneId;
    }

    private function resolveAccessibleZone($context, $cacheId)
    {
        $zone = $this->zoneAccess->getAccessibleZoneByCacheId($context, $cacheId);
        $normalizedOrigin = is_array($zone) && isset($zone['normalized_domain'])
            ? $this->recordNormalizer->normalizeOrigin($zone['normalized_domain'])
            : false;

        if(
            !is_array($zone) ||
            !isset($zone['cache_id'], $zone['normalized_domain']) ||
            (int)$zone['cache_id'] !== (int)$cacheId ||
            $normalizedOrigin === false
        ) {
            throw new DbsRecordException(
                'Kein Zugriff auf diese DBS-Zone.',
                self::ERROR_ACCESS_DENIED
            );
        }

        $zone['normalized_domain'] = $normalizedOrigin;

        return $zone;
    }

    private function normalizeFormRecord($record, $origin, $allowExistingProviderLimits = false)
    {
        try {
            return $this->recordNormalizer->normalizeFormToProviderRecord(
                $record,
                $origin,
                $allowExistingProviderLimits
            );
        } catch (DbsRecordNormalizationException $exception) {
            throw new DbsRecordException(
                'Ungültiger DNS-Record.',
                self::ERROR_INVALID_RECORD,
                $exception
            );
        }
    }

    private function normalizeCanonicalRecord($record)
    {
        try {
            return $this->recordNormalizer->normalizeCanonicalProviderRecord($record);
        } catch (DbsRecordNormalizationException $exception) {
            throw new DbsRecordException(
                'Ungültiger DNS-Record.',
                self::ERROR_INVALID_RECORD,
                $exception
            );
        }
    }

    private function liveZoneContainsRecord($liveZone, $origin, $expectedRecord)
    {
        if(!$this->isLiveZone($liveZone, $origin)) {
            return false;
        }

        try {
            $expectedRecord = $this->recordNormalizer->normalizeProviderRecord(
                $expectedRecord,
                $origin
            );
        } catch (DbsRecordNormalizationException $exception) {
            return false;
        }

        foreach($liveZone['records'] as $record) {
            if(!is_array($record)) {
                continue;
            }

            try {
                $candidate = $this->recordNormalizer->normalizeProviderRecord($record, $origin);
            } catch (DbsRecordNormalizationException $exception) {
                continue;
            }

            if($candidate === $expectedRecord) {
                return true;
            }
        }

        return false;
    }

    private function assertLiveZone($liveZone, $origin)
    {
        if($this->isLiveZone($liveZone, $origin)) {
            return;
        }

        throw new DbsRecordException(
            'Der Providerzustand der DBS-Zone konnte nicht bestätigt werden.',
            self::ERROR_VERIFICATION_FAILED
        );
    }

    private function isLiveZone($liveZone, $origin)
    {
        return is_array($liveZone)
            && isset($liveZone['origin'], $liveZone['records'])
            && $this->recordNormalizer->normalizeOrigin($liveZone['origin']) === $origin
            && is_array($liveZone['records']);
    }

    private function createClient()
    {
        $client = $this->clientFactory === null
            ? new DbsClient()
            : call_user_func($this->clientFactory);

        if(
            !is_object($client) ||
            !method_exists($client, 'createRecord') ||
            !method_exists($client, 'deleteRecord') ||
            !method_exists($client, 'getZoneInfo')
        ) {
            throw new RuntimeException('Ungültiger DBS-Client.');
        }

        return $client;
    }

    private function throwInvalidRecord()
    {
        throw new DbsRecordException('Ungültiger DNS-Record.', self::ERROR_INVALID_RECORD);
    }
}
