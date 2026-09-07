<?php

require_once __DIR__ . '/DbsClient.inc.php';
require_once __DIR__ . '/DbsZoneSettingsIdentity.inc.php';

class DbsZoneSettingsException extends RuntimeException
{
}

class DbsZoneSettingsService
{
    const ERROR_ACCESS_DENIED = 12001;
    const ERROR_INVALID_ZONE = 12002;
    const ERROR_INVALID_SETTINGS = 12003;
    const ERROR_VERIFICATION_FAILED = 12004;
    const ERROR_INVALID_IDENTIFIER = 12005;
    const ERROR_STALE_SETTINGS = 12006;

    private $zoneAccess;
    private $settingsIdentity;
    private $clientFactory;

    public function __construct(
        $zoneAccess,
        DbsZoneSettingsIdentity $settingsIdentity,
        $clientFactory = null
    )
    {
        if(!is_object($zoneAccess) || !method_exists($zoneAccess, 'getAccessibleZoneByCacheId')) {
            throw new InvalidArgumentException('Ungültige DBS-Zugriffsprüfung.');
        }

        if($clientFactory !== null && !is_callable($clientFactory)) {
            throw new InvalidArgumentException('Ungültige DBS-Client-Factory.');
        }

        $this->zoneAccess = $zoneAccess;
        $this->settingsIdentity = $settingsIdentity;
        $this->clientFactory = $clientFactory;
    }

    public function update($context, $cacheId, $settingsToken, $settings)
    {
        $cacheId = $this->normalizeCacheId($cacheId);
        $snapshot = $this->verifySettingsIdentity($settingsToken);

        if($snapshot['cache_id'] !== $cacheId) {
            throw new DbsZoneSettingsException(
                'Der DBS-Zonen-Identifier ist ungültig.',
                self::ERROR_INVALID_IDENTIFIER
            );
        }

        $zone = $this->resolveAccessibleZone($context, $cacheId);

        if($snapshot['origin'] !== $zone['normalized_domain']) {
            throw new DbsZoneSettingsException(
                'Der DBS-Zonen-Identifier ist ungültig.',
                self::ERROR_INVALID_IDENTIFIER
            );
        }

        $settings = $this->validateSettings($settings);
        $client = $this->createClient();
        $currentZone = $client->getZoneInfo($zone['normalized_domain']);
        $currentSettings = $this->settingsFromZone($currentZone, $zone['normalized_domain']);

        if($currentSettings !== $snapshot['settings']) {
            throw new DbsZoneSettingsException(
                'Die DBS-Zoneneinstellungen wurden zwischenzeitlich geändert.',
                self::ERROR_STALE_SETTINGS
            );
        }

        if($currentSettings === $settings) {
            return array(
                'cache_id' => $cacheId,
                'origin' => $zone['normalized_domain'],
                'settings' => $currentSettings,
                'zone' => $currentZone,
                'changed' => false
            );
        }

        $client->updateZoneSettings($zone['normalized_domain'], $settings);
        $updatedZone = $client->getZoneInfo($zone['normalized_domain']);
        $updatedSettings = $this->settingsFromZone($updatedZone, $zone['normalized_domain']);

        if($updatedSettings !== $settings) {
            throw new DbsZoneSettingsException(
                'Die gespeicherten Zoneneinstellungen konnten beim Provider nicht bestätigt werden.',
                self::ERROR_VERIFICATION_FAILED
            );
        }

        return array(
            'cache_id' => $cacheId,
            'origin' => $zone['normalized_domain'],
            'settings' => $updatedSettings,
            'zone' => $updatedZone,
            'changed' => true
        );
    }

    private function verifySettingsIdentity($token)
    {
        try {
            return $this->settingsIdentity->verify($token);
        } catch (DbsZoneSettingsIdentityException $exception) {
            throw new DbsZoneSettingsException(
                'Der DBS-Zonen-Identifier ist ungültig.',
                self::ERROR_INVALID_IDENTIFIER
            );
        }
    }

