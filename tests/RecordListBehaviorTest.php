<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsRecordListRenderer.inc.php';

class RecordListBehaviorFunctions
{
    public function intval($value)
    {
        return (int)$value;
    }
}

function recordListBehaviorAssertSame($expected, $actual, $message)
{
    if($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function recordListBehaviorAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

function recordListBehaviorInvoke($object, $methodName, $arguments = array())
{
    $method = new ReflectionMethod(get_class($object), $methodName);

    if(PHP_VERSION_ID < 80100) {
        $method->setAccessible(true);
    }

    return $method->invokeArgs($object, $arguments);
}

function recordListBehaviorVisibleCount($records, $paging)
{
    return count(array_slice($records, $paging['offset'], $paging['records_per_page']));
}

function recordListBehaviorResetSession($limit, $page = 0)
{
    $_SESSION = array(
        'search' => array(
            'limit' => 5,
            'dbsdns_record' => array(
                'limit' => $limit,
                'page' => $page,
                'order' => '',
                'search_active' => '',
                'search_type' => '',
                'search_name' => '',
                'search_data' => '',
                'search_aux' => '',
                'search_ttl' => ''
            )
        )
    );
    $_REQUEST = array();
    $_GET = array();
    $_POST = array();
}

$testFailure = null;

try {
    global $app;

    $listDefinition = array(
        'name' => 'dbsdns_record',
        'search_prefix' => 'search_',
        'records_per_page' => '15',
        'item' => array()
    );

    foreach(array('active', 'type', 'name', 'data', 'aux', 'ttl') as $fieldName) {
        $listDefinition['item'][] = array(
            'field' => $fieldName,
            'prefix' => $fieldName === 'active' ? '' : '%',
            'suffix' => $fieldName === 'active' ? '' : '%'
        );
    }

    $app = new stdClass();
    $app->functions = new RecordListBehaviorFunctions();
    $app->listform = new stdClass();
    $app->listform->listDef = $listDefinition;

    $renderer = new DbsRecordListRenderer(new DbsRecordIdentity(str_repeat('c', 64)));
    $providerRecords = array();

    for($number = 12; $number >= 1; $number--) {
        $providerRecords[] = array(
            'type' => 'A',
            'name' => 'host' . str_pad((string)$number, 2, '0', STR_PAD_LEFT),
            'data' => '192.0.2.' . $number,
            'aux' => '0',
            'ttl' => '3600'
        );
    }

    $records = $renderer->normalizeRecords($providerRecords, 42);

    recordListBehaviorResetSession(5);
    $pageOne = recordListBehaviorInvoke($renderer, 'buildPaging', array(12, 42));
    recordListBehaviorAssertSame(5, recordListBehaviorVisibleCount($records, $pageOne), 'Rows=5 zeigt auf Seite 1 nicht exakt fünf Records.');
    recordListBehaviorAssertSame(12, $pageOne['records_gesamt'], 'Die Gesamtanzahl der Records ist falsch.');
    recordListBehaviorAssertSame(3, $pageOne['max_pages'], 'Rows=5 erzeugt für zwölf Records nicht drei Seiten.');
    recordListBehaviorAssertSame('&id=42', $pageOne['page_params'], 'Die Pagination verliert die geprüfte DBS-Zone.');

    $_REQUEST = array('page' => '1');
    $pageTwo = recordListBehaviorInvoke($renderer, 'buildPaging', array(12, 42));
    recordListBehaviorAssertSame(5, $pageTwo['offset'], 'Seite 2 beginnt bei Rows=5 am falschen Offset.');
    recordListBehaviorAssertSame(5, recordListBehaviorVisibleCount($records, $pageTwo), 'Rows=5 zeigt auf Seite 2 nicht exakt fünf Records.');

    $_REQUEST = array('page' => '2');
    $pageThree = recordListBehaviorInvoke($renderer, 'buildPaging', array(12, 42));
    recordListBehaviorAssertSame(10, $pageThree['offset'], 'Seite 3 beginnt bei Rows=5 am falschen Offset.');
    recordListBehaviorAssertSame(2, recordListBehaviorVisibleCount($records, $pageThree), 'Rows=5 zeigt auf Seite 3 nicht exakt zwei Records.');
    recordListBehaviorAssertTrue(
        recordListBehaviorVisibleCount($records, $pageOne) < count($records),
        'Rows=5 rendert weiterhin alle zwölf Records gleichzeitig.'
    );

    $_REQUEST = array('search_limit' => '10', 'page' => '2');
    $tenRowsPageOne = recordListBehaviorInvoke($renderer, 'buildPaging', array(12, 42));
    recordListBehaviorAssertSame(10, $tenRowsPageOne['records_per_page'], 'Der Wechsel von 5 auf 10 wird nicht angewendet.');
    recordListBehaviorAssertSame(0, $tenRowsPageOne['page'], 'Der Wechsel von 5 auf 10 setzt die Seite nicht auf 1 zurück.');
    recordListBehaviorAssertSame(10, recordListBehaviorVisibleCount($records, $tenRowsPageOne), 'Rows=10 zeigt auf Seite 1 nicht zehn Records.');
    recordListBehaviorAssertSame(10, $_SESSION['search']['dbsdns_record']['limit'], 'Rows=10 wird nicht listenbezogen in der Session gespeichert.');

    $_REQUEST = array('page' => '1');
    $tenRowsPageTwo = recordListBehaviorInvoke($renderer, 'buildPaging', array(12, 42));
    recordListBehaviorAssertSame(2, recordListBehaviorVisibleCount($records, $tenRowsPageTwo), 'Rows=10 zeigt auf Seite 2 nicht zwei Records.');

    $_REQUEST = array('search_limit' => '20');
    $twentyRows = recordListBehaviorInvoke($renderer, 'buildPaging', array(12, 42));
    recordListBehaviorAssertSame(12, recordListBehaviorVisibleCount($records, $twentyRows), 'Rows=20 zeigt nicht alle zwölf Records auf einer Seite.');
    recordListBehaviorAssertSame(1, $twentyRows['max_pages'], 'Rows=20 erzeugt für zwölf Records mehr als eine Seite.');

    $_REQUEST = array('search_limit' => '5');
    $fiveRowsAgain = recordListBehaviorInvoke($renderer, 'buildPaging', array(12, 42));
    recordListBehaviorAssertSame(5, $fiveRowsAgain['records_per_page'], 'Der Wechsel von 10 beziehungsweise 20 zurück auf 5 bleibt nicht erhalten.');
    $limitSelect = recordListBehaviorInvoke($renderer, 'buildSearchLimitSelect', array('dbsdns_record'));
    recordListBehaviorAssertTrue(
        strpos($limitSelect, 'class="search_limit"') !== false
        && strpos($limitSelect, '<option value="5" selected="selected">5</option>') !== false
        && strpos($limitSelect, '<option value="10"') !== false
        && strpos($limitSelect, '<option value="20"') !== false,
        'Die native Page-Size-Auswahl markiert oder übermittelt 5/10/20 nicht korrekt.'
    );

    $_REQUEST = array('search_limit' => '10');
    recordListBehaviorInvoke($renderer, 'buildPaging', array(12, 42));
    $limitSelect = recordListBehaviorInvoke($renderer, 'buildSearchLimitSelect', array('dbsdns_record'));
    recordListBehaviorAssertTrue(
        strpos($limitSelect, '<option value="10" selected="selected">10</option>') !== false,
        'Der Wechsel von 5 auf 10 springt im Dropdown wieder auf 5 zurück.'
    );
    recordListBehaviorAssertSame(5, $_SESSION['search']['limit'], 'Die Recordliste überschreibt den globalen Page-Size-State anderer ISPConfig-Listen.');

    $_REQUEST = array('search_limit' => '5');
    recordListBehaviorInvoke($renderer, 'buildPaging', array(12, 42));
    $limitSelect = recordListBehaviorInvoke($renderer, 'buildSearchLimitSelect', array('dbsdns_record'));
    recordListBehaviorAssertTrue(
        strpos($limitSelect, '<option value="5" selected="selected">5</option>') !== false,
        'Der direkte Wechsel von 10 zurück auf 5 bleibt nicht auf 5.'
    );

    recordListBehaviorResetSession(5, 1);
    $_GET = array('orderby' => 'tbl_col_name');
    $ascending = recordListBehaviorInvoke($renderer, 'sortRecords', array($records, 'dbsdns_record'));
    recordListBehaviorAssertSame('host01', $ascending[0]['name'], 'Die Sortierung nach Name ASC ist falsch.');
    $pagingAfterAscendingSort = recordListBehaviorInvoke($renderer, 'buildPaging', array(12, 42));
    recordListBehaviorAssertSame(5, $pagingAfterAscendingSort['records_per_page'], 'ASC-Sortierung verändert den Rows-State.');
    recordListBehaviorAssertSame(5, recordListBehaviorVisibleCount($ascending, $pagingAfterAscendingSort), 'Seitenwechsel nach ASC-Sortierung liefert eine falsche Zeilenanzahl.');

    $_GET = array('orderby' => 'tbl_col_name');
    $descending = recordListBehaviorInvoke($renderer, 'sortRecords', array($records, 'dbsdns_record'));
    recordListBehaviorAssertSame('host12', $descending[0]['name'], 'Die Sortierung nach Name DESC ist falsch.');
    $_GET = array();
    $_REQUEST = array('page' => '1');
    $pagingAfterDescendingSort = recordListBehaviorInvoke($renderer, 'buildPaging', array(12, 42));
    recordListBehaviorAssertSame(5, $pagingAfterDescendingSort['records_per_page'], 'DESC-Sortierung verändert den Rows-State.');
    recordListBehaviorAssertSame(5, recordListBehaviorVisibleCount($descending, $pagingAfterDescendingSort), 'Seitenwechsel nach DESC-Sortierung liefert eine falsche Zeilenanzahl.');

    $searchProviderRecords = array();

    for($number = 1; $number <= 30; $number++) {
        $searchProviderRecords[] = array(
            'type' => $number <= 7 ? 'MX' : 'A',
            'name' => 'search' . str_pad((string)$number, 2, '0', STR_PAD_LEFT),
            'data' => $number <= 7 ? 'mail.example.test.' : '192.0.2.' . $number,
            'aux' => $number <= 7 ? '10' : '0',
            'ttl' => '3600'
        );
    }

    $searchRecords = $renderer->normalizeRecords($searchProviderRecords, 42);
    recordListBehaviorResetSession(5);
    $_SESSION['search']['dbsdns_record']['search_type'] = 'MX';
    $filtered = recordListBehaviorInvoke($renderer, 'filterRecords', array($searchRecords, $listDefinition));
    $filteredPageOne = recordListBehaviorInvoke($renderer, 'buildPaging', array(count($filtered), 42));
    recordListBehaviorAssertSame(7, count($filtered), 'Die Search findet nicht exakt sieben Records.');
    recordListBehaviorAssertSame(7, $filteredPageOne['records_gesamt'], 'Die Pagination verwendet nicht die gefilterte Gesamtanzahl.');
    recordListBehaviorAssertSame(5, recordListBehaviorVisibleCount($filtered, $filteredPageOne), 'Search mit sieben Treffern und Rows=5 zeigt auf Seite 1 nicht fünf Records.');

    $_REQUEST = array('page' => '1');
    $filteredPageTwo = recordListBehaviorInvoke($renderer, 'buildPaging', array(count($filtered), 42));
    recordListBehaviorAssertSame(2, recordListBehaviorVisibleCount($filtered, $filteredPageTwo), 'Search mit sieben Treffern und Rows=5 zeigt auf Seite 2 nicht zwei Records.');
    recordListBehaviorAssertSame(5, $_SESSION['search']['dbsdns_record']['limit'], 'Search verändert den Rows-State.');

    $previousSearchState = recordListBehaviorInvoke($renderer, 'getSearchState', array($listDefinition));
    $_SESSION['search']['dbsdns_record']['search_type'] = 'A';
    $_SESSION['search']['dbsdns_record']['page'] = 1;
    recordListBehaviorInvoke($renderer, 'resetPageWhenSearchChanged', array($previousSearchState, $listDefinition));
    $_REQUEST = array('page' => '1');
    $searchResetPaging = recordListBehaviorInvoke($renderer, 'buildPaging', array(23, 42));
    recordListBehaviorAssertSame(0, $searchResetPaging['page'], 'Eine neue Search setzt die Pagination nicht auf Seite 1 zurück.');
    recordListBehaviorAssertSame(5, $searchResetPaging['records_per_page'], 'Eine neue Search verliert den Rows-State.');

    $previousSearchState = recordListBehaviorInvoke($renderer, 'getSearchState', array($listDefinition));
    $_SESSION['search']['dbsdns_record']['search_type'] = '';
    $_SESSION['search']['dbsdns_record']['page'] = 2;
    recordListBehaviorInvoke($renderer, 'resetPageWhenSearchChanged', array($previousSearchState, $listDefinition));
    $_REQUEST = array();
    $removedSearchPaging = recordListBehaviorInvoke($renderer, 'buildPaging', array(30, 42));
    recordListBehaviorAssertSame(0, $removedSearchPaging['page'], 'Das Entfernen der Search setzt die Pagination nicht auf Seite 1 zurück.');
    recordListBehaviorAssertSame(30, $removedSearchPaging['records_gesamt'], 'Nach entfernter Search ist die Gesamtanzahl falsch.');
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Live-Record-Suche, ASC/DESC-Sortierung, Page-Size-State und Pagination erfolgreich geprüft.' . PHP_EOL;
