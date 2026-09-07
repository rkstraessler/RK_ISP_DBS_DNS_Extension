<?php

require_once __DIR__ . '/DbsCredentialProvider.inc.php';

class DbsModuleAccessException extends RuntimeException
{
}

class DbsModuleAccess
{
    const CUSTOMER_MODULE = 'dbsdns';
    const NATIVE_DNS_MODULE = 'dns';

    private $db;
    private $configurationResolver;

    public function __construct($db, $configurationResolver = null)
    {
        if(
            !is_object($db)
            || !method_exists($db, 'queryAllRecords')
            || !method_exists($db, 'query')
        ) {
            throw new DbsModuleAccessException('ISPConfig-Datenbankzugriff nicht verfügbar.');
        }

        if($configurationResolver !== null && !is_callable($configurationResolver)) {
            throw new DbsModuleAccessException('DBS-Konfigurationsprüfung nicht verfügbar.');
        }

        $this->db = $db;
        $this->configurationResolver = $configurationResolver;
    }

    public function synchronizeEligibleUsers()
    {
        $assignedClientIds = $this->loadEligibleClientIds();
        $configurationAvailable = $this->hasAvailableProviderConfiguration();
        $users = $this->db->queryAllRecords(
            'SELECT userid, typ, client_id, modules, startmodule FROM sys_user'
        );

        if(!is_array($users) || $this->hasDatabaseError()) {
            throw new DbsModuleAccessException('ISPConfig-Modulberechtigungen konnten nicht geladen werden.');
        }

        $result = array(
            'updated' => 0,
            'unchanged' => 0,
            'eligible_clients' => $configurationAvailable ? count($assignedClientIds) : 0,
            'assigned_clients' => count($assignedClientIds),
            'configuration_available' => $configurationAvailable
        );

        foreach($users as $user) {
            $userId = isset($user['userid']) ? (int)$user['userid'] : 0;
            $isAdmin = isset($user['typ']) && $user['typ'] === 'admin';
            $clientId = isset($user['client_id']) ? (int)$user['client_id'] : 0;
            $isAssignedCustomer = !$isAdmin && isset($assignedClientIds[$clientId]);
            $isEligibleCustomer = $isAssignedCustomer && $configurationAvailable;

            if($userId <= 0 || (!$isAdmin && !$isAssignedCustomer)) {
                $result['unchanged']++;
                continue;
            }

            $oldModules = isset($user['modules']) && is_string($user['modules'])
                ? $user['modules']
                : '';
            $oldStartModule = isset($user['startmodule']) && is_string($user['startmodule'])
                ? $user['startmodule']
                : '';
            if($isAdmin || $isEligibleCustomer) {
                $newModules = self::rewriteModules($oldModules, $isAdmin);
                $newStartModule = !$isAdmin && $oldStartModule === self::NATIVE_DNS_MODULE
                    ? self::CUSTOMER_MODULE
                    : $oldStartModule;
            } else {
                $newModules = self::removeDnsModules($oldModules);
                $newStartModule = in_array(
                    $oldStartModule,
                    array(self::NATIVE_DNS_MODULE, self::CUSTOMER_MODULE),
                    true
                ) ? 'dashboard' : $oldStartModule;
            }

            if($newModules === $oldModules && $newStartModule === $oldStartModule) {
                $result['unchanged']++;
                continue;
            }

            $this->db->query(
                'UPDATE sys_user SET modules = ?, startmodule = ? WHERE userid = ?',
                $newModules,
                $newStartModule,
                $userId
            );

            if($this->hasDatabaseError()) {
                throw new DbsModuleAccessException('ISPConfig-Modulberechtigung konnte nicht gespeichert werden.');
            }

            $result['updated']++;
        }

        return $result;
    }

    public function removeExtensionFromAllUsers($dryRun = false)
    {
        $users = $this->db->queryAllRecords(
            'SELECT userid, modules, startmodule FROM sys_user'
        );

        if(!is_array($users) || $this->hasDatabaseError()) {
            throw new DbsModuleAccessException('ISPConfig-Modulberechtigungen konnten nicht geladen werden.');
        }

        $result = array(
            'updated' => 0,
            'unchanged' => 0
        );

        foreach($users as $user) {
            $userId = isset($user['userid']) ? (int)$user['userid'] : 0;

            if($userId <= 0) {
                $result['unchanged']++;
                continue;
            }

            $oldModules = isset($user['modules']) && is_string($user['modules'])
                ? $user['modules']
                : '';
            $oldStartModule = isset($user['startmodule']) && is_string($user['startmodule'])
                ? $user['startmodule']
                : '';
            $oldModuleNames = is_string($oldModules)
                ? array_map('trim', explode(',', $oldModules))
                : array();

            if(
                !in_array(self::CUSTOMER_MODULE, $oldModuleNames, true)
                && $oldStartModule !== self::CUSTOMER_MODULE
            ) {
                $result['unchanged']++;
                continue;
            }

            $newModules = self::removeCustomerModule($oldModules);
            $newStartModule = $oldStartModule === self::CUSTOMER_MODULE
                ? 'dashboard'
                : $oldStartModule;

            if($newModules === $oldModules && $newStartModule === $oldStartModule) {
                $result['unchanged']++;
                continue;
            }

            if(!$dryRun) {
                $this->db->query(
                    'UPDATE sys_user SET modules = ?, startmodule = ? WHERE userid = ?',
                    $newModules,
                    $newStartModule,
                    $userId
                );

                if($this->hasDatabaseError()) {
                    throw new DbsModuleAccessException('ISPConfig-Modulberechtigung konnte nicht gespeichert werden.');
                }
            }

            $result['updated']++;
        }

        return $result;
    }

