<?php

class DbsRecordCapabilities
{
    private static $capabilities = array(
        'A' => array(
            'create' => true, 'edit' => true, 'delete' => true,
            'form_fields' => array('name', 'data', 'ttl', 'active'),
            'data_label' => 'ipv4_txt', 'name_hint' => 'apex_name_hint_txt', 'data_hint' => ''
        ),
        'AAAA' => array(
            'create' => true, 'edit' => true, 'delete' => true,
            'form_fields' => array('name', 'data', 'ttl', 'active'),
            'data_label' => 'ipv6_txt', 'name_hint' => 'apex_name_hint_txt', 'data_hint' => ''
        ),
        'CNAME' => array(
            'create' => true, 'edit' => true, 'delete' => true,
            'form_fields' => array('name', 'data', 'ttl', 'active'),
            'data_label' => 'target_hostname_txt', 'name_hint' => 'cname_name_hint_txt',
            'data_hint' => 'cname_target_hint_txt'
        ),
        'MX' => array(
            'create' => true, 'edit' => true, 'delete' => true,
            'form_fields' => array('name', 'data', 'aux', 'ttl', 'active'),
            'data_label' => 'mailserver_txt', 'name_hint' => 'apex_name_hint_txt',
            'data_hint' => 'mx_target_hint_txt'
        ),
        'NS' => array(
            'create' => true, 'edit' => true, 'delete' => true,
            'form_fields' => array('name', 'data', 'ttl', 'active'),
            'data_label' => 'nameserver_txt', 'name_hint' => 'apex_name_hint_txt',
            'data_hint' => 'ns_target_hint_txt'
        ),
        'SRV' => array(
            'create' => true, 'edit' => true, 'delete' => true,
            'form_fields' => array('name', 'target', 'weight', 'port', 'aux', 'ttl', 'active'),
            'data_label' => 'target_hostname_txt', 'name_hint' => 'srv_name_hint_txt',
            'data_hint' => 'srv_target_hint_txt'
        ),
        'TXT' => array(
            'create' => true, 'edit' => true, 'delete' => true,
            'form_fields' => array('name', 'data', 'ttl', 'active'),
            'data_label' => 'txt_value_txt', 'name_hint' => 'apex_name_hint_txt', 'data_hint' => ''
        )
    );

    public static function supportedWritableRecordTypes()
    {
        return array_keys(self::$capabilities);
    }

    public static function isWritable($type)
    {
        return self::canCreate($type) && self::canDelete($type) && self::canEdit($type);
    }

    public static function canCreate($type)
    {
        return self::hasCapability($type, 'create');
    }

    public static function canEdit($type)
    {
        $type = is_string($type) ? strtoupper($type) : '';

        return self::hasCapability($type, 'edit')
            && isset(self::$capabilities[$type]['form_fields'])
            && is_array(self::$capabilities[$type]['form_fields'])
            && count(self::$capabilities[$type]['form_fields']) > 0;
    }

    public static function canDelete($type)
    {
        return self::hasCapability($type, 'delete');
    }

    public static function formProfile($type)
    {
        $type = is_string($type) ? strtoupper($type) : '';

        if(!isset(self::$capabilities[$type])) {
            return null;
        }

        return array(
            'fields' => self::$capabilities[$type]['form_fields'],
            'data_label' => self::$capabilities[$type]['data_label'],
            'name_hint' => self::$capabilities[$type]['name_hint'],
            'data_hint' => self::$capabilities[$type]['data_hint']
        );
    }

    private static function hasCapability($type, $capability)
    {
        $type = is_string($type) ? strtoupper($type) : '';

        return isset(self::$capabilities[$type][$capability])
            && self::$capabilities[$type][$capability] === true;
    }
}
