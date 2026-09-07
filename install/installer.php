<?php

class dbsdns_installer extends extension_installer_base
{
    const EXTENSION_NAME = 'dbsdns';

    public function install()
    {
        return $this->runLifecycleScript('install.sh');
    }

    public function update()
    {
        return $this->runLifecycleScript('install.sh');
    }

    public function uninstall()
    {
        if(!is_dir($this->ispconfig_dir . '/interface/web/' . self::EXTENSION_NAME)) {
            return true;
        }

        return $this->runLifecycleScript('uninstall.sh');
    }

    public function enable()
    {
        global $app;

        if(!is_dir($this->ispconfig_dir . '/interface/web/' . self::EXTENSION_NAME)) {
            $this->runLifecycleScript('install.sh');
        }

        if(!$app->extension_installer->enable_files(self::EXTENSION_NAME)) {
            $this->addError('DBS DNS extension files could not be enabled.');
        }

        return true;
    }

    public function disable()
    {
        global $app;

        if(is_dir($this->ispconfig_dir . '/interface/web/' . self::EXTENSION_NAME)) {
            $this->runLifecycleScript('uninstall.sh');
        }

        if(!$app->extension_installer->disable_files(self::EXTENSION_NAME)) {
            $this->addError('DBS DNS extension files could not be disabled.');
        }

        return true;
    }

    private function runLifecycleScript($scriptName)
    {
        $script = $this->extension_basedir . '/' . self::EXTENSION_NAME . '/scripts/' . $scriptName;

        if(!is_file($script)) {
            $this->addError('DBS DNS lifecycle script is missing: ' . $scriptName);
        }

        $output = array();
        $exitCode = 0;
        exec('/bin/bash ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);

        if($exitCode !== 0) {
            $this->addError('DBS DNS lifecycle script failed: ' . $scriptName . ' (exit ' . $exitCode . ').');
        }

        return true;
    }

    private function addError($message)
    {
        global $app;

        $app->log($message, LOGLEVEL_WARN);
        $app->extension_installer->addError($message);

        // ISPConfig ignores lifecycle return values and would otherwise continue
        // enabling a failed update or deleting an incompletely removed package.
        throw new RuntimeException($message);
    }
}
