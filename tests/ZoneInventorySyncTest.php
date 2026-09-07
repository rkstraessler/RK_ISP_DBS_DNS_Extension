<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DomainMatcher.inc.php';
require_once __DIR__ . '/../src/dbsdns/lib/classes/ZoneInventorySync.inc.php';

class ZoneInventorySyncFakeDb
{
    public $errorMessage = '';
    public $queries = array();
    public $records = array();
    public $failOnDomain = '';
    public $readCount = 0;
    private $snapshot = null;

    public function __construct($records)
    {
        foreach($records as $record) {
            $this->records[$record['domain']] = $record;
        }
    }

    public function queryAllRecords($query)
    {
        $this->readCount++;
        $records = array();

        foreach($this->records as $record) {
            $records[] = array(
                'domain' => $record['domain'],
                'provider_present' => $record['provider_present']
            );
        }

        return $records;
    }

    public function query($query)
    {
        $arguments = func_get_args();
        $this->queries[] = $query;

        if($query === 'START TRANSACTION') {
            $this->snapshot = $this->records;
            $this->errorMessage = '';
            return true;
        }

        if($query === 'COMMIT') {
            $this->snapshot = null;
            return true;
        }

        if($query === 'ROLLBACK') {
            if($this->snapshot !== null) {
                $this->records = $this->snapshot;
            }
            $this->snapshot = null;
            $this->errorMessage = '';
            return true;
        }

        if(strpos($query, 'SET provider_present') !== false) {
            $syncedAt = $arguments[1];

            foreach($this->records as $domain => $record) {
                $this->records[$domain]['provider_present'] = 'N';
                $this->records[$domain]['last_synced_at'] = $syncedAt;
            }

            return true;
        }

        if(strpos($query, 'INSERT INTO `dbsdns_zone_cache`') !== false) {
            $domain = $arguments[1];

            if($domain === $this->failOnDomain) {
                $this->errorMessage = 'simulated failure';
                return false;
            }

            $status = $arguments[2];
            $firstSeenAt = $arguments[3];
            $lastSeenAt = $arguments[4];
            $lastSyncedAt = $arguments[5];

            if(isset($this->records[$domain])) {
                $this->records[$domain]['provider_status'] = $status;
                $this->records[$domain]['provider_present'] = 'Y';
                $this->records[$domain]['last_seen_at'] = $lastSeenAt;
                $this->records[$domain]['last_synced_at'] = $lastSyncedAt;
            } else {
                $this->records[$domain] = array(
                    'domain' => $domain,
                    'provider_status' => $status,
                    'provider_present' => 'Y',
                    'first_seen_at' => $firstSeenAt,
                    'last_seen_at' => $lastSeenAt,
                    'last_synced_at' => $lastSyncedAt
                );
            }

            return true;
        }

        return true;
    }
}

