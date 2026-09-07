<?php

require_once __DIR__ . '/DbsConfiguration.inc.php';

class DbsSettingsRepository
{
    const TABLE = 'dbsdns_settings';
    const SETTINGS_ID = 1;
    const STATUS_UNTESTED = 'untested';
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILURE = 'failure';

    private $db;

    public function __construct($db)
    {
        if(
            !is_object($db)
            || !method_exists($db, 'queryOneRecord')
            || !method_exists($db, 'query')
        ) {
            throw new DbsCredentialException(
                'DBS-Konfiguration konnte nicht geladen werden.',
                DbsCredentialException::ERROR_DATABASE
            );
        }

        $this->db = $db;
    }

    public function get()
    {
        $record = $this->db->queryOneRecord(
            "SELECT settings_id, wsdl_url, username, password_ciphertext,\n"
            . "       connection_status, connection_source, last_tested_at, updated_at\n"
            . "FROM `" . self::TABLE . "` WHERE settings_id = ?",
            self::SETTINGS_ID
        );

        if($this->hasDatabaseError()) {
            $this->throwDatabaseError();
        }

        if(!is_array($record)) {
            return null;
        }

        return array(
            'settings_id' => self::SETTINGS_ID,
            'wsdl_url' => $this->stringValue($record, 'wsdl_url'),
            'username' => $this->stringValue($record, 'username'),
            'password_ciphertext' => $this->stringValue($record, 'password_ciphertext'),
            'connection_status' => $this->stringValue($record, 'connection_status'),
            'connection_source' => $this->stringValue($record, 'connection_source'),
            'last_tested_at' => $this->stringValue($record, 'last_tested_at'),
            'updated_at' => $this->stringValue($record, 'updated_at')
        );
    }

    public function saveConfiguration($wsdlUrl, $username, $newPasswordCiphertext)
    {
        $this->execute(
            "INSERT INTO `" . self::TABLE . "`\n"
            . "    (settings_id, wsdl_url, username, password_ciphertext, connection_status, connection_source, last_tested_at, updated_at)\n"
            . "VALUES (?, ?, ?, ?, ?, '', NULL, UTC_TIMESTAMP())\n"
            . "ON DUPLICATE KEY UPDATE\n"
            . "    wsdl_url = VALUES(wsdl_url),\n"
            . "    username = VALUES(username),\n"
            . "    password_ciphertext = IF(VALUES(password_ciphertext) = '', password_ciphertext, VALUES(password_ciphertext)),\n"
            . "    connection_status = VALUES(connection_status),\n"
            . "    connection_source = '',\n"
            . "    last_tested_at = NULL,\n"
            . "    updated_at = VALUES(updated_at)",
            self::SETTINGS_ID,
            $wsdlUrl,
            $username,
            $newPasswordCiphertext,
            self::STATUS_UNTESTED
        );
    }

    public function saveConnectionStatus($status, $source)
    {
        if(
            !in_array($status, array(self::STATUS_SUCCESS, self::STATUS_FAILURE), true)
            || !in_array($source, array(DbsConfiguration::SOURCE_WEB, DbsConfiguration::SOURCE_ENVIRONMENT), true)
        ) {
            $this->throwDatabaseError();
        }

        $this->execute(
            "INSERT INTO `" . self::TABLE . "`\n"
            . "    (settings_id, wsdl_url, username, password_ciphertext, connection_status, connection_source, last_tested_at, updated_at)\n"
            . "VALUES (?, '', '', '', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())\n"
            . "ON DUPLICATE KEY UPDATE\n"
            . "    connection_status = VALUES(connection_status),\n"
            . "    connection_source = VALUES(connection_source),\n"
            . "    last_tested_at = VALUES(last_tested_at)",
            self::SETTINGS_ID,
            $status,
            $source
        );
    }

    private function execute($query)
    {
        $arguments = func_get_args();
        $result = call_user_func_array(array($this->db, 'query'), $arguments);

        if($result === false || $this->hasDatabaseError()) {
            $this->throwDatabaseError();
        }
    }

    private function stringValue($record, $key)
    {
        return isset($record[$key]) && is_scalar($record[$key]) ? (string)$record[$key] : '';
    }

    private function hasDatabaseError()
    {
        return property_exists($this->db, 'errorMessage') && $this->db->errorMessage !== '';
    }

    private function throwDatabaseError()
    {
        throw new DbsCredentialException(
            'DBS-Konfiguration konnte nicht gespeichert oder geladen werden.',
            DbsCredentialException::ERROR_DATABASE
        );
    }
}
