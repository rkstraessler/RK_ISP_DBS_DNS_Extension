<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsAssignmentListView.inc.php';

function assignmentViewAssertSame($expected, $actual, $message)
{
    if($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

$testFailure = null;

try {
    $matches = array();

    for($number = 1; $number <= 30; $number++) {
        $matches[] = array(
            'domain_name' => 'domain' . str_pad((string)$number, 2, '0', STR_PAD_LEFT) . '.example',
            'origin' => 'domain' . str_pad((string)$number, 2, '0', STR_PAD_LEFT) . '.example',
            'effective_status' => $number <= 12 ? 'assigned' : ($number <= 20 ? 'conflict' : 'unassigned'),
            'client_label' => $number <= 12 ? 'Client ' . (13 - $number) : '',
            'reseller_label' => $number % 2 === 0 ? 'North Reseller' : 'South Reseller',
            'native_status' => $number === 1 ? 'same' : ($number === 29 ? 'existing' : ($number === 27 ? 'native-special' : 'missing')),
            'native_assignment' => in_array($number, array(1, 29), true)
                ? array('client_label' => $number === 29 ? 'Saved Special Client' : 'Client 12')
                : null,
            'sources' => $number === 28 ? array('mail', 'web-special') : array('web')
        );
    }

    $view = new DbsAssignmentListView();
    $assigned = $view->build($matches, 'assigned', '', 'domain', 'asc', 1, 10);
    assignmentViewAssertSame(12, $assigned['filtered_count'], 'Der Assigned-Filter findet nicht exakt zwölf Domains.');
    assignmentViewAssertSame(10, count($assigned['visible']), 'Rows=10 zeigt auf Seite 1 nicht zehn Adminrecords.');
    assignmentViewAssertSame(2, $assigned['total_pages'], 'Rows=10 paginiert zwölf Adminrecords nicht auf zwei Seiten.');

    $assignedPageTwo = $view->build($matches, 'assigned', '', 'domain', 'asc', 2, 10);
    assignmentViewAssertSame(2, count($assignedPageTwo['visible']), 'Die zweite Adminseite enthält nicht die verbleibenden zwei Records.');
    assignmentViewAssertSame('domain11.example', $assignedPageTwo['visible'][0]['domain_name'], 'Die Pagination verliert die Domain-Sortierung.');

    $saved = $view->build($matches, 'saved', '', 'domain', 'asc', 1, 10);
    assignmentViewAssertSame(2, $saved['filtered_count'], 'Die Ansicht Zugewiesene Domains enthält nicht exakt die nativen gültigen Zuordnungen.');
    assignmentViewAssertSame('domain01.example', $saved['visible'][0]['domain_name'], 'Die gespeicherte Zuordnungsansicht ist falsch sortiert.');

    $descending = $view->build($matches, 'all', '', 'domain', 'desc', 1, 10);
    assignmentViewAssertSame('domain30.example', $descending['visible'][0]['domain_name'], 'Domain DESC ist falsch sortiert.');

    $clientSort = $view->build($matches, 'assigned', '', 'client', 'asc', 1, 25);
    assignmentViewAssertSame('Client 1', $clientSort['visible'][0]['client_label'], 'Client ASC ist falsch sortiert.');
    assignmentViewAssertSame('domain12.example', $clientSort['visible'][0]['domain_name'], 'Der Client-Sort-Tie-Breaker ist falsch.');

    foreach(array(
        'domain30' => 'domain30.example',
        'Saved Special Client' => 'domain29.example',
        'web-special' => 'domain28.example',
        'North Reseller' => 'domain02.example',
        'native-special' => 'domain27.example'
    ) as $search => $expectedFirstDomain) {
        $result = $view->build($matches, 'all', $search, 'domain', 'asc', 1, 100);
        assignmentViewAssertSame(true, $result['filtered_count'] > 0, 'Die Adminsuche findet den erwarteten Wert nicht: ' . $search);
        assignmentViewAssertSame($expectedFirstDomain, $result['visible'][0]['domain_name'], 'Die Adminsuche liefert einen falschen ersten Treffer: ' . $search);
    }

    $clamped = $view->build($matches, 'unassigned', '', 'domain', 'asc', 999, 10);
    assignmentViewAssertSame(1, $clamped['page'], 'Eine ungültig hohe Seite wird nicht auf die letzte gültige Seite begrenzt.');
    assignmentViewAssertSame(10, count($clamped['visible']), 'Der Unassigned-Filter verliert Records an der Seitengrenze.');

    $assignmentController = file_get_contents(__DIR__ . '/../src/dbsdns/assignment_list.php');
    assignmentViewAssertSame(true,
        strpos($assignmentController, "\$effectiveStatus = \$match['effective_status'];") !== false
        && strpos($assignmentController, "\$statusLabels[\$effectiveStatus]") !== false
        && strpos($assignmentController, "\$statusClasses[\$effectiveStatus]") !== false,
        'Die Adminliste zeigt nicht denselben effektiven Status an, den Filter und Sortierung verwenden.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Admin-Search, Sortierung, Pagination, Filter und Rows-per-page erfolgreich geprüft.' . PHP_EOL;
