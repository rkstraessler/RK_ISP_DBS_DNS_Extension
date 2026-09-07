<?php

$module['name'] = 'dbsdns';
$module['title'] = 'DNS';
$module['template'] = 'module.tpl.htm';
$module['startpage'] = 'dbsdns/zone_list.php';
$module['tab_width'] = '';
$module['order'] = '50';
$module['icon'] = 'icon icon-dns';

$module['nav'][] = array(
    'title' => 'DNS',
    'open' => 1,
    'items' => array(
        array(
            'title' => 'Zones',
            'target' => 'content',
            'link' => 'dbsdns/zone_list.php',
            'html_id' => 'dbsdns_zone_list'
        )
    )
);

if(isset($app) && isset($app->auth) && $app->auth->is_admin()) {
    require_once __DIR__ . '/classes/DbsAdminMenu.inc.php';
    $module['nav'][] = DbsAdminMenu::navigationGroup();
}
