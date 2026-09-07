<?php

require_once __DIR__ . '/DbsRecordCapabilities.inc.php';
require_once __DIR__ . '/DbsRecordIdentity.inc.php';
require_once __DIR__ . '/DbsRecordNormalizer.inc.php';

class DbsRecordListRenderer
{
    private $recordIdentity;
    private $recordNormalizer;
    private $searchChanged = false;

    public function __construct($recordIdentity = null, $recordNormalizer = null)
    {
        $this->recordIdentity = $recordIdentity === null
            ? new DbsRecordIdentity()
            : $recordIdentity;
        $this->recordNormalizer = $recordNormalizer === null
            ? new DbsRecordNormalizer()
            : $recordNormalizer;
    }

    public function render($records, $cacheId, $origin)
    {
        global $app;

        $cacheId = abs((int)$cacheId);
        $app->uses('listform');
        $app->listform->loadListDef('list/record.list.php');
        $app->listform->listDef['page_params'] = '&id=' . $cacheId;

        $listTemplate = new tpl;
        $listTemplate->newTemplate('templates/dbsdns_record_list.htm');
        $listTemplate->setVar('parent_id', $cacheId);
        $listTemplate->setVar('theme', $_SESSION['s']['theme'], true);

        $searchStateBefore = $this->getSearchState($app->listform->listDef);
        $app->listform->getSearchSQL('');
        $this->resetPageWhenSearchChanged($searchStateBefore, $app->listform->listDef);
        $listTemplate->setVar($app->listform->searchValues);

        $normalizedRecords = $this->normalizeRecords($records, $cacheId, $origin);
        $filteredRecords = $this->filterRecords($normalizedRecords, $app->listform->listDef);
        $filteredRecords = $this->sortRecords($filteredRecords, $app->listform->listDef['name']);
        $paging = $this->buildPaging(count($filteredRecords), $cacheId);
        $visibleRecords = array_slice(
            $filteredRecords,
            $paging['offset'],
            $paging['records_per_page']
        );
        $displayRecords = array();
        $csrfToken = $app->auth->csrf_token_get($app->listform->listDef['name']);

        foreach($visibleRecords as $record) {
            $displayRecord = $app->functions->htmlentities($record);
            $displayRecord['active'] = $app->lng('yes_txt');
            $displayRecord['edit_link'] = !empty($record['can_edit'])
                ? 'dbsdns/record_edit.php?record_token=' . rawurlencode($record['id'])
                : '';
            $displayRecord['csrf_id'] = $app->functions->htmlentities($csrfToken['csrf_id']);
            $displayRecord['csrf_key'] = $app->functions->htmlentities($csrfToken['csrf_key']);
            $displayRecord['delete_confirmation'] = $app->functions->htmlentities(
                $app->lng('delete_confirmation')
            );
            $displayRecords[] = $displayRecord;
        }

        $recordButtons = array();

        foreach(DbsRecordCapabilities::supportedWritableRecordTypes() as $recordType) {
            $recordButtons[] = array(
                'type' => $recordType,
                'link' => 'dbsdns/record_edit.php?zone=' . $cacheId
                    . '&type=' . rawurlencode($recordType)
            );
        }

        $listTemplate->setVar($app->listform->wordbook);
        $listTemplate->setVar('paging', $app->listform->getPagingHTML($paging));
        $listTemplate->setVar(
            'search_limit',
            $this->buildSearchLimitSelect($app->listform->listDef['name'])
        );
        $listTemplate->setLoop('record_buttons', $recordButtons);
        $listTemplate->setLoop('records', $displayRecords);
        $this->setTemplateDefaults($listTemplate);

        return $listTemplate->grab();
    }

