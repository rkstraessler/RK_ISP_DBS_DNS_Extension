<?php

function zoneCacheSchemaAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

$testFailure = null;

try {
    $root = dirname(__DIR__);
    $schema = file_get_contents($root . '/src/dbsdns/sql/dbsdns_zone_cache.sql');
    $migration = file_get_contents(
        $root . '/src/dbsdns/sql/migrations/001_dbsdns_zone_cache_utf8mb4_unicode_ci.sql'
    );
    $settingsMigration = file_get_contents(
        $root . '/src/dbsdns/sql/migrations/002_dbsdns_settings.sql'
    );
    $installer = file_get_contents($root . '/src/dbsdns/install_schema.php');
    $rootInstaller = file_get_contents($root . '/scripts/install.sh');

    foreach(array(
        '`cache_id`',
        '`domain`',
        '`provider_status`',
        '`provider_present`',
        '`first_seen_at`',
        '`last_seen_at`',
        '`last_synced_at`'
    ) as $requiredColumn) {
        zoneCacheSchemaAssertTrue(
            strpos($schema, $requiredColumn) !== false,
            'Im DBS-Cache fehlt die Spalte ' . $requiredColumn . '.'
        );
    }

    zoneCacheSchemaAssertTrue(
        strpos($schema, 'UNIQUE KEY `domain` (`domain`)') !== false
        && strpos($schema, 'KEY `provider_present_domain` (`provider_present`,`domain`)') !== false,
        'Domain-Eindeutigkeit oder Present-Index fehlt im DBS-Cache.'
    );
    zoneCacheSchemaAssertTrue(
        preg_match(
            '/`domain`\s+varchar\(255\)\s+CHARACTER SET utf8mb4\s+COLLATE utf8mb4_unicode_ci/i',
            $schema
        ) === 1
        && preg_match(
            '/ENGINE=InnoDB\s+DEFAULT CHARACTER SET utf8mb4\s+COLLATE utf8mb4_unicode_ci/i',
            $schema
        ) === 1,
        'Das DBS-Cache-Schema hängt weiterhin von der MariaDB-Default-Collation ab.'
    );
    zoneCacheSchemaAssertTrue(
        stripos($migration, 'ALTER TABLE `dbsdns_zone_cache`') !== false
        && stripos($migration, 'CONVERT TO CHARACTER SET utf8mb4') !== false
        && stripos($migration, 'COLLATE utf8mb4_unicode_ci') !== false
        && stripos($migration, 'DROP INDEX') === false
        && stripos($migration, 'DROP KEY') === false,
        'Die versionierte Collation-Migration ist unvollständig oder entfernt Indizes.'
    );
    foreach(array(
        '`settings_id`',
        '`wsdl_url`',
        '`username`',
        '`password_ciphertext`',
        '`connection_status`',
        '`connection_source`',
        '`last_tested_at`',
        '`updated_at`'
    ) as $requiredSettingsColumn) {
        zoneCacheSchemaAssertTrue(
            strpos($schema, $requiredSettingsColumn) !== false,
            'In den technischen DBS-Einstellungen fehlt die Spalte ' . $requiredSettingsColumn . '.'
        );
    }
    zoneCacheSchemaAssertTrue(
        strpos($schema, 'CREATE TABLE IF NOT EXISTS `dbsdns_settings`') !== false
        && strpos($schema, '`password_ciphertext` text CHARACTER SET ascii COLLATE ascii_bin NOT NULL') !== false
        && strpos($settingsMigration, 'CREATE TABLE IF NOT EXISTS `dbsdns_settings`') !== false
        && strpos($settingsMigration, 'MODIFY `password_ciphertext` text CHARACTER SET ascii COLLATE ascii_bin NOT NULL') !== false
        && stripos($settingsMigration, 'DROP ') === false,
        'Settings-Schema oder additive Migration ist nicht idempotent beziehungsweise Ciphertext-sicher.'
    );
    zoneCacheSchemaAssertTrue(
        stripos($schema, 'client_id') === false
        && stripos($schema, 'reseller') === false
        && stripos($schema, 'dns_rr') === false
        && stripos($schema, '`data`') === false,
        'Der DBS-Cache dupliziert eine Zuordnung oder DNS-Records.'
    );
    zoneCacheSchemaAssertTrue(
        strpos($installer, 'information_schema.TABLES') !== false
        && strpos($installer, "dbsdnsSchemaReadColumns(\$app->db, 'dbsdns_zone_cache'") !== false
        && strpos($installer, "dbsdnsSchemaReadIndexes(\$app->db, 'dbsdns_zone_cache'") !== false
        && strpos($installer, "dbsdnsSchemaReadColumns(\$app->db, 'dbsdns_settings'") !== false
        && strpos($installer, "dbsdnsSchemaReadIndexes(\$app->db, 'dbsdns_settings'") !== false
        && strpos($installer, 'TABLE_COLLATION AS table_collation') !== false
        && strpos($installer, 'utf8mb4_unicode_ci') !== false
        && strpos($installer, 'auto_increment') !== false,
        'Der Schema-Installer validiert einen vorhandenen Cache nicht vollständig.'
    );
    zoneCacheSchemaAssertTrue(
        strpos($installer, 'file_get_contents($schemaFile)') === false
        && strpos($installer, '->query($schema)') === false
        && stripos($installer, 'CREATE TABLE') === false
        && stripos($installer, 'ALTER TABLE') === false
        && stripos($installer, 'GRANT ') === false,
        'Der Schema-Installer versucht weiterhin, das Schema mit dem ISPConfig-DB-User zu verändern.'
    );
    zoneCacheSchemaAssertTrue(
        stripos($schema . $migration . $settingsMigration . $rootInstaller, 'GRANT ') === false
        && stripos($rootInstaller, '--password') === false
        && stripos($rootInstaller, '-p"') === false,
        'Installer oder SQL speichern Zugangsdaten beziehungsweise verändern MariaDB-Rechte.'
    );
    zoneCacheSchemaAssertTrue(
        strpos($rootInstaller, 'random_bytes(32)') !== false
        && strpos($rootInstaller, 'chmod 0640') !== false
        && strpos($rootInstaller, 'chown "root:${panel_group}"') !== false
        && strpos($rootInstaller, 'runuser --user "${panel_user}" -- test -r') !== false
        && strpos($rootInstaller, 'security/dbsdns') !== false
        && strpos($rootInstaller, '--settings-secret-state') !== false
        && strpos($rootInstaller, 'Encrypted DBS credentials exist but their external key is missing') !== false
        && strpos($rootInstaller, 'class_exists("SoapClient")') !== false,
        'Der Installer erzeugt oder erhält den externen Credential-Schlüssel nicht sicher.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'DBS-Cache-Schema erfolgreich geprüft.' . PHP_EOL;
