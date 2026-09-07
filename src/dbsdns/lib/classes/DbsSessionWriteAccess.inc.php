<?php

class DbsSessionWriteAccess
{
    public static function mayWrite($app, $sessionUser)
    {
        if(
            !is_object($app)
            || !isset($app->auth, $app->functions, $app->db)
            || !is_array($sessionUser)
        ) {
            return false;
        }

        if($app->auth->is_admin()) {
            return true;
        }

        $groupId = isset($sessionUser['default_group'])
            ? $app->functions->intval($sessionUser['default_group'])
            : 0;

        if($groupId <= 0) {
            return false;
        }

        $client = $app->db->queryOneRecord(
            'SELECT client.locked FROM sys_group, client WHERE sys_group.client_id = client.client_id and sys_group.groupid = ?',
            $groupId
        );

        return is_array($client)
            && isset($client['locked'])
            && $client['locked'] === 'n';
    }
}
