<?php

function coreFreeAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

function coreFreeRead($root, $relativePath)
{
    return file_get_contents($root . '/' . $relativePath);
}

$testFailure = null;

try {
    $root = dirname(__DIR__);
    $zoneListController = coreFreeRead($root, 'src/dbsdns/zone_list.php');
    $zoneListActions = coreFreeRead($root, 'src/dbsdns/lib/classes/DbsZoneListActions.inc.php');
    $zoneView = coreFreeRead($root, 'src/dbsdns/zone_view.php');
    $recordRenderer = coreFreeRead($root, 'src/dbsdns/lib/classes/DbsRecordListRenderer.inc.php');
    $recordPage = coreFreeRead($root, 'src/dbsdns/lib/classes/DbsRecordPage.inc.php');
    $recordService = coreFreeRead($root, 'src/dbsdns/lib/classes/DbsRecordService.inc.php');
    $recordEdit = coreFreeRead($root, 'src/dbsdns/record_edit.php');
    $recordDelete = coreFreeRead($root, 'src/dbsdns/record_delete.php');
    $zoneSettings = coreFreeRead($root, 'src/dbsdns/zone_settings.php');
    $zoneSettingsService = coreFreeRead($root, 'src/dbsdns/lib/classes/DbsZoneSettingsService.inc.php');
    $settings = coreFreeRead($root, 'src/dbsdns/settings.php');
    $capabilities = coreFreeRead($root, 'src/dbsdns/lib/classes/DbsRecordCapabilities.inc.php');
    $recordTemplate = coreFreeRead($root, 'src/dbsdns/templates/dbsdns_record_list.htm');
    $recordFormTemplate = coreFreeRead($root, 'src/dbsdns/templates/record_edit.htm');
    $installer = coreFreeRead($root, 'scripts/install.sh');

    $legacyProductionFiles = array();
    $legacyPatchDirectories = array($root . '/patches', $root . '/migration');

    foreach($legacyPatchDirectories as $legacyPatchDirectory) {
        if(is_dir($legacyPatchDirectory)) {
            $legacyPatchIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($legacyPatchDirectory, FilesystemIterator::SKIP_DOTS)
            );

            foreach($legacyPatchIterator as $legacyPatchFile) {
                if($legacyPatchFile->isFile() && $legacyPatchFile->getExtension() === 'patch') {
                    $legacyProductionFiles[] = $legacyPatchFile->getPathname();
                }
            }
        }
    }
    coreFreeAssertTrue(
        !is_dir($root . '/src/dbsdns/integration')
        || count(glob($root . '/src/dbsdns/integration/*')) === 0,
        'Die früheren Core-Delegationsadapter sind weiterhin Teil des Moduls.'
    );
    coreFreeAssertTrue(
        count($legacyProductionFiles) === 0,
        'Produktive ISPConfig-Core-Patches sind weiterhin vorhanden.'
    );
    coreFreeAssertTrue(
        strpos($installer, 'patch --') === false
        && strpos($installer, 'fuzz') === false
        && strpos($installer, 'dbsdns-native-dns.patch') === false,
        'Der Installer wendet weiterhin ISPConfig-Core-Patches an.'
    );
    coreFreeAssertTrue(
        strpos($zoneListController, "check_module_permissions('dbsdns')") !== false
        && strpos($zoneView, "check_module_permissions('dbsdns')") !== false
        && strpos($recordEdit, "check_module_permissions('dbsdns')") !== false
        && strpos($recordDelete, "check_module_permissions('dbsdns')") !== false
        && strpos($zoneSettings, "check_module_permissions('dbsdns')") !== false
        && strpos($settings, "check_module_permissions('dbsdns')") !== false
        && strpos($settings, 'is_admin()') !== false,
        'Eine eigene DBS-Route umgeht die ISPConfig-Modulberechtigung.'
    );
    coreFreeAssertTrue(
        strpos($zoneListActions, 'FROM dbsdns_zone_cache AS cache') !== false
        && strpos($zoneListActions, 'INNER JOIN `domain` AS native_domain') !== false
        && stripos($zoneListActions, 'dns_soa') === false,
        'Die Zonenliste ist nicht ausschließlich aus Cache und DomainAccess aufgebaut.'
    );
    coreFreeAssertTrue(
        strpos($zoneListActions, 'getSearchSQL') !== false
        && strpos($zoneListActions, 'SQLOrderBy') !== false
        && strpos($zoneListActions, 'getPagingHTML') !== false,
        'Search, Sorting oder Pagination verwendet nicht ISPConfigs Listform.'
    );
    coreFreeAssertTrue(
        strpos($zoneView, 'getAccessibleZoneByCacheId') !== false
        && strpos($zoneView, 'new DbsClient()') !== false
        && strpos($zoneView, 'getAccessibleZoneByCacheId') < strpos($zoneView, 'new DbsClient()'),
        'DomainAccess wird nicht vor nameserverZoneInfo() geprüft.'
    );
    coreFreeAssertTrue(
        strpos($recordRenderer, "loadListDef('list/record.list.php')") !== false
        && strpos($recordRenderer, 'array_slice') !== false
        && strpos($recordTemplate, 'data-submit-form="pageForm"') !== false
        && strpos($recordTemplate, 'data-form-action="dbsdns/zone_view.php') !== false,
        'Die Live-Recordliste verwendet nicht die eigene ISPConfig-artige Listform/AJAX-UI.'
    );
    coreFreeAssertTrue(
        strpos($recordRenderer, 'supportedWritableRecordTypes()') !== false
        && strpos($recordRenderer, "setLoop('record_buttons'") !== false
        && strpos($recordTemplate, '<tmpl_loop name="record_buttons">') !== false
        && strpos($recordTemplate, '>ALIAS<') === false
        && strpos($recordTemplate, '>CAA<') === false
        && strpos($recordTemplate, '>TLSA<') === false,
        'Recordbuttons werden nicht ausschließlich aus der zentralen Capability-Liste erzeugt.'
    );
    coreFreeAssertTrue(
        strpos($capabilities, "'A'") !== false
        && strpos($capabilities, "'AAAA'") !== false
        && strpos($capabilities, "'CNAME'") !== false
        && strpos($capabilities, "'MX'") !== false
        && strpos($capabilities, "'NS'") !== false
        && strpos($capabilities, "'SRV'") !== false
        && strpos($capabilities, "'TXT'") !== false
        && strpos($capabilities, "'ALIAS'") === false,
        'Der vorhandene Recordumfang wurde bei der Architekturänderung verändert.'
    );
    coreFreeAssertTrue(
        strpos($recordFormTemplate, 'data-submit-form="pageForm"') !== false
        && strpos($recordFormTemplate, 'data-form-action="dbsdns/record_edit.php"') !== false
        && strpos($recordPage, "csrf_token_check('POST')") !== false
        && strpos($recordDelete, "REQUEST_METHOD'] !== 'POST'") !== false
        && strpos($recordDelete, "csrf_token_check('POST')") !== false,
        'Formular-AJAX oder CSRF-Schutz fehlt.'
    );
    coreFreeAssertTrue(
        strpos($recordPage, 'new DbsRecordService') !== false
        && strpos($recordService, 'replace(') !== false
        && strpos($recordService, 'deleteRecord') < strpos($recordService, 'createRecord', strpos($recordService, 'public function replace'))
        && strpos($recordService, 'restoreRecord') !== false
        && strpos($recordService, 'getZoneInfo') < strpos($recordService, 'deleteRecord'),
        'Create/Delete/Edit verwendet nicht die abgesicherte DBS-Servicekette.'
    );
    coreFreeAssertTrue(
        strpos($zoneSettings, "REQUEST_METHOD'] !== 'POST'") !== false
        && strpos($zoneSettings, "csrf_token_check('POST')") !== false
        && strpos($zoneSettings, 'zone_settings_token') !== false
        && strpos($zoneSettingsService, 'ERROR_STALE_SETTINGS') !== false
        && strpos($zoneSettingsService, 'getAccessibleZoneByCacheId') < strpos($zoneSettingsService, 'updateZoneSettings')
        && strpos($zoneSettingsService, 'getZoneInfo') < strpos($zoneSettingsService, 'updateZoneSettings'),
        'Zoneneinstellungen umgehen POST, CSRF, DomainAccess oder den Live-Providerzustand.'
    );
    coreFreeAssertTrue(
        strpos($settings, 'csrf_token_check()') !== false
        && strpos($settings, "\$action === 'save'") !== false
        && strpos($settings, 'new DbsClient($testContext') !== false,
        'Die technische Webkonfiguration umgeht Adminrecht, CSRF oder den zentralen Read-only-Verbindungstest.'
    );

    $productionFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/src/dbsdns', FilesystemIterator::SKIP_DOTS)
    );

    foreach($productionFiles as $file) {
        if(!$file->isFile()) {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        coreFreeAssertTrue(
            stripos($source, 'INSERT INTO dns_soa') === false
            && stripos($source, 'UPDATE dns_soa') === false
            && stripos($source, 'DELETE FROM dns_soa') === false
            && stripos($source, 'INSERT INTO dns_rr') === false
            && stripos($source, 'UPDATE dns_rr') === false
            && stripos($source, 'DELETE FROM dns_rr') === false,
            'DBS-Produktionscode schreibt in native DNS-Tabellen: ' . $file->getFilename()
        );
    }
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Core-freie DBS-DNS-Architektur erfolgreich geprüft.' . PHP_EOL;
