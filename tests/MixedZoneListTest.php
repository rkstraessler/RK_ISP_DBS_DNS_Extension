<?php

class listform_actions
{
    public $SQLOrderBy = '';
    public $idx_key = 'id';

    public function prepareDataRow($record)
    {
        $record['id'] = $record[$this->idx_key];
        return $record;
    }
}

class MixedZoneListFakeListform
{
    public $listDef = array(
        'name' => 'dbsdns_zone',
        'file' => 'zone_list.php',
        'page_params' => '',
        'table' => 'dbsdns_zone_list'
    );
    public $searchValues = array('search_origin' => 'example');
    public $pagingHTML = '';

    public function getSearchSQL($where)
    {
        return "dbsdns_zone_list.origin LIKE '%example%'";
    }

    public function getPagingHTML($values)
    {
        return 'paging:' . $values['records_gesamt'];
    }
}

class MixedZoneListFakeDb
{
    public $countQuery = '';

    public function queryOneRecord($query)
    {
        $this->countQuery = $query;
        return array('anzahl' => 2);
    }
}

class MixedZoneListFakeTpl
{
    public $values = array();

    public function setVar($key, $value = null)
    {
        if(is_array($key)) {
            $this->values = array_merge($this->values, $key);
        } else {
            $this->values[$key] = $value;
        }
    }
}

class MixedZoneListFakeFunctions
{
    public function intval($value)
    {
        return (int)$value;
    }
}

function mixedZoneListAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsZoneListActions.inc.php';

$testFailure = null;

try {
    global $app;

    $app = new stdClass();
    $app->listform = new MixedZoneListFakeListform();
    $app->db = new MixedZoneListFakeDb();
    $app->tpl = new MixedZoneListFakeTpl();
    $app->functions = new MixedZoneListFakeFunctions();
    $_SESSION = array(
        's' => array(
            'user' => array('typ' => 'user'),
            'module' => array('name' => 'dbsdns')
        ),
        'search' => array(
            'limit' => 15,
            'dbsdns_zone' => array('page' => 0)
        )
    );
    $_POST = array();
    $_REQUEST = array();

    $actions = new DbsZoneListActions(array(7));
    $actions->SQLOrderBy = 'ORDER BY dbsdns_zone_list.origin';
    $query = $actions->getQueryString();

    mixedZoneListAssertTrue(
        strpos($query, 'FROM dns_soa') === false,
        'Die eigenständige DBS-Zonenliste greift auf dns_soa zu.'
    );
    mixedZoneListAssertTrue(
        strpos($query, 'FROM dbsdns_zone_cache AS cache') !== false
        && strpos($query, 'INNER JOIN `domain` AS native_domain') !== false
        && strpos($query, 'cache.cache_id AS id') !== false
        && strpos($query, 'cache.cache_id IN (7)') !== false
        && strpos($query, 'UNION ALL') === false,
        'Die DBS-only Datenquelle oder ihre serverseitige Zugriffsmenge ist falsch.'
    );
    mixedZoneListAssertTrue(
        strpos($query, "dbsdns_zone_list.origin LIKE '%example%'") !== false
        && strpos($app->db->countQuery, "dbsdns_zone_list.origin LIKE '%example%'") !== false,
        'Die gemeinsame Suche wird nicht auf Daten- und Zählabfrage angewendet.'
    );
    mixedZoneListAssertTrue(
        strpos($query, 'ORDER BY dbsdns_zone_list.origin') !== false
        && strpos($query, 'LIMIT 0, 15') !== false
        && $app->tpl->values['paging'] === 'paging:2',
        'Gemeinsame Sortierung oder Pagination ist nicht aktiv.'
    );

    $dbsRow = $actions->prepareDataRow(array('id' => 7, 'server_id' => 'DBS'));
    mixedZoneListAssertTrue(
        $dbsRow['source'] === 'dbs' && $dbsRow['server_id'] === 'DBS' && $dbsRow['id'] === 7,
        'Eine DBS-Zeile wird nicht eindeutig dargestellt.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Eigenständige DBS-Zonenliste erfolgreich geprüft.' . PHP_EOL;
