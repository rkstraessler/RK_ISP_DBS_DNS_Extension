<?php

class DomainMatcherException extends RuntimeException
{
}

class DomainMatcher
{
    const STATUS_ASSIGNED = 'assigned';
    const STATUS_CONFLICT = 'conflict';
    const STATUS_UNASSIGNED = 'unassigned';

    const SOURCE_WEB = 'web_domain';
    const SOURCE_MAIL = 'mail_domain';
    const SOURCE_DNS = 'dns_soa';

    private $idnEncoder;

    public function __construct($idnEncoder = null)
    {
        if($idnEncoder !== null && !is_callable($idnEncoder)) {
            throw new InvalidArgumentException('Der IDN-Encoder ist nicht aufrufbar.');
        }

        $this->idnEncoder = $idnEncoder;
    }

    public function normalizeDomain($domain)
    {
        if(!is_string($domain)) {
            return false;
        }

        $domain = trim($domain);

        if(substr($domain, -1) === '.') {
            $domain = substr($domain, 0, -1);
        }

        if($domain === '') {
            return false;
        }

        $domain = $this->encodeIdn($domain);

        if(!is_string($domain) || $domain === '') {
            return false;
        }

        $domain = strtolower(trim($domain));

        if(
            strlen($domain) > 253 ||
            !preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $domain)
        ) {
            return false;
        }

