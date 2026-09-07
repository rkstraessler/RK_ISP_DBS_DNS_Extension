<?php

if(PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dieses Werkzeug darf nur per CLI ausgeführt werden.\n");
    exit(2);
}

$options = getopt('', array('interface-root:'));
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

try {
    $moduleAccess = new DbsModuleAccess($app->db);
    $result = $moduleAccess->synchronizeEligibleUsers();
} catch (Throwable $exception) {
    fwrite(STDERR, "DBS-DNS-Modulberechtigungen konnten nicht synchronisiert werden.\n");
    exit(1);
}

printf(
    "DBS-DNS-Modulberechtigungen: %d aktualisiert, %d unverändert.\n",
    $result['updated'],
    $result['unchanged']
);

