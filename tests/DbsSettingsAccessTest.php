<?php

function settingsAccessAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

function settingsAccessRemoveTree($path)
{
    if(!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach($iterator as $item) {
        if($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($path);
}

function settingsAccessRun($runner, $workingDirectory, $role)
{
    $environment = getenv();

    if(!is_array($environment)) {
        $environment = array();
    }

    $environment['DBSDNS_SETTINGS_TEST_ROLE'] = $role;
    $process = proc_open(
        array(PHP_BINARY, $runner),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        $workingDirectory,
        $environment,
        array('bypass_shell' => true)
    );

    if(!is_resource($process)) {
        throw new RuntimeException('Settings-Rollentest konnte nicht gestartet werden.');
    }

    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return array('exit_code' => proc_close($process), 'output' => $output);
}

$testFailure = null;
$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'dbsdns-settings-access-' . bin2hex(random_bytes(8));

try {
    $root = dirname(__DIR__);
    $interfaceRoot = $testRoot . DIRECTORY_SEPARATOR . 'interface';
    $moduleRoot = $interfaceRoot . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'dbsdns';
    $libRoot = $interfaceRoot . DIRECTORY_SEPARATOR . 'lib';
    $runner = $testRoot . DIRECTORY_SEPARATOR . 'run-settings.php';

    settingsAccessAssertTrue(
        mkdir($moduleRoot, 0777, true) && mkdir($libRoot, 0777, true),
        'Settings-Rollentestumgebung konnte nicht erstellt werden.'
    );
    settingsAccessAssertTrue(
        copy($root . '/src/dbsdns/settings.php', $moduleRoot . DIRECTORY_SEPARATOR . 'settings.php'),
        'Settings-Controller konnte nicht vorbereitet werden.'
    );
    file_put_contents($libRoot . DIRECTORY_SEPARATOR . 'config.inc.php', "<?php\n");
    file_put_contents(
        $libRoot . DIRECTORY_SEPARATOR . 'app.inc.php',
        <<<'PHP'
<?php

class DbsSettingsAccessFakeAuth
{
    public function check_module_permissions($module)
    {
    }

    public function is_admin()
    {
        return (string)getenv('DBSDNS_SETTINGS_TEST_ROLE') === 'admin';
    }
}

$app = new stdClass();
$app->auth = new DbsSettingsAccessFakeAuth();
PHP
    );
    file_put_contents(
        $runner,
        <<<'PHP'
<?php

$_SERVER['REQUEST_METHOD'] = 'GET';
require 'settings.php';
echo 'UNAUTHORIZED_ROUTE_CONTINUED';
PHP
    );

    foreach(array('customer', 'reseller') as $role) {
        $result = settingsAccessRun($runner, $moduleRoot, $role);
        settingsAccessAssertTrue(
            $result['exit_code'] === 0
            && strpos($result['output'], 'Access denied.') !== false
            && strpos($result['output'], 'UNAUTHORIZED_ROUTE_CONTINUED') === false,
            ucfirst($role) . ' erreicht die technische Settings-Route direkt.'
        );
    }
} catch (Throwable $exception) {
    $testFailure = $exception;
} finally {
    settingsAccessRemoveTree($testRoot);
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Direkter Settings-Zugriff für Kunde und Reseller serverseitig verweigert.' . PHP_EOL;
