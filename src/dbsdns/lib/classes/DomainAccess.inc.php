<?php

require_once __DIR__ . '/DomainMatcher.inc.php';

class DomainAccess
{
    const ROLE_ADMIN = 'admin';
    const ROLE_RESELLER = 'reseller';
    const ROLE_CLIENT = 'client';
    const ROLE_NONE = 'none';

    private $db;
    private $matcher;
    private $assignments;

    public function __construct($db = null, $matcher = null)
    {
        if($db !== null && (!is_object($db) || !method_exists($db, 'queryAllRecords'))) {
            throw new InvalidArgumentException('ISPConfig-Datenbankzugriff nicht verfügbar.');
        }

        if($matcher !== null && !($matcher instanceof DomainMatcher)) {
            throw new InvalidArgumentException('Ungültiger Domain-Normalisierer.');
        }

        $this->db = $db;
        $this->matcher = $matcher !== null ? $matcher : new DomainMatcher();
        $this->assignments = null;
    }

    public function createContextFromIspConfig($auth, $sessionUser)
    {
        if(!is_object($auth) || !is_array($sessionUser)) {
            return $this->createContext(self::ROLE_NONE, 0);
        }

        if(method_exists($auth, 'is_admin') && $auth->is_admin()) {
            return $this->createContext(self::ROLE_ADMIN, 0);
        }

        $clientId = isset($sessionUser['client_id']) ? (int)$sessionUser['client_id'] : 0;

        if($clientId <= 0) {
            return $this->createContext(self::ROLE_NONE, 0);
        }

        if(method_exists($auth, 'is_reseller') && $auth->is_reseller()) {
            return $this->createContext(self::ROLE_RESELLER, $clientId);
        }

        return $this->createContext(self::ROLE_CLIENT, $clientId);
    }

    public function createContext($role, $clientId)
    {
        $allowedRoles = array(
            self::ROLE_ADMIN,
            self::ROLE_RESELLER,
            self::ROLE_CLIENT,
            self::ROLE_NONE
        );

        if(!in_array($role, $allowedRoles, true)) {
            $role = self::ROLE_NONE;
        }

        return array(
            'role' => $role,
            'client_id' => (int)$clientId
        );
    }

    public function canManageAssignments($context)
    {
        return is_array($context)
            && isset($context['role'])
            && $context['role'] === self::ROLE_ADMIN;
    }

    public function getAccessibleDomains($context, $domains)
    {
        if(!$this->isValidContext($context) || !is_array($domains)) {
            return array();
        }

        $candidates = array();

        foreach($domains as $domain) {
            $domainName = $this->extractDomainName($domain);
            $normalizedDomain = $this->matcher->normalizeDomain($domainName);

            if($normalizedDomain === false) {
                continue;
            }

            $candidates[] = array(
                'value' => $domain,
                'normalized_domain' => $normalizedDomain
            );
        }

        $assignments = $this->loadAssignments();
        $accessible = array();

        foreach($candidates as $candidate) {
            $normalizedDomain = $candidate['normalized_domain'];

            if(
                isset($assignments[$normalizedDomain]) &&
                $this->canAccessAssignment($context, $assignments[$normalizedDomain])
            ) {
                $accessible[] = $candidate['value'];
            }
        }

        return $accessible;
    }

    private function loadAssignments()
    {
        if($this->assignments !== null) {
            return $this->assignments;
        }

        if($this->db === null) {
            $this->assignments = array();
            return $this->assignments;
        }

        $sql = "SELECT native_domain.domain, native_domain.sys_groupid,\n"
            . "       sys_group.client_id AS group_client_id,\n"
            . "       client.client_id, client.parent_client_id,\n"
            . "       reseller.client_id AS reseller_client_id\n"
            . "FROM `domain` AS native_domain\n"
            . "LEFT JOIN sys_group ON sys_group.groupid = native_domain.sys_groupid\n"
            . "LEFT JOIN client ON client.client_id = sys_group.client_id\n"
            . "LEFT JOIN client AS reseller ON reseller.client_id = client.parent_client_id";
        $records = $this->db->queryAllRecords($sql);

        if(
            !is_array($records) ||
            (property_exists($this->db, 'errorMessage') && $this->db->errorMessage !== '')
        ) {
            throw new RuntimeException('ISPConfig-Domainzuordnungen konnten nicht geladen werden.');
        }

        $assignments = array();

        foreach($records as $record) {
            $normalizedDomain = isset($record['domain'])
                ? $this->matcher->normalizeDomain($record['domain'])
                : false;
            $groupId = isset($record['sys_groupid']) ? (int)$record['sys_groupid'] : 0;
            $clientId = isset($record['client_id']) ? (int)$record['client_id'] : 0;
            $groupClientId = isset($record['group_client_id'])
                ? (int)$record['group_client_id']
                : 0;

            if(
                $normalizedDomain === false ||
                $groupId <= 0 ||
                $clientId <= 0 ||
                $groupClientId !== $clientId
            ) {
                continue;
            }

            $assignments[$normalizedDomain] = array(
                'client_id' => $clientId,
                'parent_client_id' => isset($record['parent_client_id'])
                    ? (int)$record['parent_client_id']
                    : 0,
                'reseller_client_id' => isset($record['reseller_client_id'])
                    ? (int)$record['reseller_client_id']
                    : 0
            );
        }

        $this->assignments = $assignments;

        return $this->assignments;
    }

    private function canAccessAssignment($context, $assignment)
    {
        if($context['role'] === self::ROLE_ADMIN) {
            return true;
        }

        $contextClientId = isset($context['client_id']) ? (int)$context['client_id'] : 0;
        $domainClientId = isset($assignment['client_id']) ? (int)$assignment['client_id'] : 0;

        if($contextClientId <= 0 || $domainClientId <= 0) {
            return false;
        }

        if($context['role'] === self::ROLE_CLIENT) {
            return $contextClientId === $domainClientId;
        }

        if($context['role'] === self::ROLE_RESELLER) {
            if($contextClientId === $domainClientId) {
                return true;
            }

            $parentClientId = isset($assignment['parent_client_id'])
                ? (int)$assignment['parent_client_id']
                : 0;
            $resellerClientId = isset($assignment['reseller_client_id'])
                ? (int)$assignment['reseller_client_id']
                : 0;

            return $parentClientId > 0
                && $resellerClientId === $parentClientId
                && $contextClientId === $parentClientId;
        }

        return false;
    }

    private function isValidContext($context)
    {
        return is_array($context)
            && isset($context['role'])
            && in_array($context['role'], array(
                self::ROLE_ADMIN,
                self::ROLE_RESELLER,
                self::ROLE_CLIENT,
                self::ROLE_NONE
            ), true);
    }

    private function extractDomainName($domain)
    {
        if(is_string($domain)) {
            return $domain;
        }

        if(!is_array($domain)) {
            return '';
        }

        if(isset($domain['origin']) && is_string($domain['origin'])) {
            return $domain['origin'];
        }

        return isset($domain['domain_name']) && is_string($domain['domain_name'])
            ? $domain['domain_name']
            : '';
    }
}
