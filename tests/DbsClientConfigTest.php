<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsClient.inc.php';

function assertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

function assertConfigurationException($callback)
{
    try {
        call_user_func($callback);
    } catch (DbsConfigurationException $exception) {
        return $exception;
    }

    throw new RuntimeException('Erwartete DbsConfigurationException wurde nicht ausgelöst.');
}

$environmentVariables = array(
    DbsCredentialProvider::ENV_WSDL_URL,
    DbsCredentialProvider::ENV_USERNAME,
    DbsCredentialProvider::ENV_PASSWORD
);
$originalEnvironment = array();
$testFailure = null;

foreach($environmentVariables as $environmentVariable) {
    $originalEnvironment[$environmentVariable] = getenv($environmentVariable);
}

try {
    foreach($environmentVariables as $environmentVariable) {
        putenv($environmentVariable);
    }

    $exception = assertConfigurationException(function() {
        new DbsClient();
    });
    assertTrue($exception->getMessage() === 'DBS-Konfiguration unvollständig.', 'Fehlende Variablen liefern keine sichere Konfigurationsmeldung.');

    $secretMarker = 'must-not-appear-in-errors';
    putenv(DbsCredentialProvider::ENV_WSDL_URL . '=https://soap.domain-bestellsystem.de/soap.wsdl');
    putenv(DbsCredentialProvider::ENV_USERNAME . '=' . $secretMarker);
    putenv(DbsCredentialProvider::ENV_PASSWORD);

    $exception = assertConfigurationException(function() {
        new DbsClient();
    });
    assertTrue(strpos($exception->getMessage(), $secretMarker) === false, 'Ein Secret erscheint in der Fehlermeldung.');

    putenv(DbsCredentialProvider::ENV_WSDL_URL . '=invalid-wsdl-location');
    putenv(DbsCredentialProvider::ENV_PASSWORD . '=' . $secretMarker);

    $exception = assertConfigurationException(function() {
        new DbsClient();
    });
    assertTrue($exception->getMessage() === 'DBS-Konfiguration unvollständig.', 'Eine ungültige WSDL-Konfiguration wird nicht abgelehnt.');
    assertTrue(strpos($exception->getMessage(), $secretMarker) === false, 'Ein Secret erscheint in der Validierungsmeldung.');
} catch (Throwable $exception) {
    $testFailure = $exception;
} finally {
    foreach($originalEnvironment as $environmentVariable => $value) {
        if($value === false) {
            putenv($environmentVariable);
        } else {
            putenv($environmentVariable . '=' . $value);
        }
    }
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'DbsClient-Konfigurationstests erfolgreich.' . PHP_EOL;
