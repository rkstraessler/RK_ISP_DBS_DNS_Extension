<?php

require_once __DIR__ . '/DbsRecordCapabilities.inc.php';

class DbsRecordNormalizationException extends RuntimeException
{
    private $reason;

    public function __construct($reason)
    {
        parent::__construct('Ungültiger DNS-Record.');
        $this->reason = is_string($reason) ? $reason : 'record';
    }

    public function getReason()
    {
        return $this->reason;
    }
}

class DbsRecordNormalizer
{
    public function normalizeProviderRecord($record, $origin = '', $forCreate = false)
    {
        $record = $this->readRecord($record);
        $origin = $origin === '' ? '' : $this->normalizeOrigin($origin);

        if($origin === false) {
            $this->invalid('name');
        }

        if($origin === '') {
            return $this->normalizeCanonicalProviderRecord($record, $forCreate);
        }

        $type = $record['type'];
        $relativeOwner = $this->normalizeRelativeOwner(
            $record['name'],
            $origin,
            in_array($type, array('CNAME', 'SRV'), true),
            $type === 'SRV' ? 'srv_name' : 'cname_name'
        );
        $name = $type === 'MX'
            ? $this->absoluteMxOwner($relativeOwner, $origin)
            : $relativeOwner;

        return $this->normalizeRecordValues(
            $name,
            $type,
            $record['data'],
            $record['aux'],
            $record['ttl'],
            $forCreate
        );
    }

    public function normalizeCanonicalProviderRecord($record, $forCreate = false)
    {
        $record = $this->readRecord($record);
        $type = $record['type'];

        if($type === 'MX') {
            $name = $this->normalizeAbsoluteFqdn($record['name'], 'name');
        } else {
            $name = strtolower(trim($record['name']));

            if(
                !$this->validRelativeOwner($name, !in_array($type, array('CNAME', 'SRV'), true))
                || substr($name, -1) === '.'
            ) {
                $this->invalid($type === 'SRV' ? 'srv_name' : ($type === 'CNAME' ? 'cname_name' : 'name'));
            }

            if($type === 'SRV' && !$this->validSrvOwner($name)) {
                $this->invalid('srv_name');
            }
        }

        return $this->normalizeRecordValues(
            $name,
            $type,
            $record['data'],
            $record['aux'],
            $record['ttl'],
            $forCreate
        );
    }

    public function normalizeFormToProviderRecord($record, $origin, $allowExistingProviderLimits = false)
    {
        if(!is_array($record)) {
            $this->invalid('record');
        }

        $origin = $this->normalizeOrigin($origin);

        if($origin === false) {
            $this->invalid('name');
        }

        $type = isset($record['type']) && is_scalar($record['type'])
            ? strtoupper(trim((string)$record['type']))
            : '';
        $active = isset($record['active']) && is_scalar($record['active'])
            ? (string)$record['active']
            : '';

        if(!DbsRecordCapabilities::isWritable($type)) {
            $this->invalid('type');
        }

        if($active !== 'Y') {
            $this->invalid('active');
        }

        $name = isset($record['name']) && is_scalar($record['name'])
            ? (string)$record['name']
            : '';
        $relativeOwner = $this->normalizeRelativeOwner(
            $name,
            $origin,
            in_array($type, array('CNAME', 'SRV'), true),
            $type === 'SRV' ? 'srv_name' : 'cname_name'
        );

        if($type === 'SRV' && !$this->validSrvOwner($relativeOwner)) {
            $this->invalid('srv_name');
        }

        $name = $type === 'MX'
            ? $this->absoluteMxOwner($relativeOwner, $origin)
            : $relativeOwner;
        $data = isset($record['data']) && is_scalar($record['data'])
            ? (string)$record['data']
            : '';
        $aux = isset($record['aux']) && is_scalar($record['aux'])
            ? (string)$record['aux']
            : '';
        $ttl = isset($record['ttl']) && is_scalar($record['ttl'])
            ? (string)$record['ttl']
            : '';
        $normalized = $this->normalizeRecordValues(
            $name,
            $type,
            $data,
            $aux,
            $ttl,
            !$allowExistingProviderLimits
        );

        if(
            !$allowExistingProviderLimits
            && !$this->validUnsignedInteger($normalized['ttl'], 60, 2147483647)
        ) {
            $this->invalid('ttl');
        }

        return $normalized;
    }

