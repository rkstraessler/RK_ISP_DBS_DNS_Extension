<?php

if(PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Access denied.');
}

$exitSchemaMissing = 10;
$exitSchemaCollation = 11;
$exitSchemaIncompatible = 12;
$exitInspectionFailed = 20;
$interfaceRoot = dirname(__DIR__, 2);
$inspectSecretState = false;
$inspectDatabaseName = false;

for($argumentIndex = 1; $argumentIndex < $argc; $argumentIndex++) {
    if($argv[$argumentIndex] === '--interface-root' && isset($argv[$argumentIndex + 1])) {
        $interfaceRoot = rtrim((string)$argv[++$argumentIndex], "/\\");
    } elseif($argv[$argumentIndex] === '--settings-secret-state') {
        $inspectSecretState = true;
    } elseif($argv[$argumentIndex] === '--database-name') {
        $inspectDatabaseName = true;
    } else {
        fwrite(STDERR, "Verwendung: php install_schema.php [--interface-root PFAD] [--settings-secret-state|--database-name]\n");
        exit(2);
    }
}

if($inspectSecretState && $inspectDatabaseName) {
    fwrite(STDERR, "--settings-secret-state und --database-name dürfen nicht kombiniert werden.\n");
    exit(2);
}

$configFile = $interfaceRoot . '/lib/config.inc.php';
$appFile = $interfaceRoot . '/lib/app.inc.php';
$schemaFile = __DIR__ . '/sql/dbsdns_zone_cache.sql';

if(!is_file($configFile) || !is_file($appFile) || !is_file($schemaFile)) {
    fwrite(STDERR, "DBS-DNS-Schemaquellen unvollständig.\n");
    exit($exitInspectionFailed);
}

require_once $configFile;
require_once $appFile;

$databaseName = 'dbispconfig';

if(
    isset($conf['database']['database'])
    && is_string($conf['database']['database'])
    && preg_match('/\A[A-Za-z0-9_]+\z/', $conf['database']['database'])
) {
    $databaseName = $conf['database']['database'];
}

if($inspectDatabaseName) {
    fwrite(STDOUT, $databaseName . "\n");
    exit(0);
}

function dbsdnsSchemaFail($message, $exitCode)
{
    fwrite(STDERR, $message . "\n");
    exit($exitCode);
}

function dbsdnsSchemaHasDatabaseError($db)
{
    return property_exists($db, 'errorMessage') && $db->errorMessage !== '';
}

function dbsdnsSchemaReadTable($db, $tableName, $inspectionExitCode)
{
    $table = $db->queryOneRecord(
        "SELECT TABLE_NAME AS table_name, TABLE_COLLATION AS table_collation\n"
        . "FROM information_schema.TABLES\n"
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        $tableName
    );

    if(dbsdnsSchemaHasDatabaseError($db)) {
        dbsdnsSchemaFail('Die Existenz der DBS-DNS-Tabellen konnte nicht geprüft werden.', $inspectionExitCode);
    }

    if(
        !is_array($table)
        || !isset($table['table_name'])
        || (string)$table['table_name'] !== $tableName
    ) {
        return null;
    }

    return $table;
}

function dbsdnsSchemaReadColumns($db, $tableName, $inspectionExitCode)
{
    $columns = $db->queryAllRecords('SHOW FULL COLUMNS FROM `' . $tableName . '`');

    if(!is_array($columns) || dbsdnsSchemaHasDatabaseError($db)) {
        dbsdnsSchemaFail('DBS-DNS-Schema konnte nicht geprüft werden.', $inspectionExitCode);
    }

    $columnsByName = array();

    foreach($columns as $column) {
        if(isset($column['Field'])) {
            $columnsByName[(string)$column['Field']] = $column;
        }
    }

    return $columnsByName;
}

function dbsdnsSchemaValidateColumns(
    $columnsByName,
    $requiredTypes,
    $requiredNullability,
    $requiredDefaults,
    $requiredCollations,
    &$collationCompatible,
    $incompatibleExitCode
) {
    foreach($requiredTypes as $requiredColumn => $typePattern) {
        if(!isset($columnsByName[$requiredColumn])) {
            dbsdnsSchemaFail('Bestehendes DBS-DNS-Schema ist nicht kompatibel.', $incompatibleExitCode);
        }

        $column = $columnsByName[$requiredColumn];
        $columnType = isset($column['Type']) ? (string)$column['Type'] : '';
        $nullable = isset($column['Null']) ? strtoupper((string)$column['Null']) : '';
        $default = array_key_exists('Default', $column) ? $column['Default'] : false;

        if(
            preg_match($typePattern, $columnType) !== 1
            || $nullable !== $requiredNullability[$requiredColumn]
            || $default !== $requiredDefaults[$requiredColumn]
        ) {
            dbsdnsSchemaFail('Bestehendes DBS-DNS-Schema ist nicht kompatibel.', $incompatibleExitCode);
        }

        if(isset($requiredCollations[$requiredColumn])) {
            $columnCollation = isset($column['Collation'])
                ? strtolower((string)$column['Collation'])
                : '';

            if($columnCollation !== $requiredCollations[$requiredColumn]) {
                $collationCompatible = false;
            }
        }
    }
}

