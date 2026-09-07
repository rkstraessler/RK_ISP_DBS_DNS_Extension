<?php

function domainSyncFormAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

function domainSyncFormRemoveTree($path)
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

function domainSyncFormRun($runner, $workingDirectory, $method, $markerRoot)
{
    if(!is_dir($markerRoot) && !mkdir($markerRoot, 0777, true)) {
        throw new RuntimeException('Das Marker-Verzeichnis konnte nicht angelegt werden.');
    }

    $environment = getenv();

    if(!is_array($environment)) {
        $environment = array();
    }

    $environment['DBSDNS_DOMAIN_SYNC_METHOD'] = $method;
    $environment['DBSDNS_DOMAIN_SYNC_MARKER_ROOT'] = $markerRoot;
    $descriptors = array(
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w')
    );
    $process = proc_open(
        array(PHP_BINARY, $runner),
        $descriptors,
        $pipes,
        $workingDirectory,
        $environment,
        array('bypass_shell' => true)
    );

    if(!is_resource($process)) {
        throw new RuntimeException('Der Domain-Sync-Testprozess konnte nicht gestartet werden.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return array(
        'exit_code' => proc_close($process),
        'output' => $stdout . $stderr
    );
}

$testFailure = null;
$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'dbsdns-domain-sync-' . bin2hex(random_bytes(8));

try {
    $root = dirname(__DIR__);
    $template = file_get_contents($root . '/src/dbsdns/templates/domain_sync.htm');

    domainSyncFormAssertTrue(
        stripos($template, '<form') === false,
        'Das Domain-Sync-Template enthält weiterhin ein eigenes verschachteltes Formular.'
    );
    domainSyncFormAssertTrue(
        strpos($template, "type='button'") !== false
        && strpos($template, "data-submit-form='pageForm'") !== false
        && strpos($template, "data-form-action='dbsdns/domain_sync.php'") !== false,
        'Der Domain-Sync-Button verwendet nicht ISPConfigs funktionierenden pageForm-Mechanismus.'
    );
    domainSyncFormAssertTrue(
        strpos($template, "name='domain_sync' value='1'") !== false,
        'Der Domain-Sync-Aktionswert wird nicht mit dem pageForm übertragen.'
    );
    domainSyncFormAssertTrue(
        strpos($template, "name='_csrf_id' value='{tmpl_var name=\"_csrf_id\"}'") !== false
        && strpos($template, "name='_csrf_key' value='{tmpl_var name=\"_csrf_key\"}'") !== false,
        'Die CSRF-Werte liegen nicht mehr im übertragenen pageForm.'
    );

    $interfaceRoot = $testRoot . DIRECTORY_SEPARATOR . 'interface';
    $moduleRoot = $interfaceRoot . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'dbsdns';
    $classRoot = $moduleRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'classes';
    $languageRoot = $moduleRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'lang';
    $interfaceLibRoot = $interfaceRoot . DIRECTORY_SEPARATOR . 'lib';
    $runner = $testRoot . DIRECTORY_SEPARATOR . 'run-domain-sync.php';

    domainSyncFormAssertTrue(
        mkdir($classRoot, 0777, true)
        && mkdir($languageRoot, 0777, true)
        && mkdir($interfaceLibRoot, 0777, true),
        'Die Domain-Sync-Testumgebung konnte nicht angelegt werden.'
    );
    domainSyncFormAssertTrue(
        copy($root . '/src/dbsdns/domain_sync.php', $moduleRoot . DIRECTORY_SEPARATOR . 'domain_sync.php')
        && copy(
            $root . '/src/dbsdns/lib/lang/en_dbsdns.lng',
            $languageRoot . DIRECTORY_SEPARATOR . 'en_dbsdns.lng'
        ),
        'Die Domain-Sync-Testdateien konnten nicht vorbereitet werden.'
    );

    file_put_contents($interfaceLibRoot . DIRECTORY_SEPARATOR . 'config.inc.php', "<?php\n");
    file_put_contents(
        $interfaceLibRoot . DIRECTORY_SEPARATOR . 'app.inc.php',
        <<<'PHP'
<?php

function dbsdnsDomainSyncMark($name)
{
    $root = (string)getenv('DBSDNS_DOMAIN_SYNC_MARKER_ROOT');
    file_put_contents($root . DIRECTORY_SEPARATOR . $name . '.marker', '1');
}

class DbsDomainSyncFakeAuth
{
    public function check_module_permissions($module)
    {
    }

    public function is_admin()
    {
        return true;
    }

    public function csrf_token_check()
    {
        dbsdnsDomainSyncMark('csrf');
    }

    public function csrf_token_get($name)
    {
        return array('csrf_id' => 'test-id', 'csrf_key' => 'test-key');
    }
}

class DbsDomainSyncFakeFunctions
{
    public function check_language($language)
    {
        return 'en';
    }

    public function idn_encode($domain)
    {
        return $domain;
    }

    public function htmlentities($value)
    {
        return $value;
    }
}

class DbsDomainSyncFakeTemplate
{
    public function newTemplate($template)
    {
    }

    public function setInclude($name, $template)
    {
    }

    public function setVar($name, $value = null, $escape = false)
    {
    }

    public function pparse()
    {
    }
}

class DbsDomainSyncFakeApp
{
    public $auth;
    public $functions;
    public $tpl;
    public $db;

    public function __construct()
    {
        $this->auth = new DbsDomainSyncFakeAuth();
        $this->functions = new DbsDomainSyncFakeFunctions();
        $this->tpl = new DbsDomainSyncFakeTemplate();
        $this->db = new stdClass();
    }

    public function uses($classes)
    {
    }

    public function tpl_defaults()
    {
    }
}

$app = new DbsDomainSyncFakeApp();
PHP
    );
    file_put_contents(
        $classRoot . DIRECTORY_SEPARATOR . 'DbsClient.inc.php',
        <<<'PHP'
<?php

class DbsClientException extends RuntimeException
{
}

class DbsClient
{
    const ERROR_CONFIGURATION = 10001;
    const ERROR_SOAP_UNAVAILABLE = 10002;
    const ERROR_UNREACHABLE = 10003;
    const ERROR_REQUEST_FAILED = 10004;
    const ERROR_INVALID_RESPONSE = 10005;
    const ERROR_REQUEST_REJECTED = 10006;
    const ERROR_INVALID_INPUT = 10008;

    public function getDomainInventory()
    {
        dbsdnsDomainSyncMark('api');
        return array(
            'domains' => array(array('origin' => 'example.test', 'status' => 'active')),
            'empty_inventory_confirmed' => false
        );
    }
}
PHP
    );
    file_put_contents(
        $classRoot . DIRECTORY_SEPARATOR . 'DomainMatcher.inc.php',
        <<<'PHP'
<?php

class DomainMatcher
{
    public function __construct($encoder = null)
    {
    }
}
PHP
    );
    file_put_contents(
        $classRoot . DIRECTORY_SEPARATOR . 'ZoneCache.inc.php',
        <<<'PHP'
<?php

class ZoneCacheException extends RuntimeException
{
}

class ZoneCache
{
    public function __construct($db, $matcher)
    {
    }

    public function getSummary()
    {
        return array(
            'total_count' => 0,
            'present_count' => 0,
            'missing_count' => 0,
            'last_synced_at' => ''
        );
    }
}
PHP
    );
    file_put_contents(
        $classRoot . DIRECTORY_SEPARATOR . 'ZoneInventorySync.inc.php',
        <<<'PHP'
<?php

class ZoneInventorySyncException extends RuntimeException
{
    const ERROR_INVALID_INVENTORY = 2;
}

class ZoneInventorySync
{
    public function __construct($db, $matcher)
    {
    }

    public function synchronize($domains, $syncedAt = null, $emptyInventoryConfirmed = false)
    {
        if($syncedAt !== null || $emptyInventoryConfirmed !== false) {
            throw new RuntimeException('Ungültige Inventar-Metadaten.');
        }

        dbsdnsDomainSyncMark('sync');
        return array(
            'total' => count($domains),
            'created' => count($domains),
            'updated' => 0,
            'missing' => 0,
            'synced_at' => '2026-08-19 12:00:00'
        );
    }
}
PHP
    );
    file_put_contents(
        $runner,
        <<<'PHP'
<?php

$method = (string)getenv('DBSDNS_DOMAIN_SYNC_METHOD');
$_SERVER['REQUEST_METHOD'] = $method;
$_POST = $method === 'POST'
    ? array(
        'domain_sync' => '1',
        '_csrf_id' => 'test-id',
        '_csrf_key' => 'test-key'
    )
    : array();
$_SESSION = array('s' => array('language' => 'en'));

require 'domain_sync.php';
PHP
    );

    $getMarkerRoot = $testRoot . DIRECTORY_SEPARATOR . 'get-markers';
    $getResult = domainSyncFormRun($runner, $moduleRoot, 'GET', $getMarkerRoot);
    domainSyncFormAssertTrue(
        $getResult['exit_code'] === 0
        && !is_file($getMarkerRoot . DIRECTORY_SEPARATOR . 'csrf.marker')
        && !is_file($getMarkerRoot . DIRECTORY_SEPARATOR . 'api.marker')
        && !is_file($getMarkerRoot . DIRECTORY_SEPARATOR . 'sync.marker'),
        'Ein GET-Aufruf führt CSRF-, API- oder Inventar-Synchronisation aus.'
    );

    $postMarkerRoot = $testRoot . DIRECTORY_SEPARATOR . 'post-markers';
    $postResult = domainSyncFormRun($runner, $moduleRoot, 'POST', $postMarkerRoot);
    domainSyncFormAssertTrue(
        $postResult['exit_code'] === 0
        && is_file($postMarkerRoot . DIRECTORY_SEPARATOR . 'csrf.marker')
        && is_file($postMarkerRoot . DIRECTORY_SEPARATOR . 'api.marker')
        && is_file($postMarkerRoot . DIRECTORY_SEPARATOR . 'sync.marker'),
        'Ein gültiger POST-Aufruf führt CSRF-Prüfung, DBS-Abruf oder Inventar-Sync nicht aus.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
} finally {
    domainSyncFormRemoveTree($testRoot);
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'DBS-Domain-Sync-pageForm und GET/POST-Verhalten erfolgreich geprüft.' . PHP_EOL;