        return $domain;
    }

    public function matchDbsDomains($db, $dbsDomains)
    {
        return $this->match($dbsDomains, $this->loadIspConfigResources($db));
    }

    public function match($dbsDomains, $resources)
    {
        if(!is_array($dbsDomains) || !is_array($resources)) {
            throw new DomainMatcherException('Ungültige Daten für den Domain-Abgleich.');
        }

        $resourceIndex = $this->buildResourceIndex($resources);
        $matches = array();

        foreach($dbsDomains as $dbsDomain) {
            if(!is_array($dbsDomain)) {
                throw new DomainMatcherException('Ungültiger Eintrag in der DBS-Domainliste.');
            }

            $origin = isset($dbsDomain['origin']) ? $dbsDomain['origin'] : (
                isset($dbsDomain['domain_name']) ? $dbsDomain['domain_name'] : ''
            );
            $normalizedDomain = $this->normalizeDomain($origin);

            if($normalizedDomain === false) {
                throw new DomainMatcherException('Ungültige Domain in der DBS-Domainliste.');
            }

            $domainResources = isset($resourceIndex[$normalizedDomain])
                ? $resourceIndex[$normalizedDomain]
                : array();
            $matches[] = $this->buildMatch($dbsDomain, $normalizedDomain, $domainResources);
        }

        usort($matches, function($left, $right) {
            return strcmp($left['normalized_domain'], $right['normalized_domain']);
        });

        return $matches;
    }

    private function loadIspConfigResources($db)
    {
        if(!is_object($db) || !method_exists($db, 'queryAllRecords')) {
            throw new DomainMatcherException('ISPConfig-Datenbankzugriff nicht verfügbar.');
        }

        $sql = "SELECT domain_resource.resource_source AS `source`, domain_resource.domain_name, domain_resource.sys_groupid,\n"
            . "       sys_group.client_id AS group_client_id,\n"
            . "       sys_group.name AS group_name,\n"
            . "       client.client_id AS client_id, client.parent_client_id,\n"
            . "       client.company_name AS client_company_name,\n"
            . "       client.contact_firstname AS client_contact_firstname,\n"
            . "       client.contact_name AS client_contact_name,\n"
            . "       client.username AS client_username, client.customer_no AS client_customer_no,\n"
            . "       reseller.client_id AS reseller_client_id,\n"
            . "       reseller.company_name AS reseller_company_name,\n"
            . "       reseller.contact_firstname AS reseller_contact_firstname,\n"
            . "       reseller.contact_name AS reseller_contact_name,\n"
            . "       reseller.username AS reseller_username, reseller.customer_no AS reseller_customer_no\n"
            . "FROM (\n"
            . "    SELECT 'web_domain' AS resource_source, domain AS domain_name, sys_groupid FROM web_domain WHERE domain IS NOT NULL AND domain <> ''\n"
            . "    UNION ALL\n"
            . "    SELECT 'mail_domain' AS source, domain AS domain_name, sys_groupid FROM mail_domain WHERE domain <> ''\n"
            . "    UNION ALL\n"
            . "    SELECT 'dns_soa' AS source, origin AS domain_name, sys_groupid FROM dns_soa WHERE origin <> ''\n"
            . ") AS domain_resource\n"
            . "LEFT JOIN sys_group ON sys_group.groupid = domain_resource.sys_groupid\n"
            . "LEFT JOIN client ON client.client_id = sys_group.client_id\n"
            . "LEFT JOIN client AS reseller ON reseller.client_id = client.parent_client_id";

        $resources = $db->queryAllRecords($sql);

        if(
            !is_array($resources) ||
            (property_exists($db, 'errorMessage') && $db->errorMessage !== '')
        ) {
            throw new DomainMatcherException('ISPConfig-Domainressourcen konnten nicht geladen werden.');
        }

        return $resources;
    }

    private function buildResourceIndex($resources)
    {
        $resourceIndex = array();

        foreach($resources as $resource) {
            if(!is_array($resource) || !isset($resource['domain_name'])) {
                continue;
            }

            $normalizedDomain = $this->normalizeDomain($resource['domain_name']);

            if($normalizedDomain === false) {
                continue;
            }

            if(!isset($resourceIndex[$normalizedDomain])) {
                $resourceIndex[$normalizedDomain] = array();
            }

            $resourceIndex[$normalizedDomain][] = $resource;
        }

        return $resourceIndex;
    }

    private function buildMatch($dbsDomain, $normalizedDomain, $resources)
    {
        $sources = array();
        $groupIds = array();
        $clientIds = array();
        $candidates = array();
        $unresolvedRelation = false;
        $representative = null;

        foreach($resources as $resource) {
            $source = isset($resource['source']) ? (string)$resource['source'] : '';

            if(in_array($source, $this->getSourceOrder(), true)) {
                $sources[$source] = true;
            }

            $groupId = isset($resource['sys_groupid']) ? (int)$resource['sys_groupid'] : 0;
            $clientId = isset($resource['client_id']) ? (int)$resource['client_id'] : 0;
            $groupClientId = isset($resource['group_client_id']) ? (int)$resource['group_client_id'] : $clientId;

            if($groupId <= 0 || $clientId <= 0 || $groupClientId !== $clientId) {
                $unresolvedRelation = true;
                continue;
            }

            $groupIds[$groupId] = true;
            $clientIds[$clientId] = true;

            $parentClientId = isset($resource['parent_client_id']) ? (int)$resource['parent_client_id'] : 0;
            $resellerClientId = isset($resource['reseller_client_id']) ? (int)$resource['reseller_client_id'] : 0;

            if($parentClientId > 0 && $resellerClientId !== $parentClientId) {
                $unresolvedRelation = true;
            }

            if($representative === null) {
                $representative = $resource;
            }

            $candidateKey = $groupId . ':' . $clientId;

            if(!isset($candidates[$candidateKey])) {
                $candidates[$candidateKey] = array(
                    'group_id' => $groupId,
                    'group_name' => isset($resource['group_name'])
                        ? trim((string)$resource['group_name'])
                        : '',
                    'client_id' => $clientId,
                    'client_label' => $this->buildClientLabel($resource, 'client_', $clientId),
                    'reseller_id' => $parentClientId,
                    'reseller_label' => $parentClientId > 0
                        ? $this->buildClientLabel($resource, 'reseller_', $parentClientId)
                        : '',
                    'sources' => array()
                );
            }

            if(in_array($source, $this->getSourceOrder(), true)) {
                $candidates[$candidateKey]['sources'][$source] = true;
            }
        }

        $orderedSources = array();

        foreach($this->getSourceOrder() as $source) {
            if(isset($sources[$source])) {
                $orderedSources[] = $source;
            }
        }

        $candidateList = array();

        foreach($candidates as $candidate) {
            $candidateSources = array();

            foreach($this->getSourceOrder() as $source) {
                if(isset($candidate['sources'][$source])) {
                    $candidateSources[] = $source;
                }
            }

            $candidate['sources'] = $candidateSources;
            $candidateList[] = $candidate;
        }

        usort($candidateList, function($left, $right) {
            if($left['client_id'] === $right['client_id']) {
                return $left['group_id'] - $right['group_id'];
            }

            return $left['client_id'] - $right['client_id'];
        });

        $match = array(
            'domain_name' => isset($dbsDomain['domain_name']) ? (string)$dbsDomain['domain_name'] : $normalizedDomain,
            'origin' => $normalizedDomain,
            'normalized_domain' => $normalizedDomain,
            'status' => self::STATUS_UNASSIGNED,
            'group_id' => 0,
            'client_id' => 0,
            'client_label' => '',
            'reseller_id' => 0,
            'reseller_label' => '',
            'sources' => $orderedSources,
            'resource_count' => count($resources),
            'candidates' => $candidateList,
            'has_unresolved_relation' => $unresolvedRelation
        );

        if(count($resources) === 0) {
            return $match;
        }

        if(
            $unresolvedRelation ||
            count($clientIds) !== 1 ||
            count($groupIds) !== 1 ||
            $representative === null
        ) {
            $match['status'] = self::STATUS_CONFLICT;
            return $match;
        }

        $match['status'] = self::STATUS_ASSIGNED;
        $match['group_id'] = (int)$representative['sys_groupid'];
        $match['client_id'] = (int)$representative['client_id'];
        $match['client_label'] = $this->buildClientLabel($representative, 'client_', $match['client_id']);
        $match['reseller_id'] = isset($representative['parent_client_id'])
            ? (int)$representative['parent_client_id']
            : 0;

        if($match['reseller_id'] > 0) {
            $match['reseller_label'] = $this->buildClientLabel(
                $representative,
                'reseller_',
                $match['reseller_id']
            );
        }

        return $match;
    }

    private function buildClientLabel($resource, $prefix, $clientId)
    {
        $company = $this->readResourceString($resource, $prefix . 'company_name');
        $firstName = $this->readResourceString($resource, $prefix . 'contact_firstname');
        $lastName = $this->readResourceString($resource, $prefix . 'contact_name');
        $username = $this->readResourceString($resource, $prefix . 'username');
        $customerNumber = $this->readResourceString($resource, $prefix . 'customer_no');
        $label = '';

        if($company !== '') {
            $label .= $company . ' :: ';
        }

        $contactName = trim($firstName . ' ' . $lastName);

        if($contactName !== '') {
            $label .= $contactName;
        }

        if($username !== '') {
            if($label !== '') {
                $label .= ' ';
            }

            $label .= '(' . $username;

            if($customerNumber !== '') {
                $label .= ', ' . $customerNumber;
            }

            $label .= ')';
        }

        return $label !== '' ? $label : '#' . (int)$clientId;
    }

    private function readResourceString($resource, $key)
    {
        return isset($resource[$key]) && is_scalar($resource[$key])
            ? trim((string)$resource[$key])
            : '';
    }

    private function getSourceOrder()
    {
        return array(
            self::SOURCE_WEB,
            self::SOURCE_MAIL,
            self::SOURCE_DNS
        );
    }

    private function encodeIdn($domain)
    {
        if($this->idnEncoder !== null) {
            try {
                return call_user_func($this->idnEncoder, $domain);
            } catch (Throwable $exception) {
                return false;
            }
        }

        if(function_exists('idn_to_ascii')) {
            if(
                defined('IDNA_NONTRANSITIONAL_TO_ASCII') &&
                defined('INTL_IDNA_VARIANT_UTS46') &&
                constant('IDNA_NONTRANSITIONAL_TO_ASCII')
            ) {
                return idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            }

            return idn_to_ascii($domain);
        }

        return preg_match('/[^\x00-\x7F]/', $domain) ? false : $domain;
    }
}