function dbsdnsSchemaReadIndexes($db, $tableName, $inspectionExitCode)
{
    $indexes = $db->queryAllRecords('SHOW INDEX FROM `' . $tableName . '`');

    if(!is_array($indexes) || dbsdnsSchemaHasDatabaseError($db)) {
        dbsdnsSchemaFail('DBS-DNS-Indizes konnten nicht geprüft werden.', $inspectionExitCode);
    }

    $columns = array();
    $unique = array();

    foreach($indexes as $index) {
        $keyName = isset($index['Key_name']) ? (string)$index['Key_name'] : '';
        $columnName = isset($index['Column_name']) ? (string)$index['Column_name'] : '';
        $sequence = isset($index['Seq_in_index']) ? (int)$index['Seq_in_index'] : 0;

        if($keyName === '' || $columnName === '' || $sequence <= 0) {
            continue;
        }

        if(!isset($columns[$keyName])) {
            $columns[$keyName] = array();
        }

        $columns[$keyName][$sequence] = $columnName;
        $unique[$keyName] = isset($index['Non_unique']) && (int)$index['Non_unique'] === 0;
    }

    foreach($columns as $keyName => $indexColumns) {
        ksort($indexColumns, SORT_NUMERIC);
        $columns[$keyName] = array_values($indexColumns);
    }

    return array('columns' => $columns, 'unique' => $unique);
}

$cacheTable = dbsdnsSchemaReadTable($app->db, 'dbsdns_zone_cache', $exitInspectionFailed);
$settingsTable = dbsdnsSchemaReadTable($app->db, 'dbsdns_settings', $exitInspectionFailed);
$collationCompatible = true;

if($cacheTable !== null) {
    if(strtolower((string)$cacheTable['table_collation']) !== 'utf8mb4_unicode_ci') {
        $collationCompatible = false;
    }

    $cacheColumns = dbsdnsSchemaReadColumns($app->db, 'dbsdns_zone_cache', $exitInspectionFailed);
    $cacheTypes = array(
        'cache_id' => '/\Abigint(?:\([0-9]+\))? unsigned\z/i',
        'domain' => '/\Avarchar\(255\)\z/i',
        'provider_status' => '/\Avarchar\(64\)\z/i',
        'provider_present' => '/\Aenum\(\x27N\x27,\x27Y\x27\)\z/i',
        'first_seen_at' => '/\Adatetime(?:\(0\))?\z/i',
        'last_seen_at' => '/\Adatetime(?:\(0\))?\z/i',
        'last_synced_at' => '/\Adatetime(?:\(0\))?\z/i'
    );
    $cacheNullability = array_fill_keys(array_keys($cacheTypes), 'NO');
    $cacheDefaults = array(
        'cache_id' => null,
        'domain' => null,
        'provider_status' => '',
        'provider_present' => 'Y',
        'first_seen_at' => null,
        'last_seen_at' => null,
        'last_synced_at' => null
    );
    $cacheCollations = array(
        'domain' => 'utf8mb4_unicode_ci',
        'provider_status' => 'utf8mb4_unicode_ci',
        'provider_present' => 'utf8mb4_unicode_ci'
    );
    dbsdnsSchemaValidateColumns(
        $cacheColumns,
        $cacheTypes,
        $cacheNullability,
        $cacheDefaults,
        $cacheCollations,
        $collationCompatible,
        $exitSchemaIncompatible
    );

    $cacheIdExtra = isset($cacheColumns['cache_id']['Extra'])
        ? strtolower((string)$cacheColumns['cache_id']['Extra'])
        : '';

    if(strpos($cacheIdExtra, 'auto_increment') === false) {
        dbsdnsSchemaFail('Bestehendes DBS-DNS-Schema ist nicht kompatibel.', $exitSchemaIncompatible);
    }

    $cacheIndexes = dbsdnsSchemaReadIndexes($app->db, 'dbsdns_zone_cache', $exitInspectionFailed);
    $hasPrimaryCacheId = isset($cacheIndexes['columns']['PRIMARY'])
        && $cacheIndexes['columns']['PRIMARY'] === array('cache_id');
    $hasUniqueDomain = false;
    $hasPresentDomainIndex = false;

    foreach($cacheIndexes['columns'] as $keyName => $indexColumns) {
        if(isset($cacheIndexes['unique'][$keyName]) && $cacheIndexes['unique'][$keyName] && $indexColumns === array('domain')) {
            $hasUniqueDomain = true;
        }

        if(
            isset($cacheIndexes['unique'][$keyName])
            && !$cacheIndexes['unique'][$keyName]
            && array_slice($indexColumns, 0, 2) === array('provider_present', 'domain')
        ) {
            $hasPresentDomainIndex = true;
        }
    }

    if(!$hasPrimaryCacheId || !$hasUniqueDomain || !$hasPresentDomainIndex) {
        dbsdnsSchemaFail('Bestehende DBS-DNS-Indizes sind nicht kompatibel.', $exitSchemaIncompatible);
    }
}

