<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsSettingsService.inc.php';

function settingsServiceAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

function settingsServiceExpectCode($callback, $expectedCode)
{
    try {
        call_user_func($callback);
    } catch (RuntimeException $exception) {
        settingsServiceAssertTrue($exception->getCode() === $expectedCode, 'Unerwarteter Settings-Fehlercode.');
        return;
    }

    throw new RuntimeException('Erwarteter Settings-Fehler wurde nicht ausgelöst.');
}

class DbsSettingsServiceFakeDb
{
    public $errorMessage = '';
    public $record;
    public $queries = array();

    public function queryOneRecord($query)
    {
        return $this->record;
    }

    public function query($query)
    {
        $arguments = func_get_args();
        $this->queries[] = $query;

        if(strpos($query, 'password_ciphertext = IF') !== false) {
            $existingCiphertext = is_array($this->record)
                ? $this->record['password_ciphertext']
                : '';
            $newCiphertext = (string)$arguments[4];
            $this->record = array(
                'settings_id' => 1,
                'wsdl_url' => (string)$arguments[2],
                'username' => (string)$arguments[3],
                'password_ciphertext' => $newCiphertext === '' ? $existingCiphertext : $newCiphertext,
                'connection_status' => (string)$arguments[5],
                'connection_source' => '',
                'last_tested_at' => '',
                'updated_at' => '2026-08-20 12:00:00'
            );
            return true;
        }

        if(strpos($query, 'connection_source = VALUES(connection_source)') !== false) {
            if(!is_array($this->record)) {
                $this->record = array(
                    'settings_id' => 1,
                    'wsdl_url' => '',
                    'username' => '',
                    'password_ciphertext' => '',
                    'updated_at' => '2026-08-20 12:00:00'
                );
            }

            $this->record['connection_status'] = (string)$arguments[2];
            $this->record['connection_source'] = (string)$arguments[3];
            $this->record['last_tested_at'] = '2026-08-20 12:01:00';
            return true;
        }

        $this->errorMessage = 'unexpected query';
        return false;
    }
}

class DbsSettingsServiceFakeSecretBox
{
    public $counter = 0;
    public $plaintextByCiphertext = array();
    public $contextByCiphertext = array();

    public function encrypt($plaintext, $wsdlUrl = '', $username = '')
    {
        $this->counter++;
        $ciphertext = 'v1.test.' . $this->counter;
        $this->plaintextByCiphertext[$ciphertext] = $plaintext;
        $this->contextByCiphertext[$ciphertext] = array($wsdlUrl, $username);
        return $ciphertext;
    }

    public function decrypt($ciphertext, $wsdlUrl = '', $username = '')
    {
        if(
            !isset($this->plaintextByCiphertext[$ciphertext], $this->contextByCiphertext[$ciphertext])
            || $this->contextByCiphertext[$ciphertext] !== array($wsdlUrl, $username)
        ) {
            throw new DbsCredentialException('Secret unavailable.', DbsCredentialException::ERROR_SECRET_UNAVAILABLE);
        }

        return $this->plaintextByCiphertext[$ciphertext];
    }
}

$testFailure = null;

