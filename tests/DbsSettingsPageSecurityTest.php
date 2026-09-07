<?php

function settingsPageSecurityAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

$testFailure = null;

try {
    $root = dirname(__DIR__);
    $controller = file_get_contents($root . '/src/dbsdns/settings.php');
    $template = file_get_contents($root . '/src/dbsdns/templates/settings.htm');
    $module = file_get_contents($root . '/src/dbsdns/lib/module.conf.php');
    $menu = file_get_contents($root . '/src/dbsdns/lib/classes/DbsAdminMenu.inc.php');
    $client = file_get_contents($root . '/src/dbsdns/lib/classes/DbsClient.inc.php');

    settingsPageSecurityAssertTrue(
        strpos($controller, "check_module_permissions('dbsdns')") !== false
        && strpos($controller, 'if(!$app->auth->is_admin())') !== false
        && strpos($controller, 'http_response_code(403)') !== false
        && strpos($module, '$app->auth->is_admin()') !== false
        && strpos($menu, "'link' => 'dbsdns/settings.php'") !== false,
        'Einstellungen sind für Kunde/Reseller sichtbar oder direkt erreichbar.'
    );
    settingsPageSecurityAssertTrue(
        strpos($controller, "REQUEST_METHOD']) && \$_SERVER['REQUEST_METHOD'] === 'POST'") !== false
        && strpos($controller, 'csrf_token_check()') !== false
        && strpos($controller, "\$action === 'save'") !== false
        && strpos($controller, "\$action === 'test'") !== false
        && strpos($controller, "\$_GET['action']") !== false,
        'Speichern/Testen ist nicht eindeutig an POST und CSRF gebunden.'
    );
    settingsPageSecurityAssertTrue(
        preg_match("/<input[^>]+type='password'[^>]+value=''[^>]*>/i", $template) === 1
        && strpos($template, "autocomplete='new-password'") !== false
        && strpos($controller, "setVar('password'") === false
        && strpos($controller, 'setVar($_POST') === false
        && preg_match('/data-[^=]*password/i', $template) === 0,
        'Passwort oder Credential-Daten können in HTML/JS/data-* zurückgegeben werden.'
    );
    settingsPageSecurityAssertTrue(
        strpos($controller, 'new DbsClient($testContext') !== false
        && strpos($controller, 'testConnection()') !== false
        && strpos($controller, 'listDomains(') === false
        && strpos($client, "'trace' => false") !== false,
        'Verbindungstest ist nicht minimal read-only oder aktiviert SOAP-Rohtrace.'
    );
    settingsPageSecurityAssertTrue(
        strpos($controller, 'getMessage()') === false
        && strpos($controller, "\$app->log('DBS DNS module permission synchronization failed.'") !== false
        && strpos($controller, '$app->log($') === false
        && preg_match('/\$_SESSION\s*\[[^\n]+=/i', $controller) === 0,
        'Settings-Controller schreibt Credentials oder Exceptiondetails in Log beziehungsweise Session.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Admin-only Settings, POST/CSRF und Passwort-Nichtausgabe erfolgreich geprüft.' . PHP_EOL;
