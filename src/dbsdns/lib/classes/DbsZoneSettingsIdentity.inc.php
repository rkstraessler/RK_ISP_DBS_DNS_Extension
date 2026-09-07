<?php

class DbsZoneSettingsIdentityException extends RuntimeException
{
}

class DbsZoneSettingsIdentity
{
    const TOKEN_PREFIX = 'dbsz1_';
    const SESSION_KEY = 'dbsdns_zone_settings_identity_key_v1';
    const MAX_TOKEN_LENGTH = 2048;

    private $signingKey;

    public function __construct($signingKey = null)
    {
        if($signingKey === null) {
            if(
                !isset($_SESSION[self::SESSION_KEY])
                || !is_string($_SESSION[self::SESSION_KEY])
                || !preg_match('/\A[a-f0-9]{64}\z/', $_SESSION[self::SESSION_KEY])
            ) {
                $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
            }

            $signingKey = $_SESSION[self::SESSION_KEY];
        }

        if(!is_string($signingKey) || strlen($signingKey) < 32) {
            throw new DbsZoneSettingsIdentityException('Ungültiger Signaturschlüssel.');
        }

        $this->signingKey = $signingKey;
    }

    public function sign($cacheId, $origin, $settings)
    {
        $payload = json_encode(array(
            'v' => 1,
            'z' => $this->canonicalCacheId($cacheId),
            'o' => $this->canonicalOrigin($origin),
            's' => $this->canonicalSettings($settings)
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if(!is_string($payload)) {
            throw new DbsZoneSettingsIdentityException('Ungültige Zoneneinstellungen.');
        }

        $encodedPayload = $this->base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $encodedPayload, $this->signingKey, true);
        $token = self::TOKEN_PREFIX . $encodedPayload . '.' . $this->base64UrlEncode($signature);

        if(strlen($token) > self::MAX_TOKEN_LENGTH) {
            throw new DbsZoneSettingsIdentityException('Ungültige Zoneneinstellungen.');
        }

        return $token;
    }

    public function verify($token)
    {
        if(
            !is_string($token)
            || strlen($token) > self::MAX_TOKEN_LENGTH
            || strncmp($token, self::TOKEN_PREFIX, strlen(self::TOKEN_PREFIX)) !== 0
        ) {
            throw new DbsZoneSettingsIdentityException('Ungültiger Zonen-Identifier.');
        }

        $parts = explode('.', substr($token, strlen(self::TOKEN_PREFIX)));

        if(count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new DbsZoneSettingsIdentityException('Ungültiger Zonen-Identifier.');
        }

        $providedSignature = $this->base64UrlDecode($parts[1]);
        $expectedSignature = hash_hmac('sha256', $parts[0], $this->signingKey, true);

        if($providedSignature === false || !hash_equals($expectedSignature, $providedSignature)) {
            throw new DbsZoneSettingsIdentityException('Ungültiger Zonen-Identifier.');
        }

        $decodedPayload = $this->base64UrlDecode($parts[0]);
        $payload = $decodedPayload === false ? null : json_decode($decodedPayload, true);

        if(
            !is_array($payload)
            || array_keys($payload) !== array('v', 'z', 'o', 's')
            || $payload['v'] !== 1
        ) {
            throw new DbsZoneSettingsIdentityException('Ungültiger Zonen-Identifier.');
        }

        return array(
            'cache_id' => $this->canonicalCacheId($payload['z']),
            'origin' => $this->canonicalOrigin($payload['o']),
            'settings' => $this->canonicalSettings($payload['s'])
        );
    }

    private function canonicalCacheId($cacheId)
    {
        if(!is_int($cacheId) || $cacheId <= 0) {
            throw new DbsZoneSettingsIdentityException('Ungültige DBS-Zone.');
        }

        return $cacheId;
    }

    private function canonicalOrigin($origin)
    {
        if(!is_string($origin)) {
            throw new DbsZoneSettingsIdentityException('Ungültige DBS-Zone.');
        }

        $origin = strtolower(rtrim(trim($origin), '.'));

        if(
            $origin === ''
            || strlen($origin) > 253
            || !preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $origin)
        ) {
            throw new DbsZoneSettingsIdentityException('Ungültige DBS-Zone.');
        }

        return $origin;
    }

    private function canonicalSettings($settings)
    {
        $expectedKeys = array('mbox', 'refresh', 'retry', 'expire', 'minimum_ttl', 'ttl');

        if(!is_array($settings) || array_keys($settings) !== $expectedKeys) {
            throw new DbsZoneSettingsIdentityException('Ungültige Zoneneinstellungen.');
        }

        foreach($expectedKeys as $key) {
            if(!is_string($settings[$key])) {
                throw new DbsZoneSettingsIdentityException('Ungültige Zoneneinstellungen.');
            }
        }

        return $settings;
    }

    private function base64UrlEncode($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode($value)
    {
        if(!is_string($value) || !preg_match('/\A[A-Za-z0-9_-]+\z/', $value)) {
            return false;
        }

        $encodedValue = $value;
        $padding = strlen($value) % 4;

        if($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decodedValue = base64_decode(strtr($value, '-_', '+/'), true);

        if($decodedValue === false || $this->base64UrlEncode($decodedValue) !== $encodedValue) {
            return false;
        }

        return $decodedValue;
    }
}