try {
    $environment = array();
    $environmentReader = function($name) use (&$environment) {
        return array_key_exists($name, $environment) ? $environment[$name] : false;
    };
    $db = new DbsSettingsServiceFakeDb();
    $secretBox = new DbsSettingsServiceFakeSecretBox();
    $service = new DbsSettingsService($db, $secretBox, $environmentReader);

    settingsServiceExpectCode(function() use ($service) {
        $service->save('https://soap.example.test/service.wsdl', 'admin-user', '');
    }, DbsSettingsService::ERROR_PASSWORD_REQUIRED);
    settingsServiceExpectCode(function() use ($service) {
        $service->save('https://soap.example.test/service.wsdl', 'admin-user', " \t ");
    }, DbsSettingsService::ERROR_INVALID_INPUT);

    $firstSecret = 'first-secret-marker';
    $service->save('https://soap.example.test/service.wsdl', 'admin-user', $firstSecret);
    $firstCiphertext = $db->record['password_ciphertext'];
    settingsServiceAssertTrue(
        $firstCiphertext !== ''
        && $firstCiphertext !== $firstSecret
        && strpos($firstCiphertext, $firstSecret) === false,
        'Das erste Passwort wurde nicht ausschließlich als Ciphertext gespeichert.'
    );

    $state = $service->getFormState();
    settingsServiceAssertTrue(
        $state['source'] === DbsConfiguration::SOURCE_WEB
        && $state['password_configured'] === true
        && !array_key_exists('password', $state)
        && !array_key_exists('password_ciphertext', $state),
        'Die Formansicht gibt das Passwort oder den Ciphertext zurück.'
    );

    $service->save('https://soap.example.test/service.wsdl', 'admin-user', '');
    settingsServiceAssertTrue(
        $db->record['password_ciphertext'] === $firstCiphertext,
        'Ein leeres Passwortfeld hat ein unverändertes, gültiges Web-Secret ersetzt.'
    );

    settingsServiceExpectCode(function() use ($service) {
        $service->save('https://soap.example.test/changed.wsdl', 'changed-user', '');
    }, DbsSettingsService::ERROR_PASSWORD_REQUIRED);
    settingsServiceAssertTrue(
        $db->record['password_ciphertext'] === $firstCiphertext
        && $db->record['wsdl_url'] === 'https://soap.example.test/service.wsdl'
        && $db->record['username'] === 'admin-user',
        'Geänderte Identitätsfelder wurden ohne neues Passwort gespeichert.'
    );

    $secondSecret = 'second-secret-marker';
    $service->save('https://soap.example.test/changed.wsdl', 'changed-user', $secondSecret);
    $secondCiphertext = $db->record['password_ciphertext'];
    settingsServiceAssertTrue(
        $secondCiphertext !== $firstCiphertext
        && strpos($secondCiphertext, $secondSecret) === false,
        'Credential-Rotation ersetzt den alten Ciphertext nicht sicher.'
    );

    $storedTest = $service->buildTestConfiguration(
        'https://soap.example.test/changed.wsdl',
        'changed-user',
        ''
    );
    settingsServiceAssertTrue(
        $storedTest['configuration']->getPassword() === $secondSecret
        && $storedTest['persist_source'] === DbsConfiguration::SOURCE_WEB,
        'Der Verbindungstest verwendet nicht das serverseitig gespeicherte Secret.'
    );

    settingsServiceExpectCode(function() use ($service) {
        $service->buildTestConfiguration(
            'https://candidate.example.test/service.wsdl',
            'candidate-user',
            ''
        );
    }, DbsSettingsService::ERROR_PASSWORD_REQUIRED);

    $requestTest = $service->buildTestConfiguration(
        'https://request.example.test/service.wsdl',
        'request-user',
        'request-only-secret'
    );
    settingsServiceAssertTrue(
        $requestTest['configuration']->getSource() === DbsConfiguration::SOURCE_REQUEST
        && $requestTest['persist_source'] === ''
        && $db->record['password_ciphertext'] === $secondCiphertext,
        'Ein Test mit aktuell eingegebenen Credentials persistiert sie unerwartet.'
    );

    $service->saveTestResult(false, DbsConfiguration::SOURCE_WEB);
    $failedState = $service->getFormState();
    settingsServiceAssertTrue(
        $failedState['connection_status'] === DbsSettingsRepository::STATUS_FAILURE,
        'Ein fehlgeschlagener Verbindungstest wird nicht secretsfrei als Status gespeichert.'
    );

    $validCiphertext = $db->record['password_ciphertext'];
    $db->record['password_ciphertext'] = 'v1.invalid.ciphertext';
    $invalidState = $service->getFormState();
    settingsServiceAssertTrue(
        $invalidState['configured'] === false
        && $invalidState['configuration_invalid'] === true
        && $invalidState['connection_status'] === DbsSettingsService::STATUS_INVALID
        && !array_key_exists('password', $invalidState),
        'Ein nicht entschlüsselbares Web-Secret erzeugt keinen klaren sicheren Invalid-Status.'
    );

    $invalidBlankSaveRejected = false;

    try {
        $service->save('https://soap.example.test/changed.wsdl', 'changed-user', '');
    } catch (DbsCredentialException $exception) {
        $invalidBlankSaveRejected = true;
    }

    settingsServiceAssertTrue(
        $invalidBlankSaveRejected,
        'Ein beschädigtes gespeichertes Secret wird bei leerem Passwortfeld ungeprüft bestätigt.'
    );
    $db->record['password_ciphertext'] = $validCiphertext;

    $environment = array(
        DbsCredentialProvider::ENV_WSDL_URL => 'https://legacy.example.test/service.wsdl',
        DbsCredentialProvider::ENV_USERNAME => 'legacy-user',
        DbsCredentialProvider::ENV_PASSWORD => 'legacy-secret-marker'
    );
    $legacyDb = new DbsSettingsServiceFakeDb();
    $legacyService = new DbsSettingsService($legacyDb, $secretBox, $environmentReader);
    $legacyState = $legacyService->getFormState();
    $legacyTest = $legacyService->buildTestConfiguration(
        $legacyState['wsdl_url'],
        $legacyState['username'],
        ''
    );
    settingsServiceAssertTrue(
        $legacyState['source'] === DbsConfiguration::SOURCE_ENVIRONMENT
        && $legacyState['password_configured'] === false
        && $legacyTest['configuration']->getPassword() === 'legacy-secret-marker'
        && $legacyTest['persist_source'] === DbsConfiguration::SOURCE_ENVIRONMENT,
        'Die Legacy-ENV-Konfiguration kann nicht ohne Passwortausgabe getestet werden.'
    );
    settingsServiceExpectCode(function() use ($legacyService, $legacyState) {
        $legacyService->buildTestConfiguration(
            $legacyState['wsdl_url'],
            'changed-user',
            ''
        );
    }, DbsSettingsService::ERROR_PASSWORD_REQUIRED);

    $repositorySource = file_get_contents(__DIR__ . '/../src/dbsdns/lib/classes/DbsSettingsRepository.inc.php');
    settingsServiceAssertTrue(
        strpos($repositorySource, 'password_ciphertext = IF') !== false
        && stripos($repositorySource, 'session') === false
        && stripos($repositorySource, 'redis') === false
        && stripos($repositorySource, 'apcu') === false,
        'Blank-Passwort-Erhalt ist nicht atomar oder Klartext wird dauerhaft gecacht.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Admin-Credential-Speichern, Rotation und Blank-Passwort-Erhalt erfolgreich geprüft.' . PHP_EOL;
