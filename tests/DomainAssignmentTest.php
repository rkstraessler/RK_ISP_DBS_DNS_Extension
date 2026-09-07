<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DomainMatcher.inc.php';
require_once __DIR__ . '/../src/dbsdns/lib/classes/DomainAssignment.inc.php';
require_once __DIR__ . '/../src/dbsdns/lib/classes/DomainAccess.inc.php';

class DomainAssignmentFakeDb
{
    public $inserted = array();
    private $clientGroups;
    private $assignments;
    private $nextId = 1000;

    public function __construct($clientGroups, $assignments)
    {
        $this->clientGroups = $clientGroups;
        $this->assignments = array();

        foreach($assignments as $assignment) {
            $this->assignments[$assignment['domain']] = $assignment;
        }
    }

    public function queryAllRecords($query)
    {
        if(strpos($query, 'FROM sys_group') !== false) {
            return $this->clientGroups;
        }

        if(strpos($query, 'FROM `domain` AS native_domain') !== false) {
            return array_values($this->assignments);
        }

        return array();
    }

    public function datalogInsert($table, $data, $indexField)
    {
        if($table !== 'domain' || $indexField !== 'domain_id') {
            return 0;
        }

        if(isset($this->assignments[$data['domain']])) {
            return 0;
        }

        $domainId = $this->nextId++;
        $clientGroup = null;

        foreach($this->clientGroups as $group) {
            if((int)$group['groupid'] === (int)$data['sys_groupid']) {
                $clientGroup = $group;
                break;
            }
        }

        if($clientGroup === null) {
            return 0;
        }

        $assignment = array_merge($data, array(
            'domain_id' => $domainId,
            'group_client_id' => (int)$clientGroup['client_id'],
            'client_id' => (int)$clientGroup['client_id'],
            'parent_client_id' => (int)$clientGroup['parent_client_id'],
            'reseller_client_id' => (int)$clientGroup['parent_client_id'],
            'client_company_name' => $clientGroup['client_company_name'],
            'client_contact_firstname' => '',
            'client_contact_name' => '',
            'client_username' => $clientGroup['client_username'],
            'client_customer_no' => '',
            'reseller_company_name' => $clientGroup['reseller_company_name'],
            'reseller_contact_firstname' => '',
            'reseller_contact_name' => '',
            'reseller_username' => $clientGroup['reseller_username'],
            'reseller_customer_no' => ''
        ));
        $this->assignments[$data['domain']] = $assignment;
        $this->inserted[] = array(
            'table' => $table,
            'index_field' => $indexField,
            'data' => $data
        );

        return $domainId;
    }
}

