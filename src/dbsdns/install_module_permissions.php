<?php

if(PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Access denied.');
}

$options = getopt('', array('interface-root:', 'preflight'));
$interfaceRoot = isset($options['interface-root']) && is_string($options['interface-root'])
    ? rtrim($options['interface-root'], '/\\')
    : '/usr/local/ispconfig/interface';

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
    if(isset($options['preflight'])) {
        $transaction->verify();
        exit(0);
    }
    $transaction->begin();
    $moduleAccess = new DbsModuleAccess($app->db);
    $result = $moduleAccess->synchronizeEligibleUsers();
    $transaction->commit();
} catch (Throwable $exception) {
    $transaction->rollback();
    fwrite(STDERR, "DBS-DNS-Modulberechtigungen konnten nicht synchronisiert werden; InnoDB und Datenbankzugriff prüfen.\n");
    exit(1);
}

printf(
    "DBS-DNS-Modulberechtigungen: %d aktualisiert, %d unverändert.\n",
    $result['updated'],
    $result['unchanged']
);
