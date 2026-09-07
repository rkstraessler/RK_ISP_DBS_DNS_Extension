<?php

class DbsAdminMenu
{
    public static function navigationGroup()
    {
        return array(
            'title' => 'DBS DNS management',
            'open' => 1,
            'items' => array(
                array('title' => 'Overview', 'target' => 'content', 'link' => 'dbsdns/assignment_list.php?view=overview', 'html_id' => 'dbsdns_admin_overview'),
                array('title' => 'Assignments', 'target' => 'content', 'link' => 'dbsdns/assignment_list.php?status=all', 'html_id' => 'dbsdns_admin_assignments'),
                array('title' => 'Assigned domains', 'target' => 'content', 'link' => 'dbsdns/assignment_list.php?status=saved', 'html_id' => 'dbsdns_admin_assigned_domains'),
                array('title' => 'Conflicts', 'target' => 'content', 'link' => 'dbsdns/assignment_list.php?status=conflict', 'html_id' => 'dbsdns_admin_conflicts'),
                array('title' => 'Unassigned domains', 'target' => 'content', 'link' => 'dbsdns/assignment_list.php?status=unassigned', 'html_id' => 'dbsdns_admin_unassigned'),
                array('title' => 'Refresh domain inventory', 'target' => 'content', 'link' => 'dbsdns/domain_sync.php', 'html_id' => 'dbsdns_admin_domain_sync'),
                array('title' => 'Settings', 'target' => 'content', 'link' => 'dbsdns/settings.php', 'html_id' => 'dbsdns_admin_settings')
            )
        );
    }
}