if($settingsTable !== null) {
    if(strtolower((string)$settingsTable['table_collation']) !== 'utf8mb4_unicode_ci') {
        $collationCompatible = false;
    }

    $settingsColumns = dbsdnsSchemaReadColumns($app->db, 'dbsdns_settings', $exitInspectionFailed);
    $settingsTypes = array(
        'settings_id' => '/\Atinyint(?:\([0-9]+\))? unsigned\z/i',
        'wsdl_url' => '/\Avarchar\(2048\)\z/i',
        'username' => '/\Avarchar\(255\)\z/i',
        'password_ciphertext' => '/\Atext\z/i',
        'connection_status' => '/\Aenum\(\x27untested\x27,\x27success\x27,\x27failure\x27\)\z/i',
        'connection_source' => '/\Avarchar\(16\)\z/i',
        'last_tested_at' => '/\Adatetime(?:\(0\))?\z/i',
        'updated_at' => '/\Adatetime(?:\(0\))?\z/i'
    );
    $settingsNullability = array(
        'settings_id' => 'NO',
        'wsdl_url' => 'NO',
        'username' => 'NO',
        'password_ciphertext' => 'NO',
        'connection_status' => 'NO',
        'connection_source' => 'NO',
        'last_tested_at' => 'YES',
        'updated_at' => 'NO'
    );
    $settingsDefaults = array(
        'settings_id' => null,
        'wsdl_url' => '',
        'username' => '',
        'password_ciphertext' => null,
        'connection_status' => 'untested',
        'connection_source' => '',
        'last_tested_at' => null,
        'updated_at' => null
    );
    $settingsCollations = array(
        'wsdl_url' => 'utf8mb4_unicode_ci',
        'username' => 'utf8mb4_unicode_ci',
        'password_ciphertext' => 'ascii_bin',
        'connection_status' => 'utf8mb4_unicode_ci',
        'connection_source' => 'utf8mb4_unicode_ci'
    );
    dbsdnsSchemaValidateColumns(
        $settingsColumns,
        $settingsTypes,
        $settingsNullability,
        $settingsDefaults,
        $settingsCollations,
        $collationCompatible,
        $exitSchemaIncompatible
    );

    $settingsIndexes = dbsdnsSchemaReadIndexes($app->db, 'dbsdns_settings', $exitInspectionFailed);

    if(
        !isset($settingsIndexes['columns']['PRIMARY'])
        || $settingsIndexes['columns']['PRIMARY'] !== array('settings_id')
        || !isset($settingsIndexes['unique']['PRIMARY'])
        || !$settingsIndexes['unique']['PRIMARY']
    ) {
        dbsdnsSchemaFail('Bestehende DBS-DNS-Indizes sind nicht kompatibel.', $exitSchemaIncompatible);
    }
}

if($cacheTable === null || $settingsTable === null) {
    fwrite(
        STDERR,
        "Mindestens eine DBS-DNS-Tabelle (`dbsdns_zone_cache`, `dbsdns_settings`) fehlt.\n"
        . "Wenden Sie die Schema-Datei einmalig mit einem privilegierten MariaDB-Benutzer an, zum Beispiel:\n"
        . "mariadb " . $databaseName . " < " . $schemaFile . "\n"
        . "Führen Sie danach scripts/install.sh erneut aus.\n"
    );
    exit($exitSchemaMissing);
}

if(!$collationCompatible) {
    fwrite(STDERR, "Bestehendes DBS-DNS-Schema verwendet nicht utf8mb4_unicode_ci.\n");
    exit($exitSchemaCollation);
}

if($inspectSecretState) {
    $settingsRecord = $app->db->queryOneRecord(
        "SELECT CASE WHEN password_ciphertext <> '' THEN 1 ELSE 0 END AS has_secret\n"
        . "FROM `dbsdns_settings` WHERE settings_id = 1"
    );

    if(dbsdnsSchemaHasDatabaseError($app->db)) {
        dbsdnsSchemaFail('DBS-DNS-Secret-Status konnte nicht geprüft werden.', $exitInspectionFailed);
    }

    $hasSecret = is_array($settingsRecord)
        && isset($settingsRecord['has_secret'])
        && (int)$settingsRecord['has_secret'] === 1;
    fwrite(STDOUT, $hasSecret ? "configured\n" : "empty\n");
    exit(0);
}

fwrite(STDOUT, "DBS-DNS-Cache- und Settings-Schema sind bereit.\n");