    public function normalizeRecords($records, $cacheId, $origin = '')
    {
        if(!is_array($records)) {
            return array();
        }

        $cacheId = abs((int)$cacheId);
        $normalized = array();
        $position = 0;

        foreach($records as $record) {
            if(!is_array($record)) {
                continue;
            }

            $type = isset($record['type']) && is_scalar($record['type'])
                ? strtoupper(trim((string)$record['type']))
                : '';

            if($type === '') {
                continue;
            }

            $normalizedRecord = array(
                'id' => '',
                'zone' => $cacheId,
                'active' => 'Y',
                'type' => $type,
                'name' => isset($record['name']) && is_scalar($record['name'])
                    ? (string)$record['name']
                    : '',
                'data' => isset($record['data']) && is_scalar($record['data'])
                    ? (string)$record['data']
                    : '',
                'aux' => isset($record['aux']) && is_scalar($record['aux'])
                    ? (string)$record['aux']
                    : '',
                'ttl' => isset($record['ttl']) && is_scalar($record['ttl'])
                    ? (string)$record['ttl']
                    : '',
                'can_edit' => 0,
                'can_delete' => 0,
                '_position' => $position
            );

            if(DbsRecordCapabilities::isWritable($type)) {
                try {
                    $canonicalRecord = $this->recordNormalizer->normalizeProviderRecord(
                        array(
                            'name' => $normalizedRecord['name'],
                            'type' => $type,
                            'data' => $normalizedRecord['data'],
                            'aux' => $normalizedRecord['aux'],
                            'ttl' => $normalizedRecord['ttl']
                        ),
                        $origin
                    );
                    $normalizedRecord['name'] = $canonicalRecord['name'];
                    $normalizedRecord['type'] = $canonicalRecord['type'];
                    $normalizedRecord['data'] = $canonicalRecord['data'];
                    $normalizedRecord['aux'] = $canonicalRecord['aux'];
                    $normalizedRecord['ttl'] = $canonicalRecord['ttl'];
                    $normalizedRecord['id'] = $this->recordIdentity->sign(
                        $cacheId,
                        $canonicalRecord
                    );
                    $normalizedRecord['can_edit'] = DbsRecordCapabilities::canEdit($type) ? 1 : 0;
                    $normalizedRecord['can_delete'] = DbsRecordCapabilities::canDelete($type) ? 1 : 0;
                } catch (DbsRecordNormalizationException $exception) {
                    $normalizedRecord['id'] = '';
                } catch (DbsRecordIdentityException $exception) {
                    $normalizedRecord['id'] = '';
                }
            }

            $normalized[] = $normalizedRecord;
            $position++;
        }

        return $normalized;
    }