    public function normalizeProviderToForm($record, $origin)
    {
        $origin = $this->normalizeOrigin($origin);

        if($origin === false) {
            $this->invalid('name');
        }

        $record = $this->normalizeProviderRecord($record, $origin);
        $name = $record['type'] === 'MX'
            ? $this->normalizeRelativeOwner($record['name'], $origin, false)
            : $record['name'];
        $formRecord = array(
            'name' => $name,
            'type' => $record['type'],
            'ttl' => $record['ttl'],
            'active' => 'Y'
        );

        if($record['type'] === 'SRV') {
            $parts = explode(' ', $record['data'], 3);
            $formRecord['weight'] = $parts[0];
            $formRecord['port'] = $parts[1];
            $formRecord['target'] = $parts[2];
            $formRecord['aux'] = $record['aux'];
        } else {
            $formRecord['data'] = in_array($record['type'], array('CNAME', 'MX'), true)
                ? substr($record['data'], 0, -1)
                : $record['data'];

            if($record['type'] === 'MX') {
                $formRecord['aux'] = $record['aux'];
            }
        }

        return $formRecord;
    }

    public function normalizeOrigin($origin)
    {
        if(!is_string($origin)) {
            return false;
        }

        $origin = strtolower(trim($origin));

        if(substr($origin, -1) === '.') {
            $origin = substr($origin, 0, -1);
        }

        if(
            $origin === ''
            || strlen($origin) > 253
            || preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $origin) !== 1
        ) {
            return false;
        }

