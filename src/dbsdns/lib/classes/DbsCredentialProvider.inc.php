<?php

require_once __DIR__ . '/DbsConfiguration.inc.php';
require_once __DIR__ . '/DbsSecretBox.inc.php';
require_once __DIR__ . '/DbsSettingsRepository.inc.php';

class DbsCredentialProvider
{
    const ENV_WSDL_URL = 'DBS_WSDL_URL';
    const ENV_USERNAME = 'DBS_USERNAME';
    const ENV_PASSWORD = 'DBS_PASSWORD';

    private $repository;
    private $secretBox;
    private $environmentReader;

    public function __construct($db = null, $secretBox = null, $environmentReader = null)
    {
        $this->repository = $db === null ? null : new DbsSettingsRepository($db);
        $this->secretBox = $secretBox === null ? new DbsSecretBox() : $secretBox;
        $this->environmentReader = $environmentReader === null ? 'getenv' : $environmentReader;

        if(!is_callable($this->environmentReader)) {
            throw new DbsCredentialException(
                'DBS-Konfiguration unvollständig.',
                DbsCredentialException::ERROR_INVALID_CONFIGURATION
            );
        }
    }

    public static function fromGlobals()
    {
        $db = null;

        if(
            isset($GLOBALS['app'])
            && is_object($GLOBALS['app'])
            && isset($GLOBALS['app']->db)
            && is_object($GLOBALS['app']->db)
        ) {
            $db = $GLOBALS['app']->db;
        }

        return new self($db);
    }

    public function resolve()
    {
        $webRecord = $this->readWebRecord();

        if(self::isCompleteWebRecord($webRecord)) {
            $password = $this->secretBox->decrypt(
                $webRecord['password_ciphertext'],
                $webRecord['wsdl_url'],
                $webRecord['username']
            );

            return new DbsConfiguration(
                $webRecord['wsdl_url'],
                $webRecord['username'],
                $password,
                DbsConfiguration::SOURCE_WEB
            );
        }

        return $this->resolveEnvironment();
    }

    public function getMetadata()
    {
        $webRecord = $this->readWebRecord();

        if(self::isCompleteWebRecord($webRecord)) {
            try {
                $password = $this->secretBox->decrypt(
                    $webRecord['password_ciphertext'],
                    $webRecord['wsdl_url'],
                    $webRecord['username']
                );
                $validPassword = DbsConfiguration::isValidPassword($password);

                if(function_exists('sodium_memzero')) {
                    sodium_memzero($password);
                }

                $password = null;

                if(!$validPassword) {
                    throw new DbsCredentialException(
                        'DBS-Zugangsdaten konnten nicht sicher verarbeitet werden.',
                        DbsCredentialException::ERROR_SECRET_UNAVAILABLE
                    );
                }
            } catch (DbsCredentialException $exception) {
                return array(
                    'configured' => false,
                    'invalid' => true,
                    'source' => DbsConfiguration::SOURCE_WEB,
                    'wsdl_url' => '',
                    'username' => ''
                );
            }

            return array(
                'configured' => true,
                'invalid' => false,
                'source' => DbsConfiguration::SOURCE_WEB,
                'wsdl_url' => DbsConfiguration::normalizeWsdlUrl($webRecord['wsdl_url']),
                'username' => DbsConfiguration::normalizeUsername($webRecord['username'])
            );
        }

        $environment = $this->readEnvironment();

        if($this->isCompleteEnvironment($environment)) {
            return array(
                'configured' => true,
                'invalid' => false,
                'source' => DbsConfiguration::SOURCE_ENVIRONMENT,
                'wsdl_url' => DbsConfiguration::normalizeWsdlUrl($environment['wsdl_url']),
                'username' => DbsConfiguration::normalizeUsername($environment['username'])
            );
        }

        return array(
            'configured' => false,
            'invalid' => false,
            'source' => '',
            'wsdl_url' => '',
            'username' => ''
        );
    }

    public function isCustomerConfigurationAvailable()
    {
        $configuration = $this->resolve();

        if($this->repository === null) {
            return $configuration->getSource() === DbsConfiguration::SOURCE_ENVIRONMENT;
        }

        $record = $this->readWebRecord();

        if(
            $configuration->getSource() === DbsConfiguration::SOURCE_ENVIRONMENT
            && (
                !is_array($record)
                || !isset($record['connection_source'])
                || $record['connection_source'] !== DbsConfiguration::SOURCE_ENVIRONMENT
            )
        ) {
            return true;
        }

        return is_array($record)
            && isset($record['connection_status'], $record['connection_source'])
            && $record['connection_status'] === DbsSettingsRepository::STATUS_SUCCESS
            && $record['connection_source'] === $configuration->getSource();
    }

    public static function isCompleteWebRecord($record)
    {
        return is_array($record)
            && isset($record['password_ciphertext'])
            && is_string($record['password_ciphertext'])
            && $record['password_ciphertext'] !== ''
            && DbsConfiguration::normalizeWsdlUrl(isset($record['wsdl_url']) ? $record['wsdl_url'] : null) !== false
            && DbsConfiguration::normalizeUsername(isset($record['username']) ? $record['username'] : null) !== false;
    }

    private function readWebRecord()
    {
        if($this->repository === null) {
            return null;
        }

        return $this->repository->get();
    }

    private function resolveEnvironment()
    {
        $environment = $this->readEnvironment();

        if(!$this->isCompleteEnvironment($environment)) {
            throw new DbsCredentialException(
                'DBS-Konfiguration unvollständig.',
                DbsCredentialException::ERROR_NOT_CONFIGURED
            );
        }

        return new DbsConfiguration(
            $environment['wsdl_url'],
            $environment['username'],
            $environment['password'],
            DbsConfiguration::SOURCE_ENVIRONMENT
        );
    }

    private function readEnvironment()
    {
        return array(
            'wsdl_url' => call_user_func($this->environmentReader, self::ENV_WSDL_URL),
            'username' => call_user_func($this->environmentReader, self::ENV_USERNAME),
            'password' => call_user_func($this->environmentReader, self::ENV_PASSWORD)
        );
    }

    private function isCompleteEnvironment($environment)
    {
        return is_array($environment)
            && DbsConfiguration::normalizeWsdlUrl(isset($environment['wsdl_url']) ? $environment['wsdl_url'] : null) !== false
            && DbsConfiguration::normalizeUsername(isset($environment['username']) ? $environment['username'] : null) !== false
            && isset($environment['password'])
            && DbsConfiguration::isValidPassword($environment['password']);
    }
}
