<?php

require_once __DIR__ . '/DbsRecordNormalizer.inc.php';

class DbsRecordIdentityException extends RuntimeException
{
}

class DbsRecordIdentity
{
    const TOKEN_PREFIX = 'dbs1_';
    const SESSION_KEY = 'dbsdns_record_identity_key_v1';
    const MAX_TOKEN_LENGTH = 2048;

    private $signingKey;
    private $recordNormalizer;

    public function __construct($signingKey = null, $recordNormalizer = null)
    {
        if($signingKey === null) {
            if(
                !isset($_SESSION[self::SESSION_KEY]) ||
                !is_string($_SESSION[self::SESSION_KEY]) ||
                !preg_match('/\A[a-f0-9]{64}\z/', $_SESSION[self::SESSION_KEY])
            ) {
                $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
            }

            $signingKey = $_SESSION[self::SESSION_KEY];
        }

        if(!is_string($signingKey) || strlen($signingKey) < 32) {
            throw new DbsRecordIdentityException('Ungültiger Signaturschlüssel.');
        }

        $this->signingKey = $signingKey;
        $this->recordNormalizer = $recordNormalizer === null
            ? new DbsRecordNormalizer()
            : $recordNormalizer;

        if(!is_object($this->recordNormalizer) || !method_exists($this->recordNormalizer, 'normalizeCanonicalProviderRecord')) {
            throw new DbsRecordIdentityException('Ungültige Record-Normalisierung.');
        }
    }

    public function sign($cacheId, $record)
    {
        $cacheId = (int)$cacheId;

        if($cacheId <= 0) {
            throw new DbsRecordIdentityException('Ungültige virtuelle Zone.');
        }

        $record = $this->canonicalRecord($record);
        $payload = json_encode(array(
            'v' => 1,
            'z' => $cacheId,
            'r' => $record
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if(!is_string($payload)) {
            throw new DbsRecordIdentityException('Ungültiger DNS-Record.');
        }

        $encodedPayload = $this->base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $encodedPayload, $this->signingKey, true);

        $token = self::TOKEN_PREFIX . $encodedPayload . '.' . $this->base64UrlEncode($signature);

        if(strlen($token) > self::MAX_TOKEN_LENGTH) {
            throw new DbsRecordIdentityException('Ungültiger DNS-Record.');
        }

        return $token;
    }

    public function verify($token)
    {
        if(
            !is_string($token) ||
            strlen($token) > self::MAX_TOKEN_LENGTH ||
            strncmp($token, self::TOKEN_PREFIX, strlen(self::TOKEN_PREFIX)) !== 0
        ) {
            throw new DbsRecordIdentityException('Ungültiger Record-Identifier.');
        }

        $parts = explode('.', substr($token, strlen(self::TOKEN_PREFIX)));

        if(count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new DbsRecordIdentityException('Ungültiger Record-Identifier.');
        }

        $providedSignature = $this->base64UrlDecode($parts[1]);
        $expectedSignature = hash_hmac('sha256', $parts[0], $this->signingKey, true);

        if($providedSignature === false || !hash_equals($expectedSignature, $providedSignature)) {
            throw new DbsRecordIdentityException('Ungültiger Record-Identifier.');
        }

        $decodedPayload = $this->base64UrlDecode($parts[0]);
        $payload = $decodedPayload === false ? null : json_decode($decodedPayload, true);

        if(
            !is_array($payload) ||
            array_keys($payload) !== array('v', 'z', 'r') ||
            $payload['v'] !== 1 ||
            !is_int($payload['z']) ||
            $payload['z'] <= 0
        ) {
            throw new DbsRecordIdentityException('Ungültiger Record-Identifier.');
        }

        return array(
            'cache_id' => $payload['z'],
            'record' => $this->canonicalRecord($payload['r'])
        );
    }

    private function canonicalRecord($record)
    {
        try {
            return $this->recordNormalizer->normalizeCanonicalProviderRecord($record);
        } catch (DbsRecordNormalizationException $exception) {
            throw new DbsRecordIdentityException('Ungültiger DNS-Record.');
        }
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
