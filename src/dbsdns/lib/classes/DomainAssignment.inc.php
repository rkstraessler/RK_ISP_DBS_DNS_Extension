<?php

require_once __DIR__ . '/DomainMatcher.inc.php';

class DomainAssignmentException extends RuntimeException
{
    const ERROR_DATABASE = 1;
    const ERROR_INVALID_DOMAIN = 2;
    const ERROR_INVALID_CLIENT_GROUP = 3;
    const ERROR_INVALID_ADMIN = 4;
    const ERROR_WRITE_FAILED = 5;
}

class DomainAssignment
{
    const NATIVE_MISSING = 'missing';
    const NATIVE_SAME = 'same';
    const NATIVE_EXISTING = 'existing';
    const NATIVE_CONFLICT = 'conflict';

    const RESULT_CREATED = 'created';
    const RESULT_ALREADY_ASSIGNED = 'already_assigned';
    const RESULT_CONFLICT = 'conflict';

    const PERMISSION_USER = 'riud';
    const PERMISSION_GROUP = 'ru';
    const PERMISSION_OTHER = '';

    private $db;
    private $matcher;
    private $clientGroups;

    public function __construct($db, DomainMatcher $matcher)
    {
        if(
            !is_object($db) ||
            !method_exists($db, 'queryAllRecords') ||
            !method_exists($db, 'datalogInsert')
        ) {
            throw new DomainAssignmentException(
                'ISPConfig-Datenbankzugriff nicht verfügbar.',
                DomainAssignmentException::ERROR_DATABASE
            );
        }

        $this->db = $db;
        $this->matcher = $matcher;
        $this->clientGroups = null;
    }

    public function getClientGroups()
    {
        if($this->clientGroups !== null) {
            return array_values($this->clientGroups);
        }

        $sql = "SELECT sys_group.groupid, sys_group.name AS group_name,\n"
            . "       sys_group.client_id AS group_client_id,\n"
            . "       client.client_id, client.parent_client_id,\n"
            . "       client.company_name AS client_company_name,\n"
            . "       client.contact_firstname AS client_contact_firstname,\n"
            . "       client.contact_name AS client_contact_name,\n"
            . "       client.username AS client_username, client.customer_no AS client_customer_no,\n"
            . "       reseller.client_id AS reseller_client_id,\n"
            . "       reseller.company_name AS reseller_company_name,\n"
            . "       reseller.contact_firstname AS reseller_contact_firstname,\n"
            . "       reseller.contact_name AS reseller_contact_name,\n"
            . "       reseller.username AS reseller_username, reseller.customer_no AS reseller_customer_no\n"
            . "FROM sys_group\n"
            . "INNER JOIN client ON client.client_id = sys_group.client_id\n"
            . "LEFT JOIN client AS reseller ON reseller.client_id = client.parent_client_id\n"
            . "WHERE sys_group.client_id > 0\n"
            . "ORDER BY client.company_name, client.contact_name, sys_group.name";

        $records = $this->db->queryAllRecords($sql);

        if(!is_array($records) || $this->hasDatabaseError()) {
            throw new DomainAssignmentException(
                'ISPConfig-Kunden konnten nicht geladen werden.',
                DomainAssignmentException::ERROR_DATABASE
            );
        }

        $this->clientGroups = array();

        foreach($records as $record) {
            $groupId = isset($record['groupid']) ? (int)$record['groupid'] : 0;
            $clientId = isset($record['client_id']) ? (int)$record['client_id'] : 0;
            $groupClientId = isset($record['group_client_id'])
                ? (int)$record['group_client_id']
                : 0;

            if($groupId <= 0 || $clientId <= 0 || $groupClientId !== $clientId) {
                continue;
            }

            $record['groupid'] = $groupId;
            $record['client_id'] = $clientId;
            $record['parent_client_id'] = isset($record['parent_client_id'])
                ? (int)$record['parent_client_id']
                : 0;
            $record['client_label'] = $this->buildClientLabel($record, 'client_', $clientId);
            $record['reseller_label'] = $record['parent_client_id'] > 0
                ? $this->buildClientLabel($record, 'reseller_', $record['parent_client_id'])
                : '';
            $this->clientGroups[$groupId] = $record;
        }

        return array_values($this->clientGroups);
    }