        return $origin;
    }

    private function readRecord($record)
    {
        $expectedKeys = array('name', 'type', 'data', 'aux', 'ttl');

        if(!is_array($record)) {
            $this->invalid('record');
        }

        $normalized = array();

        foreach($expectedKeys as $key) {
            if(!array_key_exists($key, $record) || !is_scalar($record[$key]) || is_bool($record[$key])) {
                $this->invalid($key);
            }

            $normalized[$key] = (string)$record[$key];
        }

        $normalized['type'] = strtoupper(trim($normalized['type']));

        if(!DbsRecordCapabilities::isWritable($normalized['type'])) {
            $this->invalid('type');
        }

        return $normalized;
    }

    private function normalizeRecordValues($name, $type, $data, $aux, $ttl, $forCreate)
    {
        $ttl = $this->normalizeInteger($ttl, 1, 2147483647, 'ttl');

        if($type === 'A') {
            $data = trim($data);

            if(filter_var($data, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                $this->invalid('ipv4');
            }

            $data = inet_ntop(inet_pton($data));

            $aux = $this->normalizeZeroAux($aux);
        } elseif($type === 'AAAA') {
            $data = strtolower(trim($data));

            if(filter_var($data, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                $this->invalid('ipv6');
            }

            $data = strtolower(inet_ntop(inet_pton($data)));

            $aux = $this->normalizeZeroAux($aux);
        } elseif($type === 'CNAME') {
            $data = $this->normalizeAbsoluteFqdn($data, 'cname_target');
            $aux = $this->normalizeZeroAux($aux);
        } elseif($type === 'MX') {
            $data = $this->normalizeAbsoluteFqdn($data, 'mx_target');
            $aux = $this->normalizeInteger($aux, 0, 65535, 'priority');
        } elseif($type === 'NS') {
            $data = $this->normalizeProviderFqdn($data, 'ns_target');
            $aux = $this->normalizeZeroAux($aux);
        } elseif($type === 'SRV') {
            $srvData = $this->normalizeSrvData($data);
            $data = $srvData['weight'] . ' ' . $srvData['port'] . ' ' . $srvData['target'];
            $aux = $this->normalizeInteger($aux, 0, 65535, 'priority');
        } else {
            if(
                !is_string($data)
                || $data === ''
                || ($forCreate && strlen($data) > 512)
                || ($forCreate && preg_match('/[\x00-\x1f\x7f]/', $data) === 1)
            ) {
                $this->invalid('txt');
            }

            $aux = $this->normalizeZeroAux($aux);
        }

        return array(
            'name' => $name,
            'type' => $type,
            'data' => $data,
            'aux' => $aux,
            'ttl' => $ttl
        );
    }

    private function normalizeRelativeOwner($name, $origin, $required, $requiredReason = 'name')
    {
        if(!is_string($name)) {
            $this->invalid($required ? $requiredReason : 'name');
        }

        $name = strtolower(trim($name));
        $withoutDot = substr($name, -1) === '.' ? substr($name, 0, -1) : $name;

        if($name === '@' || $name === '' || $withoutDot === $origin) {
            if($required) {
                $this->invalid($requiredReason);
            }

            return '';
        }

        $originSuffix = '.' . $origin;

        if(substr($withoutDot, -strlen($originSuffix)) === $originSuffix) {
            $name = substr($withoutDot, 0, -strlen($originSuffix));
        } else {
            $name = $withoutDot;
        }

        if(!$this->validRelativeOwner($name, false)) {
            $this->invalid($required ? $requiredReason : 'name');
        }

        return $name;
    }

    private function absoluteMxOwner($relativeOwner, $origin)
    {
        return ($relativeOwner === '' ? $origin : $relativeOwner . '.' . $origin) . '.';
    }

    private function normalizeAbsoluteFqdn($value, $reason)
    {
        $value = $this->normalizeProviderFqdn($value, $reason);

        return $value . '.';
    }

    private function normalizeProviderFqdn($value, $reason)
    {
        if(!is_string($value)) {
            $this->invalid($reason);
        }

        $value = strtolower(trim($value));

        if(substr($value, -1) === '.') {
            $value = substr($value, 0, -1);
        }

        if(!$this->validProviderFqdn($value)) {
            $this->invalid($reason);
        }

        return $value;
    }

    private function normalizeSrvData($data)
    {
        $matches = array();

        if(
            !is_string($data)
            || preg_match('/\A([0-9]{1,10})[ \t]+([0-9]{1,10})[ \t]+([^\x00-\x20\x7f]+)\z/', $data, $matches) !== 1
        ) {
            $this->invalid('srv_target');
        }

        return array(
            'weight' => $this->normalizeInteger($matches[1], 0, 65535, 'weight'),
            'port' => $this->normalizeInteger($matches[2], 0, 65535, 'port'),
            'target' => $this->normalizeProviderFqdn($matches[3], 'srv_target')
        );
    }

    private function normalizeZeroAux($aux)
    {
        $aux = $this->normalizeInteger($aux, 0, 0, 'priority');

        return $aux;
    }

    private function normalizeInteger($value, $minimum, $maximum, $reason)
    {
        if(!is_string($value)) {
            $this->invalid($reason);
        }

        $value = trim($value);

        if(!$this->validUnsignedInteger($value, $minimum, $maximum)) {
            $this->invalid($reason);
        }

        return (string)(int)$value;
    }

    private function validUnsignedInteger($value, $minimum, $maximum)
    {
        return is_string($value)
            && preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/', $value) === 1
            && (int)$value >= $minimum
            && (int)$value <= $maximum;
    }

    private function validRelativeOwner($name, $allowEmpty)
    {
        if(!is_string($name)) {
            return false;
        }

        if($name === '') {
            return $allowEmpty;
        }

        if(strlen($name) > 253 || substr($name, -1) === '.') {
            return false;
        }

        if($name === '*') {
            return true;
        }

        $label = '[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?';

        return preg_match('/\A(?:\*\.)?' . $label . '(?:\.' . $label . ')*\z/i', $name) === 1;
    }

    private function validSrvOwner($name)
    {
        if(!is_string($name) || $name === '' || strlen($name) > 253 || substr($name, -1) === '.') {
            return false;
        }

        $srvLabel = '_[a-z0-9](?:[a-z0-9-]{0,60}[a-z0-9])?';
        $ownerLabel = '[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?';

        return preg_match('/\A' . $srvLabel . '\.' . $srvLabel . '(?:\.' . $ownerLabel . ')*\z/i', $name) === 1;
    }

    private function validProviderFqdn($value)
    {
        if(!is_string($value) || $value === '' || strlen($value) > 253 || substr($value, -1) === '.') {
            return false;
        }

        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

        return preg_match('/\A' . $label . '(?:\.' . $label . ')+\z/i', $value) === 1;
    }

    private function invalid($reason)
    {
        throw new DbsRecordNormalizationException($reason);
    }
}
