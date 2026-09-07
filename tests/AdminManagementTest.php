<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsAdminMenu.inc.php';

function adminManagementAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

$testFailure = null;

try {
    $root = dirname(__DIR__);
    $group = DbsAdminMenu::navigationGroup();
    adminManagementAssertTrue(
        $group['title'] === 'DBS DNS management'
        && count($group['items']) === 7
        && $group['items'][2]['link'] === 'dbsdns/assignment_list.php?status=saved'
        && array_column($group['items'], 'title') === array(
            'Overview',
            'Assignments',
            'Assigned domains',
            'Conflicts',
            'Unassigned domains',
            'Refresh domain inventory',
            'Settings'
        ),
        'Der technische Adminbereich ist nicht vollständig oder nicht klar getrennt.'
    );

    foreach(array('assignment_list.php', 'assignment_edit.php', 'domain_sync.php', 'settings.php') as $file) {
        $source = file_get_contents($root . '/src/dbsdns/' . $file);
        adminManagementAssertTrue(
            strpos($source, "check_module_permissions('dbsdns')") !== false
            && strpos($source, 'is_admin()') !== false,
            'Direkter Kunden-/Resellerzugriff wird nicht serverseitig verweigert: ' . $file
        );
    }

    $listController = file_get_contents($root . '/src/dbsdns/assignment_list.php');
    $listTemplate = file_get_contents($root . '/src/dbsdns/templates/assignment_list.htm');
    $listView = file_get_contents($root . '/src/dbsdns/lib/classes/DbsAssignmentListView.inc.php');
    adminManagementAssertTrue(
        strpos($listController, 'getSummary()') !== false
        && strpos($listController, "'total_count'") !== false
        && strpos($listController, "'last_synced_at'") !== false
        && strpos($listController, 'connectionOverviewStatus') !== false
        && strpos($listTemplate, 'connection_overview_label') !== false,
        'Die Adminübersicht zeigt Inventar- oder DBS-Verbindungsstatus nicht an.'
    );
    adminManagementAssertTrue(
        strpos($listController, '$searchTerm') !== false
        && strpos($listView, 'stripos($this->searchText') !== false
        && strpos($listView, 'usort($filtered') !== false
        && strpos($listController, '$pageSizes = array(10, 25, 50, 100)') !== false
        && strpos($listView, 'array_slice($filtered') !== false
        && strpos($listTemplate, "name='search_term'") !== false
        && strpos($listTemplate, "name='page_size'") !== false
        && substr_count($listTemplate, "sort_") >= 4
        && strpos($listTemplate, 'previous_page') !== false
        && strpos($listTemplate, 'next_page') !== false,
        'Search, Sorting, Pagination oder Rows-per-page der Adminliste fehlt.'
    );

    $assignment = file_get_contents($root . '/src/dbsdns/lib/classes/DomainAssignment.inc.php');
    $sync = file_get_contents($root . '/src/dbsdns/domain_sync.php');
    $settings = file_get_contents($root . '/src/dbsdns/settings.php');
    adminManagementAssertTrue(
        strpos($assignment, 'domain') !== false
        && stripos($assignment, 'dns_soa') === false
        && stripos($assignment, 'dns_rr') === false,
        'Die bestehende Zuordnung verwendet nicht mehr die native Domain-Tabelle.'
    );
    adminManagementAssertTrue(
        strpos($sync, 'ZoneInventorySync') !== false
        && strpos($settings, 'testConnection') !== false
        && stripos($sync, 'password') === false,
        'Inventar-Sync oder Verbindungstest wurde fachlich verändert beziehungsweise gibt Secrets aus.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Technische Adminverwaltung, Rollen, Übersicht und Listenverhalten erfolgreich geprüft.' . PHP_EOL;