    public function getNativeAssignments($domains = null)
    {
        $domainFilter = null;

        if($domains !== null) {
            if(!is_array($domains)) {
                throw new DomainAssignmentException(
                    'Ungültige Domainliste.',
                    DomainAssignmentException::ERROR_INVALID_DOMAIN
                );
            }

            $domainFilter = array();

            foreach($domains as $domain) {
                $normalizedDomain = $this->matcher->normalizeDomain($domain);

                if($normalizedDomain !== false) {
                    $domainFilter[$normalizedDomain] = true;
                }
            }
        }

        $sql = "SELECT native_domain.domain_id, native_domain.domain,\n"
            . "       native_domain.sys_userid, native_domain.sys_groupid,\n"
            . "       native_domain.sys_perm_user, native_domain.sys_perm_group, native_domain.sys_perm_other,\n"
            . "       sys_group.client_id AS group_client_id,\n"
            . "       client.client_id, client.parent_client_id,\n"
            . "       client.company_name AS client_company_name,\n"
            . "       client.contact_firstname AS client_contact_firstname,\n"
            . "       client.contact_name AS client_contact_name,\n"
            . "       client.username AS client_username, client.customer_no AS client_customer_no,\n"
            . "       reseller.client_id AS reseller_client_id,\n"
            . "       reseller.company_name AS reseller_company_name,\n"
            . "       reseller.contact_firstname AS reseller_contact_firstname,\n"
            . "       reseller.contact_name AS reseller_contact_name,\n"
            . "       reseller.username AS reseller_username, reseller.customer_no AS reseller_customer_no\n"
            . "FROM `domain` AS native_domain\n"
            . "LEFT JOIN sys_group ON sys_group.groupid = native_domain.sys_groupid\n"
            . "LEFT JOIN client ON client.client_id = sys_group.client_id\n"
            . "LEFT JOIN client AS reseller ON reseller.client_id = client.parent_client_id";

        $records = $this->db->queryAllRecords($sql);

        if(!is_array($records) || $this->hasDatabaseError()) {
            throw new DomainAssignmentException(
                'ISPConfig-Domainzuordnungen konnten nicht geladen werden.',
                DomainAssignmentException::ERROR_DATABASE
            );
        }

        $assignments = array();

        foreach($records as $record) {
            $normalizedDomain = isset($record['domain'])
                ? $this->matcher->normalizeDomain($record['domain'])
                : false;

            if(
                $normalizedDomain === false ||
                ($domainFilter !== null && !isset($domainFilter[$normalizedDomain]))
            ) {
                continue;
            }

            $groupId = isset($record['sys_groupid']) ? (int)$record['sys_groupid'] : 0;
            $clientId = isset($record['client_id']) ? (int)$record['client_id'] : 0;
            $groupClientId = isset($record['group_client_id'])
                ? (int)$record['group_client_id']
                : 0;
            $parentClientId = isset($record['parent_client_id'])
                ? (int)$record['parent_client_id']
                : 0;

            $record['domain_id'] = isset($record['domain_id']) ? (int)$record['domain_id'] : 0;
            $record['domain'] = $normalizedDomain;
            $record['sys_groupid'] = $groupId;
            $record['client_id'] = $clientId;
            $record['parent_client_id'] = $parentClientId;
            $record['relation_valid'] = $groupId > 0 && $clientId > 0 && $groupClientId === $clientId;
            $record['client_label'] = $clientId > 0
                ? $this->buildClientLabel($record, 'client_', $clientId)
                : '';
            $record['reseller_label'] = $parentClientId > 0
                ? $this->buildClientLabel($record, 'reseller_', $parentClientId)
                : '';
            $assignments[$normalizedDomain] = $record;
        }

        return $assignments;
    }

