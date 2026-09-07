<?php

class DbsCredentialException extends RuntimeException
{
    const ERROR_INVALID_CONFIGURATION = 1;
    const ERROR_NOT_CONFIGURED = 2;
    const ERROR_SECRET_UNAVAILABLE = 3;
    const ERROR_DATABASE = 4;
}

class DbsConfiguration
{
    const SOURCE_WEB = 'web';
    const SOURCE_ENVIRONMENT = 'environment';
    const SOURCE_REQUEST = 'request';
    const DEFAULT_WSDL_URL = 'https://soap.domain-bestellsystem.de/soap.wsdl';

    private $wsdlUrl;
    private $username;
    private $password;
    private $source;

    public function __construct($wsdlUrl, $username, $password, $source)
    {
        $wsdlUrl = self::normalizeWsdlUrl($wsdlUrl);
        $username = self::normalizeUsername($username);

        if(
            $wsdlUrl === false
            || $username === false
            || !self::isValidPassword($password)
            || !in_array($source, array(
                self::SOURCE_WEB,
                self::SOURCE_ENVIRONMENT,
                self::SOURCE_REQUEST
            ), true)
        ) {
            throw new DbsCredentialException(
                'DBS-Konfiguration unvollständig.',
                DbsCredentialException::ERROR_INVALID_CONFIGURATION
            );
        }

        $this->wsdlUrl = $wsdlUrl;
        $this->username = $username;
        $this->password = $password;
        $this->source = $source;
    }

    public function getWsdlUrl()
    {
        return $this->wsdlUrl;
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function getPassword()
    {
        return $this->password;
    }

    public function getSource()
    {
        return $this->source;
    }

    public static function normalizeWsdlUrl($wsdlUrl)
    {
        if(!is_string($wsdlUrl)) {
            return false;
        }

        $wsdlUrl = trim($wsdlUrl);

        if($wsdlUrl === '' || strlen($wsdlUrl) > 2048 || filter_var($wsdlUrl, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($wsdlUrl);

        if(
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || strtolower((string)$parts['scheme']) !== 'https'
            || (string)$parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return false;
        }

        return $wsdlUrl;
    }

    public static function normalizeUsername($username)
    {
        if(!is_string($username)) {
            return false;
        }

        $username = trim($username);

        if(
            $username === ''
            || strlen($username) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $username)
        ) {
            return false;
        }

        return $username;
    }

    public static function isValidPassword($password)
    {
        return is_string($password)
            && trim($password) !== ''
            && strlen($password) <= 4096
            && strpos($password, "\0") === false;
    }
}
