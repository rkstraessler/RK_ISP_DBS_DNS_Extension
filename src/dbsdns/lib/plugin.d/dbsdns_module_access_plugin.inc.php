<?php

require_once __DIR__ . '/../classes/DbsModuleAccess.inc.php';

class dbsdns_module_access_plugin
{
    public $plugin_name = 'dbsdns_module_access_plugin';
    public $class_name = 'dbsdns_module_access_plugin';

    public function onLoad()
    {
        global $app;

        foreach(array(
            'login',
            'client:client:on_after_insert',
            'client:client:on_after_update',
            'client:reseller:on_after_insert',
            'client:reseller:on_after_update',
            'client:domain:on_after_insert',
            'client:domain:on_after_update'
        ) as $eventName) {
            $app->plugin->registerEvent(
                $eventName,
                $this->plugin_name,
                'synchronizeModuleAccess',
                'dbsdns'
            );
        }
    }

    public function synchronizeModuleAccess($eventName, $eventData)
    {
        global $app;

        try {
            $moduleAccess = new DbsModuleAccess($app->db);
            $moduleAccess->synchronizeEligibleUsers();

            if($eventName === 'login') {
                $this->refreshCurrentSession();
            }
        } catch (Throwable $exception) {
            $app->log(
                'DBS DNS module permission synchronization failed (' . get_class($exception) . ').',
                LOGLEVEL_ERROR
            );
        }
    }

    private function refreshCurrentSession()
    {
        global $app;

        if(
            !isset($_SESSION['s']['user']['userid'])
            || !method_exists($app->db, 'queryOneRecord')
        ) {
            return;
        }

        $user = $app->db->queryOneRecord(
            'SELECT modules, startmodule FROM sys_user WHERE userid = ?',
            (int)$_SESSION['s']['user']['userid']
        );

        if(!is_array($user) || !isset($user['modules'], $user['startmodule'])) {
            return;
        }

        $_SESSION['s']['user']['modules'] = $user['modules'];
        $_SESSION['s']['user']['startmodule'] = $user['startmodule'];
        $sessionModules = array_map('trim', explode(',', $user['modules']));

        if(
            isset($_SESSION['s']['module']['name'])
            && $_SESSION['s']['module']['name'] === DbsModuleAccess::NATIVE_DNS_MODULE
            && in_array(DbsModuleAccess::CUSTOMER_MODULE, $sessionModules, true)
            && !in_array(DbsModuleAccess::NATIVE_DNS_MODULE, $sessionModules, true)
        ) {
            include ISPC_WEB_PATH . '/dbsdns/lib/module.conf.php';
            $_SESSION['s']['module'] = $module;
        }
    }
}