    public function annotateMatches($matches)
    {
        if(!is_array($matches)) {
            throw new DomainAssignmentException(
                'Ungültige Abgleichergebnisse.',
                DomainAssignmentException::ERROR_INVALID_DOMAIN
            );
        }

        $domains = array();

        foreach($matches as $match) {
            if(is_array($match) && isset($match['normalized_domain'])) {
                $domains[] = $match['normalized_domain'];
            }
        }

        $assignments = $this->getNativeAssignments($domains);
        $annotated = array();

        foreach($matches as $match) {
            $normalizedDomain = isset($match['normalized_domain'])
                ? (string)$match['normalized_domain']
                : '';
            $nativeAssignment = isset($assignments[$normalizedDomain])
                ? $assignments[$normalizedDomain]
                : null;
            $nativeStatus = self::NATIVE_MISSING;

            if($nativeAssignment !== null) {
                if(!$nativeAssignment['relation_valid']) {
                    $nativeStatus = self::NATIVE_CONFLICT;
                } elseif(
                    isset($match['status']) &&
                    $match['status'] === DomainMatcher::STATUS_ASSIGNED
                ) {
                    $sameGroup = isset($match['group_id'])
                        && (int)$match['group_id'] === (int)$nativeAssignment['sys_groupid'];
                    $sameClient = isset($match['client_id'])
                        && (int)$match['client_id'] === (int)$nativeAssignment['client_id'];
                    $nativeStatus = $sameGroup && $sameClient
                        ? self::NATIVE_SAME
                        : self::NATIVE_CONFLICT;
                } else {
                    $nativeStatus = self::NATIVE_EXISTING;
                }
            }

            $match['native_status'] = $nativeStatus;
            $match['native_assignment'] = $nativeAssignment;
            $match['effective_status'] = $nativeStatus === self::NATIVE_CONFLICT
                ? DomainMatcher::STATUS_CONFLICT
                : $match['status'];
            $match['bulk_eligible'] = $match['status'] === DomainMatcher::STATUS_ASSIGNED
                && $nativeStatus === self::NATIVE_MISSING
                && isset($match['group_id'], $match['client_id'])
                && (int)$match['group_id'] > 0
                && (int)$match['client_id'] > 0;
            $annotated[] = $match;
        }

        return $annotated;
    }

    public function assign($domain, $groupId, $adminUserId)
    {
        $normalizedDomain = $this->matcher->normalizeDomain($domain);
        $groupId = (int)$groupId;
        $adminUserId = (int)$adminUserId;

        if($normalizedDomain === false) {
            throw new DomainAssignmentException(
                'Ungültige Domain.',
                DomainAssignmentException::ERROR_INVALID_DOMAIN
            );
        }

        if($adminUserId <= 0) {
            throw new DomainAssignmentException(
                'Ungültiger Administrator.',
                DomainAssignmentException::ERROR_INVALID_ADMIN
            );
        }

        $clientGroup = $this->getClientGroup($groupId);
        $assignments = $this->getNativeAssignments(array($normalizedDomain));

        if(isset($assignments[$normalizedDomain])) {
            return $this->classifyExistingAssignment(
                $assignments[$normalizedDomain],
                $clientGroup
            );
        }

        return $this->insertNativeAssignment($normalizedDomain, $clientGroup, $adminUserId);
    }

    public function assignUniqueMatches($matches, $adminUserId)
    {
        if(!is_array($matches) || (int)$adminUserId <= 0) {
            throw new DomainAssignmentException(
                'Ungültige Sammelzuordnung.',
                DomainAssignmentException::ERROR_INVALID_ADMIN
            );
        }

        $this->getClientGroups();
        $domains = array();

        foreach($matches as $match) {
            if(is_array($match) && isset($match['normalized_domain'])) {
                $domains[] = $match['normalized_domain'];
            }
        }

        $assignments = $this->getNativeAssignments($domains);
        $result = array(
            'created' => 0,
            'already_assigned' => 0,
            'conflicts' => 0,
            'skipped' => 0,
            'failed' => 0
        );

        foreach($matches as $match) {
            if(
                !is_array($match) ||
                !isset($match['status']) ||
                $match['status'] !== DomainMatcher::STATUS_ASSIGNED
            ) {
                $result['skipped']++;
                continue;
            }

            $normalizedDomain = isset($match['normalized_domain'])
                ? $this->matcher->normalizeDomain($match['normalized_domain'])
                : false;
            $groupId = isset($match['group_id']) ? (int)$match['group_id'] : 0;
            $clientId = isset($match['client_id']) ? (int)$match['client_id'] : 0;

            if($normalizedDomain === false || !isset($this->clientGroups[$groupId])) {
                $result['conflicts']++;
                continue;
            }

            $clientGroup = $this->clientGroups[$groupId];

            if((int)$clientGroup['client_id'] !== $clientId) {
                $result['conflicts']++;
                continue;
            }

            if(isset($assignments[$normalizedDomain])) {
                $classification = $this->classifyExistingAssignment(
                    $assignments[$normalizedDomain],
                    $clientGroup
                );
                $result[$classification['status'] === self::RESULT_ALREADY_ASSIGNED
                    ? 'already_assigned'
                    : 'conflicts']++;
                continue;
            }

            try {
                $writeResult = $this->insertNativeAssignment(
                    $normalizedDomain,
                    $clientGroup,
                    (int)$adminUserId
                );

                if($writeResult['status'] === self::RESULT_CREATED) {
                    $result['created']++;
                    $assignments[$normalizedDomain] = $writeResult['assignment'];
                } elseif($writeResult['status'] === self::RESULT_ALREADY_ASSIGNED) {
                    $result['already_assigned']++;
                } else {
                    $result['conflicts']++;
                }
            } catch (DomainAssignmentException $exception) {
                $result['failed']++;
            }
        }

        return $result;
    }