    private function normalizeCacheId($cacheId)
    {
        if(is_int($cacheId)) {
            $normalized = $cacheId;
        } elseif(is_string($cacheId) && preg_match('/\A[1-9][0-9]*\z/', $cacheId)) {
            $normalized = (int)$cacheId;

            if((string)$normalized !== $cacheId) {
                throw new DbsZoneSettingsException('Ungültige DBS-Zone.', self::ERROR_INVALID_ZONE);
            }
        } else {
            throw new DbsZoneSettingsException('Ungültige DBS-Zone.', self::ERROR_INVALID_ZONE);
        }

        if($normalized <= 0) {
            throw new DbsZoneSettingsException('Ungültige DBS-Zone.', self::ERROR_INVALID_ZONE);
        }

        return $normalized;
    }

    private function resolveAccessibleZone($context, $cacheId)
    {
        $zone = $this->zoneAccess->getAccessibleZoneByCacheId($context, $cacheId);

        if(
            !is_array($zone)
            || !isset($zone['cache_id'], $zone['normalized_domain'])
            || (int)$zone['cache_id'] !== $cacheId
        ) {
            throw new DbsZoneSettingsException(
                'Kein Zugriff auf diese DBS-Zone.',
                self::ERROR_ACCESS_DENIED
            );
        }

        $origin = $this->normalizeOrigin($zone['normalized_domain']);

        if($origin === false) {
            throw new DbsZoneSettingsException(
                'Kein Zugriff auf diese DBS-Zone.',
                self::ERROR_ACCESS_DENIED
            );
        }

        $zone['normalized_domain'] = $origin;

        return $zone;
    }

    private function validateSettings($settings)
    {
        $expectedKeys = array('mbox', 'refresh', 'retry', 'expire', 'minimum_ttl', 'ttl');

        if(!is_array($settings) || array_keys($settings) !== $expectedKeys) {
            $this->throwInvalidSettings();
        }

        $normalized = array();

        foreach($expectedKeys as $key) {
            if(!is_string($settings[$key])) {
                $this->throwInvalidSettings();
            }

            $normalized[$key] = trim($settings[$key]);
        }

        if(
            $normalized['mbox'] === ''
            || strlen($normalized['mbox']) > 254
            || preg_match('/[\x00-\x20\x7f]/', $normalized['mbox'])
        ) {
            $this->throwInvalidSettings();
        }

        foreach(array('refresh', 'retry', 'expire', 'minimum_ttl', 'ttl') as $key) {
            if(!$this->validUnsignedInteger($normalized[$key], 0, 2147483647)) {
                $this->throwInvalidSettings();
            }
        }

        return $normalized;
    }

    private function settingsFromZone($zone, $expectedOrigin)
    {
        if(
            !is_array($zone)
            || !isset(
                $zone['origin'],
                $zone['mbox'],
                $zone['refresh'],
                $zone['retry'],
                $zone['expire'],
                $zone['minimum_ttl'],
                $zone['ttl']
            )
            || $this->normalizeOrigin($zone['origin']) !== $expectedOrigin
        ) {
            throw new DbsZoneSettingsException(
                'Das Domain-Bestellsystem hat ungültige Zoneneinstellungen geliefert.',
                self::ERROR_VERIFICATION_FAILED
            );
        }

        return $this->validateSettings(array(
            'mbox' => (string)$zone['mbox'],
            'refresh' => (string)$zone['refresh'],
            'retry' => (string)$zone['retry'],
            'expire' => (string)$zone['expire'],
            'minimum_ttl' => (string)$zone['minimum_ttl'],
            'ttl' => (string)$zone['ttl']
        ));
    }

    private function createClient()
    {
        $client = $this->clientFactory === null
            ? new DbsClient()
            : call_user_func($this->clientFactory);

        if(
            !is_object($client)
            || !method_exists($client, 'getZoneInfo')
            || !method_exists($client, 'updateZoneSettings')
        ) {
            throw new RuntimeException('Ungültiger DBS-Client.');
        }

        return $client;
    }

    private function normalizeOrigin($origin)
    {
        if(!is_string($origin)) {
            return false;
        }

        $origin = strtolower(rtrim(trim($origin), '.'));

        if(
            $origin === ''
            || strlen($origin) > 253
            || !preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $origin)
        ) {
            return false;
        }

        return $origin;
    }

    private function validUnsignedInteger($value, $minimum, $maximum)
    {
        return is_string($value)
            && preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/', $value) === 1
            && (int)$value >= $minimum
            && (int)$value <= $maximum;
    }

    private function throwInvalidSettings()
    {
        throw new DbsZoneSettingsException(
            'Ungültige DBS-Zoneneinstellungen.',
            self::ERROR_INVALID_SETTINGS
        );
    }
}
