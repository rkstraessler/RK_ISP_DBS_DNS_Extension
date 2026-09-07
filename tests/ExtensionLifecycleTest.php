<?php

require_once __DIR__ . '/ReleaseMetadataTest.php';

define('LOGLEVEL_WARN', 1);

class LifecycleTestInstaller extends dbsdns_installer
{
    public function __construct($root)
    {
        $this->extension_basedir = $root . '/extensions';
        $this->ispconfig_dir = $root;
    }
}

class LifecycleTestManager
{
    public $events = array();
    public $errors = array();
    public $enableResult = true;

    public function enable_files($name)
    {
        $this->events[] = 'enable';
        return $this->enableResult;
    }

    public function disable_files($name)
    {
        $this->events[] = 'disable';
        return true;
    }

    public function addError($message)
    {
        $this->errors[] = $message;
    }
}

class LifecycleTestApp
{
    public $extension_installer;

    public function log($message, $level) {}
}

$root = sys_get_temp_dir() . '/dbsdns-lifecycle-' . bin2hex(random_bytes(8));
$scripts = $root . '/extensions/dbsdns/scripts';
$module = $root . '/interface/web/dbsdns';
mkdir($scripts, 0700, true);
mkdir($module, 0700, true);
$app = new LifecycleTestApp();
$app->extension_installer = new LifecycleTestManager();
$installer = new LifecycleTestInstaller($root);

try {
    file_put_contents($scripts . '/install.sh', "exit 0\n");
    file_put_contents($scripts . '/uninstall.sh', "exit 0\n");
    releaseMetadataAssertTrue($installer->install() && $installer->update(), 'Install/Update fehlgeschlagen.');
    releaseMetadataAssertTrue($installer->enable() && $installer->disable() && $installer->uninstall(), 'Lifecycle fehlgeschlagen.');

    foreach(array('install', 'update', 'disable', 'uninstall') as $method) {
        file_put_contents($scripts . '/install.sh', "exit 17\n");
        file_put_contents($scripts . '/uninstall.sh', "exit 17\n");
        $app->extension_installer->events = array();
        $continued = false;
        $caught = false;
        try {
            $installer->$method();
            // Model ISPConfig continuing despite an unchecked return value.
            $continued = true;
        } catch (RuntimeException $exception) {
            $caught = true;
        }
        releaseMetadataAssertTrue($caught && !$continued, 'Fehler stoppt ISPConfig nicht: ' . $method);
        releaseMetadataAssertTrue($app->extension_installer->events === array(), 'Aktivstatus nach Fehler verändert.');
    }

    $app->extension_installer->enableResult = false;
    $caught = false;
    try {
        $installer->enable();
    } catch (RuntimeException $exception) {
        $caught = true;
    }
    releaseMetadataAssertTrue($caught, 'Fehlgeschlagenes enable_files muss abbrechen.');

    unlink($scripts . '/install.sh');
    $caught = false;
    try {
        $installer->install();
    } catch (RuntimeException $exception) {
        $caught = true;
    }
    releaseMetadataAssertTrue($caught, 'Fehlendes Lifecycle-Skript muss abbrechen.');
    echo "ISPConfig-Lifecycle und sichere Fehlerabbrüche erfolgreich geprüft.\n";
} finally {
    foreach(array('install.sh', 'uninstall.sh') as $script) {
        if(is_file($scripts . '/' . $script)) unlink($scripts . '/' . $script);
    }
    rmdir($scripts);
    rmdir(dirname($scripts));
    rmdir(dirname($scripts, 2));
    rmdir($module);
    rmdir(dirname($module));
    rmdir(dirname($module, 2));
    rmdir($root);
}
