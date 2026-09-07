<?php

require_once __DIR__ . '/DbsConfiguration.inc.php';
require_once __DIR__ . '/DbsCredentialProvider.inc.php';
require_once __DIR__ . '/DbsSecretBox.inc.php';
require_once __DIR__ . '/DbsSettingsRepository.inc.php';

class DbsSettingsService
{
    const ERROR_INVALID_INPUT = 1;
    const ERROR_PASSWORD_REQUIRED = 2;
    const STATUS_INVALID = 'invalid';

    private $repository;
    private $secretBox;
    private $credentialProvider;

    public function __construct($db, $secretBox = null, $environmentReader = null)
    {
        $this->repository = new DbsSettingsRepository($db);
        $this->secretBox = $secretBox === null ? new DbsSecretBox() : $secretBox;
        $this->credentialProvider = new DbsCredentialProvider($db, $this->secretBox, $environmentReader);
    }

    public function getFormState()
    {
        $record = $this->repository->get();
        $metadata = $this->credentialProvider->getMetadata();
        $hasWebConfiguration = DbsCredentialProvider::isCompleteWebRecord($record);
        $source = $metadata['source'];
        $wsdlUrl = DbsConfiguration::DEFAULT_WSDL_URL;
        $username = '';

        if($hasWebConfiguration) {
            $wsdlUrl = (string)$record['wsdl_url'];
            $username = (string)$record['username'];
        } elseif($metadata['configured'] && $metadata['source'] === DbsConfiguration::SOURCE_ENVIRONMENT) {
            $wsdlUrl = (string)$metadata['wsdl_url'];
            $username = (string)$metadata['username'];
        }

        $connectionStatus = DbsSettingsRepository::STATUS_UNTESTED;
        $lastTestedAt = '';

        if(
            is_array($record)
            && isset($record['connection_source'])
            && $record['connection_source'] === $source
            && isset($record['connection_status'])
            && in_array($record['connection_status'], array(
                DbsSettingsRepository::STATUS_SUCCESS,
                DbsSettingsRepository::STATUS_FAILURE
            ), true)
        ) {
            $connectionStatus = $record['connection_status'];
            $lastTestedAt = isset($record['last_tested_at']) ? (string)$record['last_tested_at'] : '';
        }

        if(!empty($metadata['invalid'])) {
            $connectionStatus = self::STATUS_INVALID;
            $lastTestedAt = '';
        }

        return array(
            'configured' => (bool)$metadata['configured'],
            'source' => $source,
            'wsdl_url' => $wsdlUrl,
            'username' => $username,
            'password_configured' => $hasWebConfiguration,
            'configuration_invalid' => !empty($metadata['invalid']),
            'connection_status' => $connectionStatus,
            'last_tested_at' => $lastTestedAt
        );
    }

    public function save($wsdlUrl, $username, $newPassword)
    {
        $wsdlUrl = DbsConfiguration::normalizeWsdlUrl($wsdlUrl);
        $username = DbsConfiguration::normalizeUsername($username);

        if(
            $wsdlUrl === false
            || $username === false
            || !is_string($newPassword)
            || ($newPassword !== '' && !DbsConfiguration::isValidPassword($newPassword))
        ) {
            throw new RuntimeException('Ungültige DBS-Einstellungen.', self::ERROR_INVALID_INPUT);
        }

        $record = $this->repository->get();
        $hasStoredPassword = is_array($record)
            && isset($record['password_ciphertext'])
            && is_string($record['password_ciphertext'])
            && $record['password_ciphertext'] !== '';

        if($newPassword === '' && !$hasStoredPassword) {
            throw new RuntimeException('Für die Webkonfiguration ist ein Passwort erforderlich.', self::ERROR_PASSWORD_REQUIRED);
        }

        $newCiphertext = '';

        if($newPassword !== '') {
            $newCiphertext = $this->secretBox->encrypt($newPassword, $wsdlUrl, $username);
        } else {
            $storedWsdlUrl = DbsConfiguration::normalizeWsdlUrl($record['wsdl_url']);
            $storedUsername = DbsConfiguration::normalizeUsername($record['username']);

            if($storedWsdlUrl !== $wsdlUrl || $storedUsername !== $username) {
                throw new RuntimeException(
                    'Bei geänderter WSDL-URL oder geändertem Benutzernamen ist ein neues Passwort erforderlich.',
                    self::ERROR_PASSWORD_REQUIRED
                );
            }

            $storedPassword = null;

            try {
                $storedPassword = $this->secretBox->decrypt(
                    $record['password_ciphertext'],
                    $record['wsdl_url'],
                    $record['username']
                );
            } finally {
                if(is_string($storedPassword) && function_exists('sodium_memzero')) {
                    sodium_memzero($storedPassword);
                }

                $storedPassword = null;
            }
        }

        $this->repository->saveConfiguration($wsdlUrl, $username, $newCiphertext);
    }