    private function getClientGroup($groupId)
    {
        $groupId = (int)$groupId;
        $this->getClientGroups();

        if($groupId <= 0 || !isset($this->clientGroups[$groupId])) {
            throw new DomainAssignmentException(
                'Ungültige ISPConfig-Kundengruppe.',
                DomainAssignmentException::ERROR_INVALID_CLIENT_GROUP
            );
        }

        return $this->clientGroups[$groupId];
    }

    private function insertNativeAssignment($domain, $clientGroup, $adminUserId)
    {
        $insertData = array(
            'sys_userid' => (int)$adminUserId,
            'sys_groupid' => (int)$clientGroup['groupid'],
            'sys_perm_user' => self::PERMISSION_USER,
            'sys_perm_group' => self::PERMISSION_GROUP,
            'sys_perm_other' => self::PERMISSION_OTHER,
            'domain' => $domain
        );
        $domainId = (int)$this->db->datalogInsert('domain', $insertData, 'domain_id');

        if($domainId <= 0) {
            $assignments = $this->getNativeAssignments(array($domain));

            if(isset($assignments[$domain])) {
                return $this->classifyExistingAssignment($assignments[$domain], $clientGroup);
            }

            throw new DomainAssignmentException(
                'Die ISPConfig-Domainzuordnung konnte nicht gespeichert werden.',
                DomainAssignmentException::ERROR_WRITE_FAILED
            );
        }

        return array(
            'status' => self::RESULT_CREATED,
            'assignment' => array(
                'domain_id' => $domainId,
                'domain' => $domain,
                'sys_userid' => (int)$adminUserId,
                'sys_groupid' => (int)$clientGroup['groupid'],
                'sys_perm_user' => self::PERMISSION_USER,
                'sys_perm_group' => self::PERMISSION_GROUP,
                'sys_perm_other' => self::PERMISSION_OTHER,
                'client_id' => (int)$clientGroup['client_id'],
                'parent_client_id' => (int)$clientGroup['parent_client_id'],
                'client_label' => $clientGroup['client_label'],
                'reseller_label' => $clientGroup['reseller_label'],
                'relation_valid' => true
            )
        );
    }

    private function classifyExistingAssignment($assignment, $clientGroup)
    {
        $sameGroup = isset($assignment['sys_groupid'])
            && (int)$assignment['sys_groupid'] === (int)$clientGroup['groupid'];
        $sameClient = isset($assignment['client_id'])
            && (int)$assignment['client_id'] === (int)$clientGroup['client_id'];

        return array(
            'status' => $sameGroup && $sameClient
                ? self::RESULT_ALREADY_ASSIGNED
                : self::RESULT_CONFLICT,
            'assignment' => $assignment
        );
    }

    private function buildClientLabel($record, $prefix, $clientId)
    {
        $company = $this->readString($record, $prefix . 'company_name');
        $firstName = $this->readString($record, $prefix . 'contact_firstname');
        $lastName = $this->readString($record, $prefix . 'contact_name');
        $username = $this->readString($record, $prefix . 'username');
        $customerNumber = $this->readString($record, $prefix . 'customer_no');
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

    private function readString($record, $key)
    {
        return isset($record[$key]) && is_scalar($record[$key])
            ? trim((string)$record[$key])
            : '';
    }

    private function hasDatabaseError()
    {
        return property_exists($this->db, 'errorMessage')
            && $this->db->errorMessage !== '';
    }
}
