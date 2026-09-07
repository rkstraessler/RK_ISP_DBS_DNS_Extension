<?php

class DbsZoneListActions extends listform_actions
{
    private $accessibleCacheIds;

    public function __construct($accessibleCacheIds)
    {
        $cacheIds = array();

        if(is_array($accessibleCacheIds)) {
            foreach($accessibleCacheIds as $cacheId) {
                $cacheId = (int)$cacheId;

                if($cacheId > 0) {
                    $cacheIds[$cacheId] = $cacheId;
                }
            }
        }

        $this->accessibleCacheIds = array_values($cacheIds);
        sort($this->accessibleCacheIds, SORT_NUMERIC);
    }

    public function prepareDataRow($record)
    {
        $record = parent::prepareDataRow($record);
        $record['source'] = 'dbs';
        $record['server_id'] = 'DBS';

        return $record;
    }

    public function getQueryString($noLimit = false)
    {
        global $app;

        $sqlWhere = $app->listform->getSearchSQL('');
        $app->tpl->setVar($app->listform->searchValues);
        $sourceSql = $this->buildSourceSql();
        $tableAlias = 'dbsdns_zone_list';
        $countRecord = $app->db->queryOneRecord(
            'SELECT COUNT(*) AS anzahl FROM (' . $sourceSql . ') AS ' . $tableAlias
            . ' WHERE ' . $sqlWhere
        );
        $recordCount = is_array($countRecord) && isset($countRecord['anzahl'])
            ? (int)$countRecord['anzahl']
            : 0;
        $limitSql = $this->buildPaging($recordCount);
        $sql = 'SELECT ' . $tableAlias . '.* FROM (' . $sourceSql . ') AS ' . $tableAlias
            . ' WHERE ' . $sqlWhere . ' ' . $this->SQLOrderBy;

        if(!$noLimit) {
            $sql .= ' ' . $limitSql;
        }

        return $sql;
    }

    private function buildSourceSql()
    {
        $cacheIdFilter = count($this->accessibleCacheIds) > 0
            ? 'cache.cache_id IN (' . implode(',', $this->accessibleCacheIds) . ')'
            : '1 = 0';

        return "SELECT cache.cache_id AS id, cache.cache_id,\n"
            . "       native_domain.sys_userid, native_domain.sys_groupid,\n"
            . "       native_domain.sys_perm_user, native_domain.sys_perm_group, native_domain.sys_perm_other,\n"
            . "       'DBS' AS server_id, cache.domain AS origin,\n"
            . "       cache.provider_status, cache.provider_present AS active,\n"
            . "       client.username AS client_name\n"
            . "FROM dbsdns_zone_cache AS cache\n"
            . "INNER JOIN `domain` AS native_domain ON native_domain.domain = cache.domain\n"
            . "INNER JOIN sys_group ON sys_group.groupid = native_domain.sys_groupid\n"
            . "INNER JOIN client ON client.client_id = sys_group.client_id\n"
            . "WHERE cache.provider_present = 'Y'\n"
            . "  AND " . $cacheIdFilter;
    }

    private function buildPaging($recordCount)
    {
        global $app;

        $oldSearchLimit = isset($_SESSION['search']['limit'])
            ? (int)$_SESSION['search']['limit']
            : 0;

        if(isset($_POST['search_limit']) && $app->functions->intval($_POST['search_limit']) > 0) {
            $_SESSION['search']['limit'] = $app->functions->intval($_POST['search_limit']);
        }

        if(!isset($_SESSION['search']['limit']) || (int)$_SESSION['search']['limit'] < 1) {
            $_SESSION['search']['limit'] = 15;
        }

        $listName = $app->listform->listDef['name'];
        $recordsPerPage = (int)$_SESSION['search']['limit'];

        if(!isset($_SESSION['search'][$listName]['page'])) {
            $_SESSION['search'][$listName]['page'] = 0;
        }

        if(isset($_REQUEST['page'])) {
            $_SESSION['search'][$listName]['page'] = $app->functions->intval($_REQUEST['page']);
        }

        if($oldSearchLimit !== $recordsPerPage) {
            $_SESSION['search'][$listName]['page'] = 0;
        }

        $pages = $recordCount > 0
            ? (int)floor(($recordCount - 1) / $recordsPerPage)
            : 0;
        $page = max(0, min($pages, (int)$_SESSION['search'][$listName]['page']));
        $_SESSION['search'][$listName]['page'] = $page;
        $offset = $page * $recordsPerPage;
        $vars = array(
            'list_file' => 'dbsdns/' . $app->listform->listDef['file'],
            'page' => $page,
            'last_page' => $page - 1,
            'next_page' => $page + 1,
            'pages' => $pages,
            'max_pages' => $pages + 1,
            'records_gesamt' => $recordCount,
            'page_params' => '',
            'offset' => $offset,
            'records_per_page' => $recordsPerPage
        );

        if($page > 0) {
            $vars['show_page_back'] = 1;
        }

        if($page < $pages) {
            $vars['show_page_next'] = 1;
        }

        $app->listform->pagingHTML = $app->listform->getPagingHTML($vars);
        $app->tpl->setVar('paging', $app->listform->pagingHTML);

        return 'LIMIT ' . $offset . ', ' . $recordsPerPage;
    }
}
