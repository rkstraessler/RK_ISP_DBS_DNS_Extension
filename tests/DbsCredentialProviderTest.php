<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsCredentialProvider.inc.php';

function credentialProviderAssertSame($expected, $actual, $message)
{
    if($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function credentialProviderExpectFailure($callback, $secretMarker, $expectedCode = null)
{
    try {
        call_user_func($callback);
    } catch (DbsCredentialException $exception) {
        credentialProviderAssertSame(
            false,
            strpos($exception->getMessage(), $secretMarker) !== false,
            'Eine Provider-Fehlermeldung enthält ein Credential.'
        );

        if($expectedCode !== null) {
            credentialProviderAssertSame($expectedCode, $exception->getCode(), 'Eine Provider-Fehlerklasse wurde verschluckt.');
        }

        return;
    }

    throw new RuntimeException('Eine fehlende oder beschädigte Konfiguration wurde akzeptiert.');
}

class DbsCredentialProviderFakeDb
{
    public $errorMessage = '';
    public $record;

    public function queryOneRecord($query)
    {
        return $this->record;
    }

    public function query($query)
    {
        return true;
    }
}

class DbsCredentialProviderFakeSecretBox
{
    public $decryptCalls = 0;
    public $lastContext = array();
    public $fail = false;

    public function decrypt($ciphertext, $wsdlUrl = '', $username = '')
    {
        $this->decryptCalls++;
        $this->lastContext = array($wsdlUrl, $username);

        if($this->fail) {
            throw new DbsCredentialException(
                'DBS-Zugangsdaten konnten nicht sicher verarbeitet werden.',
                DbsCredentialException::ERROR_SECRET_UNAVAILABLE
            );
        }

        return 'web-password';
    }
}

function credentialProviderEnvironmentReader(&$values)
{
    return function($name) use (&$values) {
        return array_key_exists($name, $values) ? $values[$name] : false;
    };
}

$testFailure = null;

try {
    $environment = array(
        DbsCredentialProvider::ENV_WSDL_URL => 'https://legacy.example.test/soap.wsdl',
        DbsCredentialProvider::ENV_USERNAME => 'legacy-user',
        DbsCredentialProvider::ENV_PASSWORD => 'legacy-password'
    );
    $db = new DbsCredentialProviderFakeDb();
    $db->record = array(
        'settings_id' => 1,
        'wsdl_url' => 'https://web.example.test/soap.wsdl',
        'username' => 'web-user',
        'password_ciphertext' => 'v1.fake.ciphertext',
        'connection_status' => 'untested',
        'connection_source' => '',
        'last_tested_at' => '',
        'updated_at' => ''
    );
    $secretBox = new DbsCredentialProviderFakeSecretBox();
    $provider = new DbsCredentialProvider(
        $db,
        $secretBox,
        credentialProviderEnvironmentReader($environment)
    );
    $resolved = $provider->resolve();

    credentialProviderAssertSame(DbsConfiguration::SOURCE_WEB, $resolved->getSource(), 'Webkonfiguration hat keinen Vorrang vor ENV.');
    credentialProviderAssertSame('https://web.example.test/soap.wsdl', $resolved->getWsdlUrl(), 'Falsche Web-WSDL wurde aufgelöst.');
    credentialProviderAssertSame('web-user', $resolved->getUsername(), 'Falscher Web-Benutzer wurde aufgelöst.');
    credentialProviderAssertSame('web-password', $resolved->getPassword(), 'Web-Ciphertext wurde nicht requestlokal entschlüsselt.');
    credentialProviderAssertSame(1, $secretBox->decryptCalls, 'Web-Secret wurde mehr als einmal je Auflösung entschlüsselt.');
    credentialProviderAssertSame(
        array('https://web.example.test/soap.wsdl', 'web-user'),
        $secretBox->lastContext,
        'Der Provider bindet die Entschlüsselung nicht an WSDL und Benutzername.'
    );

    $secretBox->decryptCalls = 0;
    $metadata = $provider->getMetadata();
    credentialProviderAssertSame(DbsConfiguration::SOURCE_WEB, $metadata['source'], 'Web-Metadaten melden die falsche Quelle.');
    credentialProviderAssertSame(false, $metadata['invalid'], 'Eine lesbare Webkonfiguration wird als ungültig gemeldet.');
    credentialProviderAssertSame(1, $secretBox->decryptCalls, 'Der sichere Status prüft den Ciphertext nicht genau einmal.');

    $db->record['connection_status'] = DbsSettingsRepository::STATUS_SUCCESS;
    $db->record['connection_source'] = DbsConfiguration::SOURCE_WEB;
    credentialProviderAssertSame(true, $provider->isCustomerConfigurationAvailable(), 'Ein erfolgreicher Web-Verbindungstest gibt die Konfiguration nicht frei.');
    $db->record['connection_status'] = DbsSettingsRepository::STATUS_FAILURE;
    credentialProviderAssertSame(false, $provider->isCustomerConfigurationAvailable(), 'Eine fehlgeschlagene Verbindung bleibt für Kunden freigegeben.');

    $db->record['password_ciphertext'] = '';
    $legacy = $provider->resolve();
    credentialProviderAssertSame(DbsConfiguration::SOURCE_ENVIRONMENT, $legacy->getSource(), 'Unvollständige Webdaten verhindern den ENV-Fallback.');
    credentialProviderAssertSame('legacy-password', $legacy->getPassword(), 'ENV-Fallback liefert nicht das Legacy-Secret.');

    $environment = array();
    credentialProviderExpectFailure(function() use ($provider) {
        $provider->resolve();
    }, 'legacy-password');

    $environment = array(
        DbsCredentialProvider::ENV_WSDL_URL => 'https://legacy.example.test/soap.wsdl',
        DbsCredentialProvider::ENV_USERNAME => 'legacy-user',
        DbsCredentialProvider::ENV_PASSWORD => 'legacy-password'
    );
    $db->record['password_ciphertext'] = 'v1.manipulated.ciphertext';
    $secretBox->fail = true;
    credentialProviderExpectFailure(function() use ($provider) {
        $provider->resolve();
    }, 'legacy-password');
    $invalidMetadata = $provider->getMetadata();
    credentialProviderAssertSame(true, $invalidMetadata['invalid'], 'Manipulierter Ciphertext wird in der Statusansicht nicht erkannt.');
    credentialProviderAssertSame(false, $invalidMetadata['configured'], 'Manipulierter Ciphertext wird als konfiguriert gemeldet.');

    $db->record = null;
    $secretBox->fail = false;
    $legacyWithoutWebRow = $provider->resolve();
    credentialProviderAssertSame(
        DbsConfiguration::SOURCE_ENVIRONMENT,
        $legacyWithoutWebRow->getSource(),
        'Eine bestehende Legacy-Installation ohne Settings-Zeile funktioniert nicht.'
    );
    credentialProviderAssertSame(
        true,
        $provider->isCustomerConfigurationAvailable(),
        'Eine vollständige Legacy-ENV-Konfiguration wird beim Upgrade ohne früheren Fehltest gesperrt.'
    );

    $db->record = array(
        'settings_id' => 1,
        'wsdl_url' => '',
        'username' => '',
        'password_ciphertext' => '',
        'connection_status' => DbsSettingsRepository::STATUS_FAILURE,
        'connection_source' => DbsConfiguration::SOURCE_ENVIRONMENT,
        'last_tested_at' => '2026-08-20 12:00:00',
        'updated_at' => '2026-08-20 12:00:00'
    );
    credentialProviderAssertSame(
        false,
        $provider->isCustomerConfigurationAvailable(),
        'Ein explizit fehlgeschlagener Legacy-ENV-Test wird für Kunden ignoriert.'
    );
    $db->record = null;

    $db->errorMessage = 'database unavailable';
    credentialProviderExpectFailure(function() use ($provider) {
        $provider->resolve();
    }, 'legacy-password', DbsCredentialException::ERROR_DATABASE);
    $db->errorMessage = '';

    $environment[DbsCredentialProvider::ENV_WSDL_URL] = 'http://insecure.example.test/soap.wsdl';
    credentialProviderExpectFailure(function() use ($provider) {
        $provider->resolve();
    }, 'legacy-password');

    $environment = array(
        DbsCredentialProvider::ENV_WSDL_URL => 'https://legacy.example.test/soap.wsdl',
        DbsCredentialProvider::ENV_USERNAME => 'legacy-user',
        DbsCredentialProvider::ENV_PASSWORD => " \t "
    );
    credentialProviderExpectFailure(function() use ($provider) {
        $provider->resolve();
    }, 'legacy-password');
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Webkonfigurations-Priorität und Legacy-ENV-Fallback erfolgreich geprüft.' . PHP_EOL;
