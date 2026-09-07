<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsModuleAccess.inc.php';

class ModuleAccessFakeDb
{
    public $errorMessage = '';
    public $users;

    public function __construct()
    {
        $this->users = array(
            1 => array('userid' => 1, 'typ' => 'admin', 'client_id' => 0, 'modules' => 'dashboard,dns,admin', 'startmodule' => 'dashboard'),
            2 => array('userid' => 2, 'typ' => 'user', 'client_id' => 12, 'modules' => 'dashboard,dns,tools', 'startmodule' => 'dns'),
            3 => array('userid' => 3, 'typ' => 'user', 'client_id' => 99, 'modules' => 'dashboard,dns,client', 'startmodule' => 'dashboard'),
            4 => array('userid' => 4, 'typ' => 'user', 'client_id' => 44, 'modules' => 'dashboard,dns,tools', 'startmodule' => 'dashboard')
        );
    }

    public function queryAllRecords($sql)
    {
        if(strpos($sql, 'FROM dbsdns_zone_cache AS cache') !== false) {
            return array(array('client_id' => 12, 'parent_client_id' => 99));
        }

        if(strpos($sql, 'FROM sys_user') !== false) {
            return array_values($this->users);
        }

        return array();
    }

    public function query($sql, ...$arguments)
    {
        $userId = (int)$arguments[2];
        $this->users[$userId]['modules'] = $arguments[0];
        $this->users[$userId]['startmodule'] = $arguments[1];
    }
}

function moduleAccessAssertSame($expected, $actual, $message)
{
    if($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

$testFailure = null;

try {
    $db = new ModuleAccessFakeDb();
    $service = new DbsModuleAccess($db, function() {
        return true;
    });
    $firstResult = $service->synchronizeEligibleUsers();

    moduleAccessAssertSame(3, $firstResult['updated'], 'Admin, Kunde und direkter Reseller wurden nicht aktualisiert.');
    moduleAccessAssertSame('dashboard,dns,admin,dbsdns', $db->users[1]['modules'], 'Der Admin verliert das native DNS oder erhält DBS DNS nicht.');
    moduleAccessAssertSame('dashboard,dbsdns,tools', $db->users[2]['modules'], 'Der Kunde sieht weiterhin das native DNS-Modul.');
    moduleAccessAssertSame('dbsdns', $db->users[2]['startmodule'], 'Ein nativer DNS-Startpunkt wird nicht auf das eigene Modul migriert.');
    moduleAccessAssertSame('dashboard,dbsdns,client', $db->users[3]['modules'], 'Der direkte Reseller erhält das eigene DNS-Modul nicht.');
    moduleAccessAssertSame('dashboard,dns,tools', $db->users[4]['modules'], 'Ein fremder Kunde wird unerwartet verändert.');

    $secondResult = $service->synchronizeEligibleUsers();
    moduleAccessAssertSame(0, $secondResult['updated'], 'Die Modulberechtigung ist nicht idempotent.');

    $blockedService = new DbsModuleAccess($db, function() {
        return false;
    });
    $blockedResult = $blockedService->synchronizeEligibleUsers();
    moduleAccessAssertSame(2, $blockedResult['updated'], 'Fehlende Providerfreigabe entzieht zugeordneten Kunden das DNS-Modul nicht.');
    moduleAccessAssertSame('dashboard,tools', $db->users[2]['modules'], 'Ein Kunde behält ohne Providerfreigabe eine DNS-Berechtigung.');
    moduleAccessAssertSame('dashboard', $db->users[2]['startmodule'], 'Ein gesperrter DNS-Startpunkt wird nicht sicher zurückgesetzt.');
    moduleAccessAssertSame('dashboard,client', $db->users[3]['modules'], 'Ein direkter Reseller behält ohne Providerfreigabe eine DNS-Berechtigung.');
    moduleAccessAssertSame('dashboard,dns,tools', $db->users[4]['modules'], 'Ein nicht zugeordneter Kunde verliert unerwartet das native DNS-Modul.');
    moduleAccessAssertSame('dashboard,dns,admin,dbsdns', $db->users[1]['modules'], 'Der Admin verliert bei fehlender Providerfreigabe Diagnosezugriff.');
    moduleAccessAssertSame(0, $blockedResult['eligible_clients'], 'Nicht verifizierte Providerkonfiguration meldet freigegebene Kunden.');

    $throwingService = new DbsModuleAccess(new ModuleAccessFakeDb(), function() {
        throw new RuntimeException('simulated configuration failure');
    });
    $throwingResult = $throwingService->synchronizeEligibleUsers();
    moduleAccessAssertSame(false, $throwingResult['configuration_available'], 'Konfigurationsfehler wird nicht fail-closed behandelt.');

    $cleanupDb = new ModuleAccessFakeDb();
    $cleanupDb->users[1]['modules'] = 'dashboard,dns,admin,dbsdns';
    $cleanupDb->users[2]['modules'] = 'dashboard,dbsdns,tools';
    $cleanupDb->users[2]['startmodule'] = 'dbsdns';
    $cleanupService = new DbsModuleAccess($cleanupDb);
    $dryCleanup = $cleanupService->removeExtensionFromAllUsers(true);
    moduleAccessAssertSame(2, $dryCleanup['updated'], 'Der Deinstallations-Dry-Run erkennt betroffene Benutzer nicht.');
    moduleAccessAssertSame('dashboard,dbsdns,tools', $cleanupDb->users[2]['modules'], 'Der Deinstallations-Dry-Run verändert Modulrechte.');

    $cleanupResult = $cleanupService->removeExtensionFromAllUsers();
    moduleAccessAssertSame(2, $cleanupResult['updated'], 'Die Deinstallation entfernt das Modul nicht bei allen betroffenen Benutzern.');
    moduleAccessAssertSame('dashboard,dns,admin', $cleanupDb->users[1]['modules'], 'Die Deinstallation entfernt beim Admin native DNS-Rechte.');
    moduleAccessAssertSame('dashboard,dns,tools', $cleanupDb->users[4]['modules'], 'Die Deinstallation verändert unbeteiligte native DNS-Rechte.');
    moduleAccessAssertSame('dashboard,tools', $cleanupDb->users[2]['modules'], 'Die Deinstallation lässt DBS DNS in den Modulrechten zurück.');
    moduleAccessAssertSame('dashboard', $cleanupDb->users[2]['startmodule'], 'Die Deinstallation lässt DBS DNS als Startmodul zurück.');

    $root = dirname(__DIR__);
    $zoneAccessSource = file_get_contents($root . '/src/dbsdns/lib/classes/DbsZoneAccess.inc.php');
    moduleAccessAssertSame(
        true,
        strpos($zoneAccessSource, 'isProviderConfigurationAvailable') !== false
            && strpos($zoneAccessSource, 'ROLE_CLIENT') !== false
            && strpos($zoneAccessSource, 'ROLE_RESELLER') !== false,
        'Direkte Kunden-Zonenrouten sind nicht serverseitig an die verifizierte Providerkonfiguration gebunden.'
    );

    foreach(array('zone_list.php', 'zone_view.php', 'record_delete.php', 'zone_settings.php') as $routeFile) {
        $routeSource = file_get_contents($root . '/src/dbsdns/' . $routeFile);
        moduleAccessAssertSame(
            true,
            strpos($routeSource, 'DbsZoneAccessException') !== false,
            'Eine Kundenroute behandelt die Provider-Konfigurationssperre nicht verständlich: ' . $routeFile
        );
    }
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'DBS-DNS-Modulberechtigungen erfolgreich geprüft.' . PHP_EOL;