function domainAssignmentAssertSame($expected, $actual, $message)
{
    if($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function domainAssignmentAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

function domainAssignmentClientGroup($groupId, $clientId, $parentClientId)
{
    return array(
        'groupid' => $groupId,
        'group_name' => 'client' . $clientId,
        'group_client_id' => $clientId,
        'client_id' => $clientId,
        'parent_client_id' => $parentClientId,
        'client_company_name' => 'Client ' . $clientId,
        'client_contact_firstname' => '',
        'client_contact_name' => '',
        'client_username' => 'client' . $clientId,
        'client_customer_no' => '',
        'reseller_client_id' => $parentClientId,
        'reseller_company_name' => $parentClientId > 0 ? 'Reseller ' . $parentClientId : '',
        'reseller_contact_firstname' => '',
        'reseller_contact_name' => '',
        'reseller_username' => $parentClientId > 0 ? 'reseller' . $parentClientId : '',
        'reseller_customer_no' => ''
    );
}

function domainAssignmentNative($domain, $domainId, $groupId, $clientId, $parentClientId)
{
    return array(
        'domain_id' => $domainId,
        'domain' => $domain,
        'sys_userid' => 1,
        'sys_groupid' => $groupId,
        'sys_perm_user' => 'riud',
        'sys_perm_group' => 'ru',
        'sys_perm_other' => '',
        'group_client_id' => $clientId,
        'client_id' => $clientId,
        'parent_client_id' => $parentClientId,
        'client_company_name' => 'Client ' . $clientId,
        'client_contact_firstname' => '',
        'client_contact_name' => '',
        'client_username' => 'client' . $clientId,
        'client_customer_no' => '',
        'reseller_client_id' => $parentClientId,
        'reseller_company_name' => $parentClientId > 0 ? 'Reseller ' . $parentClientId : '',
        'reseller_contact_firstname' => '',
        'reseller_contact_name' => '',
        'reseller_username' => $parentClientId > 0 ? 'reseller' . $parentClientId : '',
        'reseller_customer_no' => ''
    );
}

function domainAssignmentMatch($status, $domain, $groupId, $clientId)
{
    return array(
        'domain_name' => $domain,
        'origin' => $domain,
        'normalized_domain' => $domain,
        'status' => $status,
        'group_id' => $groupId,
        'client_id' => $clientId
    );
}

$testFailure = null;

try {
    $matcher = new DomainMatcher(function($domain) {
        return $domain;
    });
    $clientGroups = array(
        domainAssignmentClientGroup(501, 12, 99),
        domainAssignmentClientGroup(777, 44, 77),
        domainAssignmentClientGroup(999, 99, 0)
    );
    $uniqueMatch = domainAssignmentMatch(
        DomainMatcher::STATUS_ASSIGNED,
        'new.example',
        501,
        12
    );

    $newDb = new DomainAssignmentFakeDb($clientGroups, array());
    $newService = new DomainAssignment($newDb, $matcher);
    $newResult = $newService->assignUniqueMatches(array($uniqueMatch), 1);
    domainAssignmentAssertSame(1, $newResult['created'], 'Eine neue eindeutige Zuordnung wird nicht angelegt.');
    domainAssignmentAssertSame(1, count($newDb->inserted), 'Die neue eindeutige Zuordnung erzeugt nicht genau einen nativen Eintrag.');
    $insertData = $newDb->inserted[0]['data'];
    domainAssignmentAssertSame(1, $insertData['sys_userid'], 'sys_userid entspricht nicht dem Admin-Benutzer.');
    domainAssignmentAssertSame(501, $insertData['sys_groupid'], 'sys_groupid entspricht nicht der validierten Kundengruppe.');
    domainAssignmentAssertSame('riud', $insertData['sys_perm_user'], 'sys_perm_user entspricht nicht der nativen ISPConfig-Vorgabe.');
    domainAssignmentAssertSame('ru', $insertData['sys_perm_group'], 'sys_perm_group entspricht nicht dem nativen Endzustand.');
    domainAssignmentAssertSame('', $insertData['sys_perm_other'], 'sys_perm_other entspricht nicht der nativen ISPConfig-Vorgabe.');
    domainAssignmentAssertSame('new.example', $insertData['domain'], 'Die normalisierte Domain wird nicht gespeichert.');

    $sameDb = new DomainAssignmentFakeDb(
        $clientGroups,
        array(domainAssignmentNative('same.example', 10, 501, 12, 99))
    );
    $sameService = new DomainAssignment($sameDb, $matcher);
    $sameResult = $sameService->assignUniqueMatches(array(domainAssignmentMatch(
        DomainMatcher::STATUS_ASSIGNED,
        'same.example',
        501,
        12
    )), 1);
    domainAssignmentAssertSame(1, $sameResult['already_assigned'], 'Eine identische bestehende Zuordnung wird nicht erkannt.');
    domainAssignmentAssertSame(0, count($sameDb->inserted), 'Eine identische bestehende Zuordnung wird doppelt angelegt.');

    $differentDb = new DomainAssignmentFakeDb(
        $clientGroups,
        array(domainAssignmentNative('different.example', 11, 777, 44, 77))
    );
    $differentService = new DomainAssignment($differentDb, $matcher);
    $differentResult = $differentService->assignUniqueMatches(array(domainAssignmentMatch(
        DomainMatcher::STATUS_ASSIGNED,
        'different.example',
        501,
        12
    )), 1);
    domainAssignmentAssertSame(1, $differentResult['conflicts'], 'Eine abweichende bestehende Zuordnung wird nicht als Konflikt erkannt.');
    domainAssignmentAssertSame(0, count($differentDb->inserted), 'Eine abweichende bestehende Zuordnung wird überschrieben.');

    $nonUniqueDb = new DomainAssignmentFakeDb($clientGroups, array());
    $nonUniqueService = new DomainAssignment($nonUniqueDb, $matcher);
    $nonUniqueResult = $nonUniqueService->assignUniqueMatches(array(
        domainAssignmentMatch(DomainMatcher::STATUS_CONFLICT, 'conflict.example', 0, 0),
        domainAssignmentMatch(DomainMatcher::STATUS_UNASSIGNED, 'unassigned.example', 0, 0)
    ), 1);
    domainAssignmentAssertSame(2, $nonUniqueResult['skipped'], 'Konflikte und nicht zugeordnete Domains werden nicht sicher ausgelassen.');
    domainAssignmentAssertSame(0, count($nonUniqueDb->inserted), 'Ein Konflikt oder eine nicht zugeordnete Domain wird automatisch geschrieben.');

    $manualResult = $nonUniqueService->assign('manual.example', 501, 1);
    domainAssignmentAssertSame(DomainAssignment::RESULT_CREATED, $manualResult['status'], 'Eine ausdrücklich gewählte manuelle Zuordnung wird nicht angelegt.');
    domainAssignmentAssertSame(1, count($nonUniqueDb->inserted), 'Die manuelle Zuordnung erzeugt nicht genau einen nativen Eintrag.');

    $invalidRelation = domainAssignmentNative('invalid-relation.example', 23, 501, 12, 99);
    $invalidRelation['group_client_id'] = 44;
    $accessDb = new DomainAssignmentFakeDb($clientGroups, array(
        domainAssignmentNative('child.example', 20, 501, 12, 99),
        domainAssignmentNative('foreign.example', 21, 777, 44, 77),
        domainAssignmentNative('reseller-own.example', 22, 999, 99, 0),
        $invalidRelation
    ));
    $access = new DomainAccess($accessDb, $matcher);
    $adminContext = $access->createContext(DomainAccess::ROLE_ADMIN, 0);
    $clientContext = $access->createContext(DomainAccess::ROLE_CLIENT, 12);
    $foreignClientContext = $access->createContext(DomainAccess::ROLE_CLIENT, 44);
    $resellerContext = $access->createContext(DomainAccess::ROLE_RESELLER, 99);
    $foreignResellerContext = $access->createContext(DomainAccess::ROLE_RESELLER, 77);
    $accessCandidates = array(
        array('origin' => 'child.example'),
        array('origin' => 'foreign.example'),
        array('origin' => 'reseller-own.example'),
        array('origin' => 'invalid-relation.example'),
        array('origin' => 'unassigned.example')
    );
    $accessibleDomains = function($context) use ($access, $accessCandidates) {
        return array_map(function($domain) {
            return $domain['origin'];
        }, $access->getAccessibleDomains($context, $accessCandidates));
    };

    domainAssignmentAssertSame(
        array('child.example', 'foreign.example', 'reseller-own.example'),
        $accessibleDomains($adminContext),
        'Der Admin sieht nicht ausschließlich zugewiesene DBS-Domains.'
    );
    domainAssignmentAssertSame(
        array('child.example'),
        $accessibleDomains($clientContext),
        'Der Kunde sieht nicht ausschließlich seine eigene Domain.'
    );
    domainAssignmentAssertSame(
        array('foreign.example'),
        $accessibleDomains($foreignClientContext),
        'Ein fremder Kunde erhält eine falsche Domainmenge.'
    );
    domainAssignmentAssertSame(
        array('child.example', 'reseller-own.example'),
        $accessibleDomains($resellerContext),
        'Der Reseller sieht nicht seine eigene Domain und die Domain seines Kunden.'
    );
    domainAssignmentAssertSame(
        array('foreign.example'),
        $accessibleDomains($foreignResellerContext),
        'Ein fremder Reseller erhält eine falsche Domainmenge.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Domain-Zuordnungs- und Zugriffstests erfolgreich.' . PHP_EOL;
