<?php

// Execute the real CLI boundary; the fixture models transactional ISPConfig
// queries and a failure after the first user's UPDATE.
$root = sys_get_temp_dir() . '/dbsdns-permission-cli-' . bin2hex(random_bytes(6));
mkdir($root . '/lib', 0700, true);
file_put_contents($root . '/lib/config.inc.php', '<?php');
file_put_contents($root . '/lib/app.inc.php', <<<'PHP'
<?php
class PermissionCliDb
{
    public $errorMessage = '';
    private $updates = 0;

    public function queryOneRecord($sql, ...$arguments)
    {
        if(strpos($sql, 'information_schema.TABLES') !== false) {
            return array('engine' => trim(file_get_contents(__DIR__ . '/../engine')));
        }
        return null;
    }

    public function queryAllRecords($sql, ...$arguments)
    {
        if(strpos($sql, 'FROM sys_user') !== false) {
            $modules = is_file(__DIR__ . '/../uninstall') ? 'dns,dbsdns' : 'dns';
            return array(
                array('userid' => 1, 'typ' => 'admin', 'modules' => $modules, 'startmodule' => 'dns'),
                array('userid' => 2, 'typ' => 'admin', 'modules' => $modules, 'startmodule' => 'dns')
            );
        }
        return array();
    }

    public function query($sql, ...$arguments)
    {
        $event = strpos($sql, 'UPDATE sys_user') === 0 ? 'UPDATE' : $sql;
        file_put_contents(__DIR__ . '/../events', $event . "\n", FILE_APPEND);
        $this->errorMessage = '';
        if(is_file(__DIR__ . '/../false-query') && trim(file_get_contents(__DIR__ . '/../false-query')) === $event) {
            return false;
        }
        if($event === 'UPDATE' && ++$this->updates === 2 && is_file(__DIR__ . '/../fail')) {
            $this->errorMessage = 'Synthetic secret which must not appear in the CLI output';
        }
    }
}
$app = (object)array('db' => new PermissionCliDb());
PHP
);

function permissionCliRun($root, $preflight = false, $uninstall = false)
{
    @unlink($root . '/events');
    $command = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/../src/dbsdns/' . ($uninstall ? 'uninstall' : 'install') . '_module_permissions.php')
        . ' --interface-root ' . escapeshellarg($root)
        . ($preflight ? ($uninstall ? ' --dry-run' : ' --preflight') : '') . ' 2>&1';
    $lines = array();
    exec($command, $lines, $status);
    $events = is_file($root . '/events') ? file($root . '/events', FILE_IGNORE_NEW_LINES) : array();
    if(strpos(implode("\n", $lines), 'Synthetic secret') !== false) {
        throw new RuntimeException('CLI gibt interne Datenbankdetails aus.');
    }
    return array($status, $events);
}

try {
    file_put_contents($root . '/engine', 'InnoDB');
    if(permissionCliRun($root, true) !== array(0, array())) {
        throw new RuntimeException('Modulrechte-Preflight darf keine Transaktion oder Schreibabfrage starten.');
    }
    if(permissionCliRun($root) !== array(0, array('START TRANSACTION', 'UPDATE', 'UPDATE', 'COMMIT'))) {
        throw new RuntimeException('Erfolgreiche Modulzuweisungen werden nicht gemeinsam committed.');
    }
    file_put_contents($root . '/fail', '1');
    if(permissionCliRun($root) !== array(1, array('START TRANSACTION', 'UPDATE', 'UPDATE', 'ROLLBACK'))) {
        throw new RuntimeException('Teilweise fehlgeschlagene Modulzuweisungen werden nicht zurückgerollt.');
    }
    file_put_contents($root . '/engine', 'MyISAM');
    if(permissionCliRun($root, true) !== array(1, array()) || permissionCliRun($root) !== array(1, array())) {
        throw new RuntimeException('Nicht transaktionale Modulzuweisungen müssen vor Änderungen abbrechen.');
    }
    file_put_contents($root . '/engine', 'InnoDB');
    unlink($root . '/fail');
    file_put_contents($root . '/false-query', 'START TRANSACTION');
    if(permissionCliRun($root) !== array(1, array('START TRANSACTION'))) {
        throw new RuntimeException('Ein false-Rückgabewert beim Transaktionsstart wurde ignoriert.');
    }
    file_put_contents($root . '/false-query', 'COMMIT');
    if(permissionCliRun($root) !== array(1, array('START TRANSACTION', 'UPDATE', 'UPDATE', 'COMMIT', 'ROLLBACK'))) {
        throw new RuntimeException('Ein false-Rückgabewert beim Commit wurde ignoriert.');
    }
    unlink($root . '/false-query');
    file_put_contents($root . '/uninstall', '1');
    if(permissionCliRun($root, true, true) !== array(0, array())
        || permissionCliRun($root, false, true) !== array(0, array('START TRANSACTION', 'UPDATE', 'UPDATE', 'COMMIT'))) {
        throw new RuntimeException('Deinstallation verletzt Dry-Run- oder Transaktionsgrenzen.');
    }
    file_put_contents($root . '/fail', '1');
    if(permissionCliRun($root, false, true) !== array(1, array('START TRANSACTION', 'UPDATE', 'UPDATE', 'ROLLBACK'))) {
        throw new RuntimeException('Fehlgeschlagene Deinstallation hinterlässt teilweise bereinigte Modulzuweisungen.');
    }
    echo "CLI-Modulzuweisungen: Preflight, Commit und sekretfreier Rollback erfolgreich geprüft.\n";
} finally {
    foreach(array('lib/config.inc.php', 'lib/app.inc.php', 'events', 'engine', 'fail', 'false-query', 'uninstall') as $file) {
        if(is_file($root . '/' . $file)) unlink($root . '/' . $file);
    }
    rmdir($root . '/lib');
    rmdir($root);
}
