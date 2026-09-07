<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsModuleAccess.inc.php';

function providerDnsMenuAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

class ProviderDnsMenuAuth
{
    private $admin;

    public function __construct($admin)
    {
        $this->admin = $admin;
    }

    public function is_admin()
    {
        return $this->admin;
    }
}

function providerDnsMenuLoadModule($admin)
{
    $app = new stdClass();
    $app->auth = new ProviderDnsMenuAuth($admin);
    $module = array();
    include __DIR__ . '/../src/dbsdns/lib/module.conf.php';

    return $module;
}

$testFailure = null;

try {
    $repositoryRoot = dirname(__DIR__);
    $moduleConfiguration = file_get_contents($repositoryRoot . '/src/dbsdns/lib/module.conf.php');
    $adminConfiguration = file_get_contents($repositoryRoot . '/src/dbsdns/lib/admin.conf.php');
    $plugin = file_get_contents($repositoryRoot . '/src/dbsdns/lib/plugin.d/dbsdns_module_access_plugin.inc.php');
    $index = file_get_contents($repositoryRoot . '/src/dbsdns/index.php');
    $adminMenu = file_get_contents($repositoryRoot . '/src/dbsdns/lib/classes/DbsAdminMenu.inc.php');
    $clientModule = providerDnsMenuLoadModule(false);
    $adminModule = providerDnsMenuLoadModule(true);

    providerDnsMenuAssertTrue(
        strpos($moduleConfiguration, "\$module['name'] = 'dbsdns'") !== false
        && strpos($moduleConfiguration, "\$module['title'] = 'DNS';") !== false
        && strpos($moduleConfiguration, "'dbsdns/zone_list.php'") !== false,
        'Das eigenständige Benutzermodul besitzt nicht den Titel DNS und den gewünschten Einstieg.'
    );
    providerDnsMenuAssertTrue(
        count($clientModule['nav']) === 1
        && $clientModule['nav'][0]['title'] === 'DNS'
        && count($adminModule['nav']) === 2
        && $adminModule['nav'][1]['title'] === 'DBS DNS management'
        && count($adminModule['nav'][1]['items']) === 7,
        'Admin und normale Benutzer erhalten nicht die logisch getrennten Menübäume.'
    );
    providerDnsMenuAssertTrue(
        strpos($adminConfiguration, '$options[]') === false
        && strpos($moduleConfiguration, 'DbsAdminMenu::navigationGroup()') !== false
        && strpos($adminMenu, "'title' => 'Assigned domains'") !== false
        && strpos($adminMenu, 'dbsdns/domain_sync.php') !== false
        && strpos($adminMenu, 'dbsdns/settings.php') !== false,
        'Der technische DBS-DNS-Adminbereich fehlt oder wird doppelt registriert.'
    );
    $expectedAdminLinks = array(
        'dbsdns/assignment_list.php?view=overview',
        'dbsdns/assignment_list.php?status=all',
        'dbsdns/assignment_list.php?status=saved',
        'dbsdns/assignment_list.php?status=conflict',
        'dbsdns/assignment_list.php?status=unassigned',
        'dbsdns/domain_sync.php',
        'dbsdns/settings.php'
    );
    providerDnsMenuAssertTrue(
        array_column($adminModule['nav'][1]['items'], 'link') === $expectedAdminLinks,
        'Mindestens eine technische Adminseite ist nicht erreichbar.'
    );
    providerDnsMenuAssertTrue(
        DbsModuleAccess::rewriteModules('dashboard,dns,tools', false)
            === 'dashboard,dbsdns,tools',
        'Das native DNS-Modul wird für einen DBS-Kunden nicht exakt ersetzt.'
    );
    foreach(array('assignment_list.php', 'assignment_edit.php', 'domain_sync.php', 'settings.php') as $adminController) {
        $source = file_get_contents($repositoryRoot . '/src/dbsdns/' . $adminController);
        providerDnsMenuAssertTrue(
            strpos($source, "check_module_permissions('dbsdns')") !== false
            && strpos($source, 'is_admin()') !== false,
            'Eine technische Adminroute ist nur kosmetisch geschützt: ' . $adminController
        );
    }
    providerDnsMenuAssertTrue(
        DbsModuleAccess::rewriteModules('dashboard,dns,tools', true)
            === 'dashboard,dns,tools,dbsdns',
        'Der technische Admin behält das native DNS-Modul nicht zusätzlich.'
    );
    providerDnsMenuAssertTrue(
        substr_count(DbsModuleAccess::rewriteModules('dns,dbsdns,dns', false), 'dbsdns') === 1
        && !in_array(
            'dns',
            explode(',', DbsModuleAccess::rewriteModules('dns,dbsdns,dns', false)),
            true
        ),
        'Die Modulumschaltung erzeugt doppelte DNS-Menüpunkte.'
    );
    providerDnsMenuAssertTrue(
        strpos($plugin, "'login'") !== false
        && strpos($plugin, "'client:client:on_after_update'") !== false
        && strpos($plugin, "'client:domain:on_after_insert'") !== false
        && strpos($plugin, '!in_array(DbsModuleAccess::NATIVE_DNS_MODULE') !== false,
        'Die core-freie Modulberechtigung wird nicht dauerhaft synchronisiert.'
    );
    providerDnsMenuAssertTrue(
        strpos($index, "zone_list.php") !== false,
        'Der Moduleinstieg führt nicht zur Kunden-Zonenliste.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Core-freie DNS-Navigation erfolgreich geprüft.' . PHP_EOL;
