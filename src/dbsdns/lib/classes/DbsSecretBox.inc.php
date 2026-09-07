<?php

require_once __DIR__ . '/DbsConfiguration.inc.php';

class DbsSecretBox
{
    const ENVELOPE_VERSION = 'v1';
    const ASSOCIATED_DATA = 'ispconfig-dbsdns-credentials:v1';

    private $keyPath;

    public function __construct($keyPath = null)
    {
        $this->keyPath = $keyPath === null ? self::defaultKeyPath() : (string)$keyPath;
    }

    public static function defaultKeyPath()
    {
        return dirname(__DIR__, 5)
            . DIRECTORY_SEPARATOR . 'security'
            . DIRECTORY_SEPARATOR . 'dbsdns'
            . DIRECTORY_SEPARATOR . 'credentials.key';
    }

    public static function isAvailable()
    {
        return function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
            && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')
            && function_exists('random_bytes');
    }

    public function encrypt($plaintext, $wsdlUrl, $username)
    {
        if(!DbsConfiguration::isValidPassword($plaintext)) {
            $this->throwSecretUnavailable();
        }

        $associatedData = $this->buildAssociatedData($wsdlUrl, $username);
        $key = $this->loadKey();

        try {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                $associatedData,
                $nonce,
                $key
            );
        } catch (Throwable $exception) {
            $this->forget($key);
            $this->throwSecretUnavailable();
        }

        $this->forget($key);

        return self::ENVELOPE_VERSION
            . '.' . base64_encode($nonce)
            . '.' . base64_encode($ciphertext);
    }

    public function decrypt($envelope, $wsdlUrl, $username)
    {
        if(!self::isAvailable() || !is_string($envelope) || strlen($envelope) > 16384) {
            $this->throwSecretUnavailable();
        }

        $parts = explode('.', $envelope);

        if(count($parts) !== 3 || $parts[0] !== self::ENVELOPE_VERSION) {
            $this->throwSecretUnavailable();
        }

        $nonce = base64_decode($parts[1], true);
        $ciphertext = base64_decode($parts[2], true);

        if(
            !is_string($nonce)
            || strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            || !is_string($ciphertext)
            || strlen($ciphertext) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES
        ) {
            $this->throwSecretUnavailable();
        }

        $associatedData = $this->buildAssociatedData($wsdlUrl, $username);
        $key = $this->loadKey();

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $ciphertext,
                $associatedData,
                $nonce,
                $key
            );
        } catch (Throwable $exception) {
            $this->forget($key);
            $this->throwSecretUnavailable();
        }

        $this->forget($key);

        if(!DbsConfiguration::isValidPassword($plaintext)) {
            $this->throwSecretUnavailable();
        }

        return $plaintext;
    }

    private function buildAssociatedData($wsdlUrl, $username)
    {
        $wsdlUrl = DbsConfiguration::normalizeWsdlUrl($wsdlUrl);
        $username = DbsConfiguration::normalizeUsername($username);

        if($wsdlUrl === false || $username === false) {
            $this->throwSecretUnavailable();
        }

        return self::ASSOCIATED_DATA
            . "\0" . strlen($wsdlUrl) . ':' . $wsdlUrl
            . "\0" . strlen($username) . ':' . $username;
    }

    private function loadKey()
    {
        if(!self::isAvailable() || $this->keyPath === '' || !is_file($this->keyPath) || !is_readable($this->keyPath)) {
            $this->throwSecretUnavailable();
        }

        $key = @file_get_contents($this->keyPath);

        if(!is_string($key) || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            $this->throwSecretUnavailable();
        }

        return $key;
    }

    private function forget(&$value)
    {
        if(function_exists('sodium_memzero') && is_string($value)) {
            sodium_memzero($value);
        }

        $value = null;
    }

    private function throwSecretUnavailable()
    {
        throw new DbsCredentialException(
            'DBS-Zugangsdaten konnten nicht sicher verarbeitet werden.',
            DbsCredentialException::ERROR_SECRET_UNAVAILABLE
        );
    }
}