    public function buildTestConfiguration($wsdlUrl, $username, $password)
    {
        if(!is_string($password)) {
            throw new RuntimeException('Ungültige DBS-Einstellungen.', self::ERROR_INVALID_INPUT);
        }

        if($password !== '') {
            return array(
                'configuration' => new DbsConfiguration(
                    $wsdlUrl,
                    $username,
                    $password,
                    DbsConfiguration::SOURCE_REQUEST
                ),
                'persist_source' => ''
            );
        }

        $record = $this->repository->get();

        if(DbsCredentialProvider::isCompleteWebRecord($record)) {
            $normalizedWsdlUrl = DbsConfiguration::normalizeWsdlUrl($wsdlUrl);
            $normalizedUsername = DbsConfiguration::normalizeUsername($username);

            if(
                $normalizedWsdlUrl !== DbsConfiguration::normalizeWsdlUrl($record['wsdl_url'])
                || $normalizedUsername !== DbsConfiguration::normalizeUsername($record['username'])
            ) {
                throw new RuntimeException(
                    'Für aktuell eingegebene DBS-Einstellungen ist ein Passwort erforderlich.',
                    self::ERROR_PASSWORD_REQUIRED
                );
            }

            $configuration = new DbsConfiguration(
                $normalizedWsdlUrl,
                $normalizedUsername,
                $this->secretBox->decrypt(
                    $record['password_ciphertext'],
                    $record['wsdl_url'],
                    $record['username']
                ),
                DbsConfiguration::SOURCE_WEB
            );

            return array(
                'configuration' => $configuration,
                'persist_source' => DbsConfiguration::SOURCE_WEB
            );
        }

        $configuration = $this->credentialProvider->resolve();
        $normalizedWsdlUrl = DbsConfiguration::normalizeWsdlUrl($wsdlUrl);
        $normalizedUsername = DbsConfiguration::normalizeUsername($username);

        if(
            $normalizedWsdlUrl !== $configuration->getWsdlUrl()
            || $normalizedUsername !== $configuration->getUsername()
        ) {
            throw new RuntimeException(
                'Für aktuell eingegebene DBS-Einstellungen ist ein Passwort erforderlich.',
                self::ERROR_PASSWORD_REQUIRED
            );
        }

        return array(
            'configuration' => $configuration,
            'persist_source' => $configuration->getSource() === DbsConfiguration::SOURCE_ENVIRONMENT
                ? DbsConfiguration::SOURCE_ENVIRONMENT
                : ''
        );
    }

    public function saveTestResult($successful, $source)
    {
        if(!in_array($source, array(DbsConfiguration::SOURCE_WEB, DbsConfiguration::SOURCE_ENVIRONMENT), true)) {
            return;
        }

        $this->repository->saveConnectionStatus(
            $successful ? DbsSettingsRepository::STATUS_SUCCESS : DbsSettingsRepository::STATUS_FAILURE,
            $source
        );
    }
}