    public static function rewriteModules($modules, $isAdmin)
    {
        $moduleNames = is_string($modules) ? explode(',', $modules) : array();
        $normalized = array();
        $customerModuleInserted = false;

        foreach($moduleNames as $moduleName) {
            $moduleName = trim($moduleName);

            if($moduleName === '') {
                continue;
            }

            if(!$isAdmin && $moduleName === self::NATIVE_DNS_MODULE) {
                $moduleName = self::CUSTOMER_MODULE;
            }

            if($moduleName === self::CUSTOMER_MODULE) {
                $customerModuleInserted = true;
            }

            if(!isset($normalized[$moduleName])) {
                $normalized[$moduleName] = $moduleName;
            }
        }

        if(!$customerModuleInserted) {
            $normalized[self::CUSTOMER_MODULE] = self::CUSTOMER_MODULE;
        }

        return implode(',', array_values($normalized));
    }

    public static function removeDnsModules($modules)
    {
        $moduleNames = is_string($modules) ? explode(',', $modules) : array();
        $normalized = array();

        foreach($moduleNames as $moduleName) {
            $moduleName = trim($moduleName);

            if(
                $moduleName === ''
                || $moduleName === self::NATIVE_DNS_MODULE
                || $moduleName === self::CUSTOMER_MODULE
            ) {
                continue;
            }

            if(!isset($normalized[$moduleName])) {
                $normalized[$moduleName] = $moduleName;
            }
        }

        return implode(',', array_values($normalized));
    }

    public static function removeCustomerModule($modules)
    {
        $moduleNames = is_string($modules) ? explode(',', $modules) : array();
        $normalized = array();

        foreach($moduleNames as $moduleName) {
            $moduleName = trim($moduleName);

            if($moduleName === '' || $moduleName === self::CUSTOMER_MODULE) {
                continue;
            }

            if(!isset($normalized[$moduleName])) {
                $normalized[$moduleName] = $moduleName;
            }
        }

        return implode(',', array_values($normalized));
    }

    public static function isProviderConfigurationAvailable($db)
    {
        try {
            return (new DbsCredentialProvider($db))->isCustomerConfigurationAvailable();
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function loadEligibleClientIds()
    {
        $records = $this->db->queryAllRecords(
            "SELECT DISTINCT client.client_id, client.parent_client_id\n"
            . "FROM dbsdns_zone_cache AS cache\n"
            . "INNER JOIN `domain` AS native_domain ON native_domain.domain = cache.domain\n"
            . "INNER JOIN sys_group ON sys_group.groupid = native_domain.sys_groupid\n"
            . "INNER JOIN client ON client.client_id = sys_group.client_id\n"
            . "WHERE cache.provider_present = 'Y'\n"
            . "  AND sys_group.client_id = client.client_id"
        );

        if(!is_array($records) || $this->hasDatabaseError()) {
            throw new DbsModuleAccessException('DBS-Kunden konnten nicht bestimmt werden.');
        }

        $clientIds = array();

        foreach($records as $record) {
            $clientId = isset($record['client_id']) ? (int)$record['client_id'] : 0;
            $parentClientId = isset($record['parent_client_id'])
                ? (int)$record['parent_client_id']
                : 0;

            if($clientId > 0) {
                $clientIds[$clientId] = true;
            }

            if($parentClientId > 0) {
                $clientIds[$parentClientId] = true;
            }
        }

        return $clientIds;
    }

    private function hasAvailableProviderConfiguration()
    {
        if($this->configurationResolver !== null) {
            try {
                return call_user_func($this->configurationResolver) === true;
            } catch (Throwable $exception) {
                return false;
            }
        }

        return self::isProviderConfigurationAvailable($this->db);
    }

    private function hasDatabaseError()
    {
        return property_exists($this->db, 'errorMessage')
            && $this->db->errorMessage !== '';
    }
}
