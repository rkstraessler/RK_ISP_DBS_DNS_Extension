<?php

if(PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Access denied.');
}

$options = getopt('', array('interface-root:', 'dry-run'));
$interfaceRoot = isset($options['interface-root']) && is_string($options['interface-root'])
    ? rtrim($options['interface-root'], '/\\')
    : '/usr/local/ispconfig/interface';
$dryRun = array_key_exists('dry-run', $options);

if(
    $interfaceRoot === ''
    || !is_file($interfaceRoot . '/lib/config.inc.php')
    || !is_file($interfaceRoot . '/lib/app.inc.php')
) {
    fwrite(STDERR, "Der ISPConfig-Interface-Pfad ist ungültig.\n");
    exit(2);
}

require_once $interfaceRoot . '/lib/config.inc.php';
require_once $interfaceRoot . '/lib/app.inc.php';
require_once __DIR__ . '/lib/classes/DbsModuleAccess.inc.php';
require_once __DIR__ . '/lib/classes/DbsModulePermissionTransaction.inc.php';

$transaction = new DbsModulePermissionTransaction($app->db);
try {
    if(!$dryRun) {
        $transaction->begin();
    }
    $moduleAccess = new DbsModuleAccess($app->db);
    $result = $moduleAccess->removeExtensionFromAllUsers($dryRun);
    if(!$dryRun) {
        $transaction->commit();
    }
} catch (Throwable $exception) {
    $transaction->rollback();
    fwrite(STDERR, "DBS-DNS-Modulberechtigungen konnten nicht bereinigt werden; InnoDB und Datenbankzugriff prüfen.\n");
    exit(1);
}

printf(
    "DBS-DNS-Modulberechtigungen: %d %s, %d unverändert.\n",
    $result['updated'],
    $dryRun ? 'würden bereinigt' : 'bereinigt',
    $result['unchanged']
);
