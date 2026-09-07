<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsSessionWriteAccess.inc.php';

class SessionWriteAuth
{
    public $admin = false;

    public function is_admin()
    {
        return $this->admin;
    }
}

class SessionWriteFunctions
{
    public function intval($value)
    {
        return (int)$value;
    }
}

class SessionWriteDb
{
    public $locked = 'n';
    public $calls = 0;
    public $result = null;

    public function queryOneRecord($sql, $groupId)
    {
        $this->calls++;

        if($this->result !== null) {
            return $this->result;
        }

        return array('locked' => $this->locked);
    }
}

function sessionWriteAssertSame($expected, $actual, $message)
{
    if($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

$testFailure = null;

try {
    $app = new stdClass();
    $app->auth = new SessionWriteAuth();
    $app->functions = new SessionWriteFunctions();
    $app->db = new SessionWriteDb();
    $user = array('default_group' => '12');

    sessionWriteAssertSame(true, DbsSessionWriteAccess::mayWrite($app, $user), 'Ein aktiver Kunde wird fälschlich blockiert.');
    $app->db->locked = 'y';
    sessionWriteAssertSame(false, DbsSessionWriteAccess::mayWrite($app, $user), 'Ein gesperrter Kunde darf weiterhin schreiben.');
    $app->db->result = false;
    sessionWriteAssertSame(false, DbsSessionWriteAccess::mayWrite($app, $user), 'Ein fehlgeschlagener Client-Lookup wird nicht sicher blockiert.');
    $app->db->result = array();
    sessionWriteAssertSame(false, DbsSessionWriteAccess::mayWrite($app, $user), 'Ein Client-Lookup ohne Lock-Status wird nicht sicher blockiert.');
    $app->db->result = array('locked' => 'unknown');
    sessionWriteAssertSame(false, DbsSessionWriteAccess::mayWrite($app, $user), 'Ein unbekannter Lock-Status wird nicht sicher blockiert.');
    $app->db->result = null;
    $app->auth->admin = true;
    sessionWriteAssertSame(true, DbsSessionWriteAccess::mayWrite($app, array()), 'Ein Admin wird vom Kunden-Lock blockiert.');

    $recordPage = file_get_contents(__DIR__ . '/../src/dbsdns/lib/classes/DbsRecordPage.inc.php');
    $recordDelete = file_get_contents(__DIR__ . '/../src/dbsdns/record_delete.php');
    $zoneSettings = file_get_contents(__DIR__ . '/../src/dbsdns/zone_settings.php');
    sessionWriteAssertSame(true,
        strpos($recordPage, 'DbsSessionWriteAccess::mayWrite') !== false
        && strpos($recordDelete, 'DbsSessionWriteAccess::mayWrite') !== false
        && strpos($zoneSettings, 'DbsSessionWriteAccess::mayWrite') !== false,
        'Create/Edit/Delete und Zoneneinstellungen verwenden nicht denselben Session-Write-Guard.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Einheitlicher Schreibschutz für Admins, aktive und gesperrte Kunden erfolgreich geprüft.' . PHP_EOL;
