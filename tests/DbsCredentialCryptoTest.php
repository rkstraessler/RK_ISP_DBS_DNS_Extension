<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsSecretBox.inc.php';

function credentialCryptoAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

function credentialCryptoExpectFailure($callback, $secretMarker)
{
    try {
        call_user_func($callback);
    } catch (DbsCredentialException $exception) {
        credentialCryptoAssertTrue(
            strpos($exception->getMessage(), $secretMarker) === false,
            'Eine Crypto-Fehlermeldung enthält das Passwort.'
        );
        return;
    }

    throw new RuntimeException('Manipulierte oder falsch verschlüsselte Credentials wurden akzeptiert.');
}

$testFailure = null;
$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'dbsdns-credential-crypto-' . bin2hex(random_bytes(8));
$keyPath = $testRoot . DIRECTORY_SEPARATOR . 'credentials.key';
$wrongKeyPath = $testRoot . DIRECTORY_SEPARATOR . 'wrong.key';

try {
    credentialCryptoAssertTrue(DbsSecretBox::isAvailable(), 'PHP Sodium/XChaCha20-Poly1305 ist nicht verfügbar.');
    credentialCryptoAssertTrue(mkdir($testRoot, 0700, true), 'Temporäres Crypto-Verzeichnis konnte nicht erstellt werden.');
    credentialCryptoAssertTrue(file_put_contents($keyPath, random_bytes(32)) === 32, 'Testschlüssel konnte nicht erstellt werden.');
    credentialCryptoAssertTrue(file_put_contents($wrongKeyPath, random_bytes(32)) === 32, 'Falscher Testschlüssel konnte nicht erstellt werden.');

    $secretMarker = 'crypto-secret-marker-' . bin2hex(random_bytes(6));
    $wsdlUrl = 'https://soap.example.test/service.wsdl';
    $username = 'crypto-user';
    $box = new DbsSecretBox($keyPath);
    $firstEnvelope = $box->encrypt($secretMarker, $wsdlUrl, $username);
    $secondEnvelope = $box->encrypt($secretMarker, $wsdlUrl, $username);

    credentialCryptoAssertTrue(
        $firstEnvelope !== $secondEnvelope
        && strpos($firstEnvelope, $secretMarker) === false
        && strpos($secondEnvelope, $secretMarker) === false,
        'Nonce ist nicht zufällig oder das Passwort steht im Ciphertext.'
    );
    credentialCryptoAssertTrue(
        $box->decrypt($firstEnvelope, $wsdlUrl, $username) === $secretMarker
        && $box->decrypt($secondEnvelope, $wsdlUrl, $username) === $secretMarker,
        'AEAD-Roundtrip liefert nicht exakt das Passwort zurück.'
    );

    $parts = explode('.', $firstEnvelope);
    $tamperedCiphertext = base64_decode($parts[2], true);
    $tamperedCiphertext[0] = chr(ord($tamperedCiphertext[0]) ^ 1);
    $tamperedEnvelope = $parts[0] . '.' . $parts[1] . '.' . base64_encode($tamperedCiphertext);
    credentialCryptoExpectFailure(function() use ($box, $tamperedEnvelope, $wsdlUrl, $username) {
        $box->decrypt($tamperedEnvelope, $wsdlUrl, $username);
    }, $secretMarker);
    credentialCryptoExpectFailure(function() use ($firstEnvelope, $wrongKeyPath, $wsdlUrl, $username) {
        (new DbsSecretBox($wrongKeyPath))->decrypt($firstEnvelope, $wsdlUrl, $username);
    }, $secretMarker);
    credentialCryptoExpectFailure(function() use ($firstEnvelope, $wsdlUrl, $username) {
        (new DbsSecretBox(__DIR__ . '/missing-credential-key'))->decrypt($firstEnvelope, $wsdlUrl, $username);
    }, $secretMarker);
    credentialCryptoExpectFailure(function() use ($box, $firstEnvelope, $wsdlUrl, $username) {
        $box->decrypt('v2.' . substr($firstEnvelope, 3), $wsdlUrl, $username);
    }, $secretMarker);
    credentialCryptoExpectFailure(function() use ($box, $firstEnvelope, $username) {
        $box->decrypt($firstEnvelope, 'https://redirect.example.test/service.wsdl', $username);
    }, $secretMarker);
    credentialCryptoExpectFailure(function() use ($box, $firstEnvelope, $wsdlUrl) {
        $box->decrypt($firstEnvelope, $wsdlUrl, 'other-user');
    }, $secretMarker);

    $cryptoSource = file_get_contents(__DIR__ . '/../src/dbsdns/lib/classes/DbsSecretBox.inc.php');
    credentialCryptoAssertTrue(
        strpos($cryptoSource, 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt') !== false
        && strpos($cryptoSource, 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt') !== false
        && strpos($cryptoSource, 'buildAssociatedData') !== false
        && strpos($cryptoSource, 'strlen($wsdlUrl)') !== false
        && strpos($cryptoSource, 'strlen($username)') !== false
        && stripos($cryptoSource, 'openssl_encrypt') === false
        && stripos($cryptoSource, 'mcrypt') === false,
        'Die Secret-Speicherung verwendet nicht ausschließlich die vorgesehene authentifizierte Verschlüsselung.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
} finally {
    if(is_file($keyPath)) {
        unlink($keyPath);
    }
    if(is_file($wrongKeyPath)) {
        unlink($wrongKeyPath);
    }
    if(is_dir($testRoot)) {
        rmdir($testRoot);
    }
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Authentifizierte DBS-Credential-Verschlüsselung erfolgreich geprüft.' . PHP_EOL;
