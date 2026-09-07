<?php

class DbsAssignmentListView
{
    public function build($matches, $statusFilter, $searchTerm, $sortField, $sortDirection, $page, $pageSize)
    {
        $matches = is_array($matches) ? $matches : array();
        $searchTerm = is_string($searchTerm) ? $searchTerm : '';
        $sortFields = array(
            'domain' => 'domain_name',
            'status' => 'effective_status',
            'client' => 'client_label',
            'reseller' => 'reseller_label'
        );

        if(!isset($sortFields[$sortField])) {
            $sortField = 'domain';
        }

        if($sortDirection !== 'desc') {
            $sortDirection = 'asc';
        }

        $filtered = array();

        foreach($matches as $match) {
            if(!is_array($match)) {
                continue;
            }

            $effectiveStatus = isset($match['effective_status']) && is_scalar($match['effective_status'])
                ? (string)$match['effective_status']
                : '';

            if($statusFilter === 'saved') {
                $nativeStatus = isset($match['native_status']) && is_scalar($match['native_status'])
                    ? (string)$match['native_status']
                    : '';

                if(!in_array($nativeStatus, array('same', 'existing'), true)) {
                    continue;
                }
            } elseif($statusFilter !== 'all' && $statusFilter !== $effectiveStatus) {
                continue;
            }

            if($searchTerm !== '' && stripos($this->searchText($match), $searchTerm) === false) {
                continue;
            }

            $filtered[] = $match;
        }

        $field = $sortFields[$sortField];
        usort($filtered, function($left, $right) use ($field, $sortDirection) {
            $leftValue = isset($left[$field]) && is_scalar($left[$field]) ? (string)$left[$field] : '';
            $rightValue = isset($right[$field]) && is_scalar($right[$field]) ? (string)$right[$field] : '';
            $comparison = strnatcasecmp($leftValue, $rightValue);

            if($comparison === 0 && $field !== 'domain_name') {
                $comparison = strnatcasecmp(
                    isset($left['domain_name']) ? (string)$left['domain_name'] : '',
                    isset($right['domain_name']) ? (string)$right['domain_name'] : ''
                );
            }

            return $sortDirection === 'desc' ? -$comparison : $comparison;
        });

        $pageSize = max(1, (int)$pageSize);
        $filteredCount = count($filtered);
        $totalPages = max(1, (int)ceil($filteredCount / $pageSize));
        $page = max(1, min($totalPages, (int)$page));

        return array(
            'filtered' => $filtered,
            'visible' => array_slice($filtered, ($page - 1) * $pageSize, $pageSize),
            'filtered_count' => $filteredCount,
            'total_pages' => $totalPages,
            'page' => $page
        );
    }

    private function searchText($match)
    {
        $values = array();

        foreach(array('domain_name', 'origin', 'client_label', 'reseller_label', 'effective_status', 'native_status') as $key) {
            if(isset($match[$key]) && is_scalar($match[$key])) {
                $values[] = (string)$match[$key];
            }
        }

        if(isset($match['native_assignment']['client_label']) && is_scalar($match['native_assignment']['client_label'])) {
            $values[] = (string)$match['native_assignment']['client_label'];
        }

        if(isset($match['sources']) && is_array($match['sources'])) {
            $values[] = implode(' ', $match['sources']);
        }

        return implode(' ', $values);
    }
}
