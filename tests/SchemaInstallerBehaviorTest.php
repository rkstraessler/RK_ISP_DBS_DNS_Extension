<?php

function schemaInstallerAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

function schemaInstallerRemoveTree($path)
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

function schemaInstallerRun($script, $scenario, $queryMarker, $arguments = array())
{
    if(is_file($queryMarker)) {
        unlink($queryMarker);
    }

    $environment = getenv();

    if(!is_array($environment)) {
        $environment = array();
    }

    $environment['DBSDNS_SCHEMA_TEST_SCENARIO'] = $scenario;
    $environment['DBSDNS_SCHEMA_QUERY_MARKER'] = $queryMarker;
    $descriptors = array(
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w')
    );
    $process = proc_open(
        array_merge(array(PHP_BINARY, $script), $arguments),
        $descriptors,
        $pipes,
        null,
        $environment,
        array('bypass_shell' => true)
    );

    if(!is_resource($process)) {
        throw new RuntimeException('Der Schema-Installer-Testprozess konnte nicht gestartet werden.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return array(
        'exit_code' => proc_close($process),
        'output' => $stdout . $stderr,
        'query_marker_exists' => is_file($queryMarker)
    );
}

$testFailure = null;
$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'dbsdns-schema-installer-' . bin2hex(random_bytes(8));

try {
    $root = dirname(__DIR__);
    $interfaceRoot = $testRoot . DIRECTORY_SEPARATOR . 'interface';
    $moduleRoot = $interfaceRoot . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'dbsdns';
    $libRoot = $interfaceRoot . DIRECTORY_SEPARATOR . 'lib';
    $schemaRoot = $moduleRoot . DIRECTORY_SEPARATOR . 'sql';
    $queryMarker = $testRoot . DIRECTORY_SEPARATOR . 'schema-query.txt';
    $installerTarget = $moduleRoot . DIRECTORY_SEPARATOR . 'install_schema.php';

    schemaInstallerAssertTrue(
        mkdir($schemaRoot, 0777, true) && mkdir($libRoot, 0777, true),
        'Die Schema-Installer-Testumgebung konnte nicht angelegt werden.'
    );
    schemaInstallerAssertTrue(
        copy($root . '/src/dbsdns/install_schema.php', $installerTarget),
        'Der Schema-Installer konnte nicht in die Testumgebung kopiert werden.'
    );
    schemaInstallerAssertTrue(
        copy(
            $root . '/src/dbsdns/sql/dbsdns_zone_cache.sql',
            $schemaRoot . DIRECTORY_SEPARATOR . 'dbsdns_zone_cache.sql'
        ),
        'Die Schema-Datei konnte nicht in die Testumgebung kopiert werden.'
    );
    file_put_contents(
        $libRoot . DIRECTORY_SEPARATOR . 'config.inc.php',
        "<?php\n\$conf = array('database' => array('database' => 'dbispconfig_test'));\n"
    );
    file_put_contents(
        $libRoot . DIRECTORY_SEPARATOR . 'app.inc.php',
        <<<'PHP'
<?php

class DbsSchemaInstallerFakeDb
{
    public $errorMessage = '';
    private $scenario;

    public function __construct()
    {
        $this->scenario = (string)getenv('DBSDNS_SCHEMA_TEST_SCENARIO');
    }

    public function queryOneRecord($query)
    {
        $this->errorMessage = '';

        $arguments = func_get_args();

        if(strpos($query, 'information_schema.TABLES') === false) {
            $this->errorMessage = 'unexpected existence query';
            return null;
        }

        $tableName = isset($arguments[1]) ? (string)$arguments[1] : '';

        if(
            $this->scenario === 'missing'
            || ($this->scenario === 'missing_settings' && $tableName === 'dbsdns_settings')
        ) {
            return null;
        }

        return array(
            'table_name' => $tableName,
            'table_collation' => $this->scenario === 'wrong_collation'
                ? 'utf8mb4_uca1400_ai_ci'
                : 'utf8mb4_unicode_ci'
        );
    }

    public function queryAllRecords($query)
    {
        $this->errorMessage = '';

        if($query === 'SHOW FULL COLUMNS FROM `dbsdns_zone_cache`') {
            $domainType = $this->scenario === 'incompatible' ? 'varchar(254)' : 'varchar(255)';
            $textCollation = $this->scenario === 'wrong_collation'
                ? 'utf8mb4_uca1400_ai_ci'
                : 'utf8mb4_unicode_ci';

            return array(
                array('Field' => 'cache_id', 'Type' => 'bigint(20) unsigned', 'Collation' => null, 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'),
                array('Field' => 'domain', 'Type' => $domainType, 'Collation' => $textCollation, 'Null' => 'NO', 'Default' => null, 'Extra' => ''),
                array('Field' => 'provider_status', 'Type' => 'varchar(64)', 'Collation' => $textCollation, 'Null' => 'NO', 'Default' => '', 'Extra' => ''),
                array('Field' => 'provider_present', 'Type' => "enum('N','Y')", 'Collation' => $textCollation, 'Null' => 'NO', 'Default' => 'Y', 'Extra' => ''),
                array('Field' => 'first_seen_at', 'Type' => 'datetime', 'Collation' => null, 'Null' => 'NO', 'Default' => null, 'Extra' => ''),
                array('Field' => 'last_seen_at', 'Type' => 'datetime', 'Collation' => null, 'Null' => 'NO', 'Default' => null, 'Extra' => ''),
                array('Field' => 'last_synced_at', 'Type' => 'datetime', 'Collation' => null, 'Null' => 'NO', 'Default' => null, 'Extra' => '')
            );
        }

        if($query === 'SHOW INDEX FROM `dbsdns_zone_cache`') {
            return array(
                array('Key_name' => 'PRIMARY', 'Column_name' => 'cache_id', 'Seq_in_index' => 1, 'Non_unique' => 0),
                array('Key_name' => 'domain', 'Column_name' => 'domain', 'Seq_in_index' => 1, 'Non_unique' => 0),
                array('Key_name' => 'provider_present_domain', 'Column_name' => 'provider_present', 'Seq_in_index' => 1, 'Non_unique' => 1),
                array('Key_name' => 'provider_present_domain', 'Column_name' => 'domain', 'Seq_in_index' => 2, 'Non_unique' => 1)
            );
        }

        if($query === 'SHOW FULL COLUMNS FROM `dbsdns_settings`') {
            $textCollation = $this->scenario === 'wrong_collation'
                ? 'utf8mb4_uca1400_ai_ci'
                : 'utf8mb4_unicode_ci';

            return array(
                array('Field' => 'settings_id', 'Type' => 'tinyint(3) unsigned', 'Collation' => null, 'Null' => 'NO', 'Default' => null, 'Extra' => ''),
                array('Field' => 'wsdl_url', 'Type' => 'varchar(2048)', 'Collation' => $textCollation, 'Null' => 'NO', 'Default' => '', 'Extra' => ''),
                array('Field' => 'username', 'Type' => 'varchar(255)', 'Collation' => $textCollation, 'Null' => 'NO', 'Default' => '', 'Extra' => ''),
                array('Field' => 'password_ciphertext', 'Type' => 'text', 'Collation' => 'ascii_bin', 'Null' => 'NO', 'Default' => null, 'Extra' => ''),
                array('Field' => 'connection_status', 'Type' => "enum('untested','success','failure')", 'Collation' => $textCollation, 'Null' => 'NO', 'Default' => 'untested', 'Extra' => ''),
                array('Field' => 'connection_source', 'Type' => 'varchar(16)', 'Collation' => $textCollation, 'Null' => 'NO', 'Default' => '', 'Extra' => ''),
                array('Field' => 'last_tested_at', 'Type' => 'datetime', 'Collation' => null, 'Null' => 'YES', 'Default' => null, 'Extra' => ''),
                array('Field' => 'updated_at', 'Type' => 'datetime', 'Collation' => null, 'Null' => 'NO', 'Default' => null, 'Extra' => '')
            );
        }

        if($query === 'SHOW INDEX FROM `dbsdns_settings`') {
            return array(
                array('Key_name' => 'PRIMARY', 'Column_name' => 'settings_id', 'Seq_in_index' => 1, 'Non_unique' => 0)
            );
        }

        $this->errorMessage = 'unexpected metadata query';
        return array();
    }

    public function query($query)
    {
        file_put_contents((string)getenv('DBSDNS_SCHEMA_QUERY_MARKER'), $query);
        $this->errorMessage = 'schema mutation attempted';
        return false;
    }
}

$app = new stdClass();
$app->db = new DbsSchemaInstallerFakeDb();
PHP
    );

    $databaseName = schemaInstallerRun(
        $installerTarget,
        'compatible',
        $queryMarker,
        array('--database-name')
    );
    schemaInstallerAssertTrue(
        $databaseName['exit_code'] === 0
        && trim($databaseName['output']) === 'dbispconfig_test'
        && !$databaseName['query_marker_exists'],
        'Der konfigurierte ISPConfig-Datenbankname wird nicht mutationsfrei ermittelt.'
    );

    $compatible = schemaInstallerRun($installerTarget, 'compatible', $queryMarker);
    schemaInstallerAssertTrue(
        $compatible['exit_code'] === 0
        && strpos($compatible['output'], 'DBS-DNS-Cache- und Settings-Schema sind bereit.') !== false,
        'Vorhandene kompatible Cache- und Settings-Tabellen werden nicht akzeptiert.'
    );
    schemaInstallerAssertTrue(
        !$compatible['query_marker_exists'],
        'Bei einer vorhandenen kompatiblen Tabelle wurde eine Schema-Mutation versucht.'
    );

    $incompatible = schemaInstallerRun($installerTarget, 'incompatible', $queryMarker);
    schemaInstallerAssertTrue(
        $incompatible['exit_code'] !== 0
        && strpos($incompatible['output'], 'Bestehendes DBS-DNS-Schema ist nicht kompatibel.') !== false,
        'Eine vorhandene inkompatible Tabelle wird nicht verständlich abgewiesen.'
    );
    schemaInstallerAssertTrue(
        !$incompatible['query_marker_exists'],
        'Bei einer vorhandenen inkompatiblen Tabelle wurde eine Schema-Mutation versucht.'
    );

    $wrongCollation = schemaInstallerRun($installerTarget, 'wrong_collation', $queryMarker);
    schemaInstallerAssertTrue(
        $wrongCollation['exit_code'] === 11
        && strpos($wrongCollation['output'], 'verwendet nicht utf8mb4_unicode_ci') !== false,
        'Eine vorhandene Tabelle mit falscher Collation wird nicht eindeutig erkannt.'
    );
    schemaInstallerAssertTrue(
        !$wrongCollation['query_marker_exists'],
        'Bei einer falschen Collation wurde über den ISPConfig-DB-User eine Migration versucht.'
    );

    $missing = schemaInstallerRun($installerTarget, 'missing', $queryMarker);
    schemaInstallerAssertTrue(
        $missing['exit_code'] !== 0
        && strpos($missing['output'], 'Mindestens eine DBS-DNS-Tabelle') !== false
        && strpos($missing['output'], 'privilegierten MariaDB-Benutzer') !== false
        && strpos($missing['output'], 'mariadb dbispconfig_test < ') !== false
        && strpos($missing['output'], 'scripts/install.sh erneut') !== false,
        'Eine fehlende Tabelle liefert keine verständliche manuelle Installationsanweisung.'
    );
    schemaInstallerAssertTrue(
        !$missing['query_marker_exists'],
        'Bei einer fehlenden Tabelle wurde eine Schema-Mutation versucht.'
    );

    $missingSettings = schemaInstallerRun($installerTarget, 'missing_settings', $queryMarker);
    schemaInstallerAssertTrue(
        $missingSettings['exit_code'] === 10
        && strpos($missingSettings['output'], '`dbsdns_settings`') !== false
        && !$missingSettings['query_marker_exists'],
        'Ein Upgrade mit vorhandenem Cache und fehlender Settings-Tabelle wird nicht additiv erkannt.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
} finally {
    schemaInstallerRemoveTree($testRoot);
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Schema-Installer-Verhalten erfolgreich geprüft.' . PHP_EOL;