function zoneInventoryAssertSame($expected, $actual, $message)
{
    if($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function zoneInventoryAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

function zoneInventoryRecord($domain, $present, $firstSeenAt)
{
    return array(
        'domain' => $domain,
        'provider_status' => 'old',
        'provider_present' => $present,
        'first_seen_at' => $firstSeenAt,
        'last_seen_at' => $firstSeenAt,
        'last_synced_at' => $firstSeenAt
    );
}

$testFailure = null;

try {
    $matcher = new DomainMatcher(function($domain) {
        return $domain;
    });
    $db = new ZoneInventorySyncFakeDb(array(
        zoneInventoryRecord('keep.example', 'Y', '2026-01-01 00:00:00'),
        zoneInventoryRecord('gone.example', 'Y', '2026-01-02 00:00:00'),
        zoneInventoryRecord('already-missing.example', 'N', '2026-01-03 00:00:00')
    ));
    $sync = new ZoneInventorySync($db, $matcher);
    $result = $sync->synchronize(array(
        array('origin' => 'KEEP.EXAMPLE.', 'status' => 'active'),
        array('origin' => 'new.example', 'status' => 'active')
    ), '2026-08-19 10:00:00');

    zoneInventoryAssertSame(2, $result['total'], 'Die vollständige Inventargröße ist falsch.');
    zoneInventoryAssertSame(1, $result['created'], 'Neue Domains werden nicht korrekt gezählt.');
    zoneInventoryAssertSame(1, $result['updated'], 'Bestehende Domains werden nicht korrekt gezählt.');
    zoneInventoryAssertSame(1, $result['missing'], 'Verschwundene Domains werden nicht korrekt gezählt.');
    zoneInventoryAssertSame('Y', $db->records['keep.example']['provider_present'], 'Eine vorhandene Domain wurde deaktiviert.');
    zoneInventoryAssertSame('N', $db->records['gone.example']['provider_present'], 'Eine verschwundene Domain wurde nicht inaktiv markiert.');
    zoneInventoryAssertTrue(isset($db->records['gone.example']), 'Eine verschwundene Domain wurde gelöscht.');
    zoneInventoryAssertSame('2026-01-01 00:00:00', $db->records['keep.example']['first_seen_at'], 'first_seen_at wurde überschrieben.');
    zoneInventoryAssertSame('2026-08-19 10:00:00', $db->records['new.example']['first_seen_at'], 'Eine neue Domain erhält keinen first_seen_at-Wert.');

    foreach($db->queries as $query) {
        zoneInventoryAssertTrue(stripos($query, 'DELETE') === false, 'Der Inventar-Sync löscht Cache-Datensätze.');
    }

    $unexpectedEmptyDb = new ZoneInventorySyncFakeDb(array(
        zoneInventoryRecord('stable.example', 'Y', '2026-01-01 00:00:00')
    ));
    $unexpectedEmptyRecords = $unexpectedEmptyDb->records;
    $unexpectedEmptyCaught = false;

    try {
        (new ZoneInventorySync($unexpectedEmptyDb, $matcher))->synchronize(
            array(),
            '2026-08-19 10:30:00'
        );
    } catch (ZoneInventorySyncException $exception) {
        $unexpectedEmptyCaught = $exception->getCode()
            === ZoneInventorySyncException::ERROR_INVALID_INVENTORY;
    }

    zoneInventoryAssertTrue($unexpectedEmptyCaught, 'Ein unerwartet leeres Inventar wird nicht abgewiesen.');
    zoneInventoryAssertSame(0, $unexpectedEmptyDb->readCount, 'Ein unerwartet leeres Inventar liest oder verändert den Cache.');
    zoneInventoryAssertSame(array(), $unexpectedEmptyDb->queries, 'Ein unerwartet leeres Inventar startet eine Transaktion.');
    zoneInventoryAssertSame($unexpectedEmptyRecords, $unexpectedEmptyDb->records, 'Ein unerwartet leeres Inventar zerstört den bestehenden Cache.');

    $confirmedEmptyDb = new ZoneInventorySyncFakeDb(array(
        zoneInventoryRecord('first.example', 'Y', '2026-01-01 00:00:00'),
        zoneInventoryRecord('second.example', 'Y', '2026-01-02 00:00:00')
    ));
    $confirmedEmptyResult = (new ZoneInventorySync($confirmedEmptyDb, $matcher))->synchronize(
        array(),
        '2026-08-19 10:45:00',
        true
    );

    zoneInventoryAssertSame(0, $confirmedEmptyResult['total'], 'Ein ausdrücklich leeres Inventar hat eine falsche Größe.');
    zoneInventoryAssertSame(2, $confirmedEmptyResult['missing'], 'Ein ausdrücklich leeres Inventar markiert den alten Bestand nicht korrekt.');
    zoneInventoryAssertSame('N', $confirmedEmptyDb->records['first.example']['provider_present'], 'Ein bestätigtes leeres Inventar wurde nicht angewendet.');
    zoneInventoryAssertSame('N', $confirmedEmptyDb->records['second.example']['provider_present'], 'Ein bestätigtes leeres Inventar wurde nicht vollständig angewendet.');

    $failureDb = new ZoneInventorySyncFakeDb(array(
        zoneInventoryRecord('stable.example', 'Y', '2026-01-01 00:00:00')
    ));
    $failureDb->failOnDomain = 'fail.example';
    $failureSync = new ZoneInventorySync($failureDb, $matcher);
    $failureCaught = false;

    try {
        $failureSync->synchronize(array(
            array('origin' => 'fail.example', 'status' => 'active')
        ), '2026-08-19 11:00:00');
    } catch (ZoneInventorySyncException $exception) {
        $failureCaught = true;
    }

    zoneInventoryAssertTrue($failureCaught, 'Ein Datenbankfehler wird nicht gemeldet.');
    zoneInventoryAssertSame('Y', $failureDb->records['stable.example']['provider_present'], 'Ein fehlgeschlagener Sync zerstört den bestehenden Cache.');
    zoneInventoryAssertTrue(!isset($failureDb->records['fail.example']), 'Ein fehlgeschlagener Sync hinterlässt einen Teilstand.');

    $readFailureDb = new ZoneInventorySyncFakeDb(array(
        zoneInventoryRecord('stable.example', 'Y', '2026-01-01 00:00:00')
    ));
    $readFailureDb->errorMessage = 'simulated read failure';
    $readFailureCaught = false;

    try {
        (new ZoneInventorySync($readFailureDb, $matcher))->synchronize(array(
            array('origin' => 'stable.example', 'status' => 'active')
        ), '2026-08-19 12:00:00');
    } catch (ZoneInventorySyncException $exception) {
        $readFailureCaught = true;
    }

    zoneInventoryAssertTrue($readFailureCaught, 'Ein von ISPConfig als leeres Array zurückgegebener SQL-Lesefehler wird nicht erkannt.');
    zoneInventoryAssertSame(0, count($readFailureDb->queries), 'Nach einem SQL-Lesefehler wurde dennoch eine Cache-Transaktion gestartet.');
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'DBS-Domaininventar-Sync erfolgreich geprüft.' . PHP_EOL;