    private function filterRecords($records, $listDefinition)
    {
        $listName = $listDefinition['name'];
        $searchPrefix = $listDefinition['search_prefix'];
        $fields = array();

        foreach($listDefinition['item'] as $field) {
            $fields[$field['field']] = $field;
        }

        return array_values(array_filter($records, function($record) use ($fields, $listName, $searchPrefix) {
            foreach($fields as $fieldName => $field) {
                $sessionKey = $searchPrefix . $fieldName;
                $searchValue = isset($_SESSION['search'][$listName][$sessionKey])
                    ? (string)$_SESSION['search'][$listName][$sessionKey]
                    : '';

                if($searchValue === '' || !array_key_exists($fieldName, $record)) {
                    continue;
                }

                $recordValue = (string)$record[$fieldName];
                $prefix = isset($field['prefix']) ? (string)$field['prefix'] : '';
                $suffix = isset($field['suffix']) ? (string)$field['suffix'] : '';

                if($prefix === '%' || $suffix === '%') {
                    if(stripos($recordValue, $searchValue) === false) {
                        return false;
                    }
                } elseif(strcasecmp($recordValue, $searchValue) !== 0) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function sortRecords($records, $listName)
    {
        $allowedFields = array('active', 'type', 'name', 'data', 'aux', 'ttl');

        if(!isset($_SESSION['search'][$listName]['order'])) {
            $_SESSION['search'][$listName]['order'] = '';
        }

        if(isset($_GET['orderby'])) {
            $requestedOrder = str_replace('tbl_col_', '', (string)$_GET['orderby']);

            if(in_array($requestedOrder, $allowedFields, true)) {
                if($_SESSION['search'][$listName]['order'] === $requestedOrder) {
                    $_SESSION['search'][$listName]['order'] = $requestedOrder . ' DESC';
                } else {
                    $_SESSION['search'][$listName]['order'] = $requestedOrder;
                }
            }
        }

        $order = $_SESSION['search'][$listName]['order'] !== ''
            ? $_SESSION['search'][$listName]['order']
            : 'type';
        $descending = substr($order, -5) === ' DESC';
        $field = $descending ? substr($order, 0, -5) : $order;

        if(!in_array($field, $allowedFields, true)) {
            $field = 'type';
            $descending = false;
        }

        usort($records, function($left, $right) use ($field, $descending) {
            $comparison = strnatcasecmp((string)$left[$field], (string)$right[$field]);

            if($comparison === 0 && $field !== 'name') {
                $comparison = strnatcasecmp((string)$left['name'], (string)$right['name']);
            }

            if($comparison === 0) {
                $comparison = $left['_position'] - $right['_position'];
            }

            return $descending ? -$comparison : $comparison;
        });

        return $records;
    }

    private function buildPaging($recordCount, $cacheId)
    {
        global $app;

        $listName = $app->listform->listDef['name'];
        $limits = $this->pageSizeOptions();
        $defaultLimit = isset($app->listform->listDef['records_per_page'])
            ? (int)$app->listform->listDef['records_per_page']
            : 15;

        if(!isset($limits[$defaultLimit])) {
            $defaultLimit = 15;
        }

        if(!isset($_SESSION['search']) || !is_array($_SESSION['search'])) {
            $_SESSION['search'] = array();
        }

        if(!isset($_SESSION['search'][$listName]) || !is_array($_SESSION['search'][$listName])) {
            $_SESSION['search'][$listName] = array();
        }

        $recordsPerPage = isset($_SESSION['search'][$listName]['limit'])
            ? (int)$_SESSION['search'][$listName]['limit']
            : 0;

        if(!isset($limits[$recordsPerPage])) {
            $globalLimit = isset($_SESSION['search']['limit'])
                ? (int)$_SESSION['search']['limit']
                : 0;
            $recordsPerPage = isset($limits[$globalLimit]) ? $globalLimit : $defaultLimit;
        }

        $oldSearchLimit = $recordsPerPage;

        if(isset($_REQUEST['search_limit']) && is_scalar($_REQUEST['search_limit'])) {
            $requestedLimit = $app->functions->intval($_REQUEST['search_limit']);

            if(isset($limits[$requestedLimit])) {
                $recordsPerPage = $requestedLimit;
            }
        }

        $_SESSION['search'][$listName]['limit'] = $recordsPerPage;

        if(!isset($_SESSION['search'][$listName]['page'])) {
            $_SESSION['search'][$listName]['page'] = 0;
        }

        if(isset($_REQUEST['page']) && is_scalar($_REQUEST['page']) && !$this->searchChanged) {
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
        $paging = array(
            'list_file' => 'dbsdns/zone_view.php',
            'page' => $page,
            'last_page' => $page - 1,
            'next_page' => $page + 1,
            'pages' => $pages,
            'max_pages' => $pages + 1,
            'records_gesamt' => $recordCount,
            'page_params' => '&id=' . $cacheId,
            'offset' => $page * $recordsPerPage,
            'records_per_page' => $recordsPerPage
        );

        if($page > 0) {
            $paging['show_page_back'] = 1;
        }

        if($page < $pages) {
            $paging['show_page_next'] = 1;
        }

        return $paging;
    }

    private function buildSearchLimitSelect($listName)
    {
        $limits = $this->pageSizeOptions();
        $selectedLimit = isset($_SESSION['search'][$listName]['limit'])
            ? (int)$_SESSION['search'][$listName]['limit']
            : 15;
        $options = '';

        foreach($limits as $value => $label) {
            $selected = $selectedLimit === $value ? ' selected="selected"' : '';
            $options .= '<option value="' . $value . '"' . $selected . '>' . $label . '</option>';
        }

        return '<select name="search_limit" class="search_limit" style="width: 60px;">'
            . $options . '</select>';
    }

    private function pageSizeOptions()
    {
        return array(
            5 => '5',
            10 => '10',
            15 => '15',
            20 => '20',
            25 => '25',
            50 => '50',
            100 => '100',
            999999999 => 'all'
        );
    }

    private function getSearchState($listDefinition)
    {
        $listName = $listDefinition['name'];
        $searchPrefix = $listDefinition['search_prefix'];
        $searchState = array();

        foreach($listDefinition['item'] as $field) {
            $sessionKey = $searchPrefix . $field['field'];
            $searchState[$sessionKey] = isset($_SESSION['search'][$listName][$sessionKey])
                ? (string)$_SESSION['search'][$listName][$sessionKey]
                : '';
        }

        return $searchState;
    }

    private function resetPageWhenSearchChanged($previousSearchState, $listDefinition)
    {
        $currentSearchState = $this->getSearchState($listDefinition);
        $this->searchChanged = $previousSearchState !== $currentSearchState;

        if(!$this->searchChanged) {
            return;
        }

        $listName = $listDefinition['name'];

        if(!isset($_SESSION['search']) || !is_array($_SESSION['search'])) {
            $_SESSION['search'] = array();
        }

        if(!isset($_SESSION['search'][$listName]) || !is_array($_SESSION['search'][$listName])) {
            $_SESSION['search'][$listName] = array();
        }

        $_SESSION['search'][$listName]['page'] = 0;
    }

    private function setTemplateDefaults($template)
    {
        global $app;

        $template->setVar('app_title', $app->_conf['app_title']);
        $template->setVar('app_version', $app->_conf['app_version']);
        $template->setVar('app_link', $app->_conf['app_link']);
        $template->setVar('app_logo', $app->_conf['logo']);
        $template->setVar('theme', $_SESSION['s']['theme'], true);
        $template->setVar('html_content_encoding', $app->_conf['html_content_encoding']);
        $template->setVar('app_module', 'dbsdns', true);
        $template->setVar('globalsearch_noresults_text_txt', $app->lng('globalsearch_noresults_text_txt'));
    }
}
