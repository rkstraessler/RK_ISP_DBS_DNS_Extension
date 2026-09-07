<?php

require_once __DIR__ . '/DomainMatcher.inc.php';
require_once __DIR__ . '/ZoneCache.inc.php';

class ZoneInventorySyncException extends RuntimeException
{
    const ERROR_DATABASE = 1;
    const ERROR_INVALID_INVENTORY = 2;
}

class ZoneInventorySync
{
    private $db;
    private $matcher;

    public function __construct($db, DomainMatcher $matcher)
    {
        if(
            !is_object($db) ||
            !method_exists($db, 'query') ||
            !method_exists($db, 'queryAllRecords')
        ) {
            throw new ZoneInventorySyncException(
                'ISPConfig-Datenbankzugriff nicht verfügbar.',
                ZoneInventorySyncException::ERROR_DATABASE
            );
        }

        $this->db = $db;
        $this->matcher = $matcher;
    }

    public function synchronize($domains, $syncedAt = null, $emptyInventoryConfirmed = false)
    {
        if(!is_array($domains) || !is_bool($emptyInventoryConfirmed)) {
            throw new ZoneInventorySyncException(
                'Das DBS-Domaininventar ist ungültig.',
                ZoneInventorySyncException::ERROR_INVALID_INVENTORY
            );
        }

        $syncedAt = $syncedAt === null ? gmdate('Y-m-d H:i:s') : (string)$syncedAt;

        if(!$this->isValidTimestamp($syncedAt)) {
            throw new ZoneInventorySyncException(
                'Der Synchronisationszeitpunkt ist ungültig.',
                ZoneInventorySyncException::ERROR_INVALID_INVENTORY
            );
        }

        $inventory = $this->normalizeInventory($domains);

        if(
            (count($inventory) === 0 && !$emptyInventoryConfirmed) ||
            (count($inventory) > 0 && $emptyInventoryConfirmed)
        ) {
            throw new ZoneInventorySyncException(
                'Ein leeres DBS-Domaininventar wurde nicht eindeutig bestätigt.',
                ZoneInventorySyncException::ERROR_INVALID_INVENTORY
            );
        }

        $existingRecords = $this->db->queryAllRecords(
            "SELECT domain, provider_present FROM `" . ZoneCache::TABLE . "`"
        );

        if(!is_array($existingRecords) || $this->hasDatabaseError()) {
            throw new ZoneInventorySyncException(
                'Das bestehende DBS-Domaininventar konnte nicht geladen werden.',
                ZoneInventorySyncException::ERROR_DATABASE
            );
        }

        $existing = array();

        foreach($existingRecords as $record) {
            if(!isset($record['domain']) || !is_string($record['domain'])) {
                continue;
            }

            $domain = $this->matcher->normalizeDomain($record['domain']);

            if($domain !== false) {
                $existing[$domain] = isset($record['provider_present'])
                    ? (string)$record['provider_present']
                    : 'N';
            }
        }

        $created = 0;
        $updated = 0;
        $missing = 0;

        foreach($inventory as $domain => $entry) {
            if(isset($existing[$domain])) {
                $updated++;
            } else {
                $created++;
            }
        }

        foreach($existing as $domain => $present) {
            if(!isset($inventory[$domain]) && $present === 'Y') {
                $missing++;
            }
        }

        $transactionStarted = false;

        try {
            $this->execute('START TRANSACTION');
            $transactionStarted = true;
            $this->execute(
                "UPDATE `" . ZoneCache::TABLE . "`\n"
                . "SET provider_present = 'N', last_synced_at = ?",
                $syncedAt
            );

            foreach($inventory as $entry) {
                $this->execute(
                    "INSERT INTO `" . ZoneCache::TABLE . "`\n"
                    . "    (domain, provider_status, provider_present, first_seen_at, last_seen_at, last_synced_at)\n"
                    . "VALUES (?, ?, 'Y', ?, ?, ?)\n"
                    . "ON DUPLICATE KEY UPDATE\n"
                    . "    provider_status = VALUES(provider_status),\n"
                    . "    provider_present = 'Y',\n"
                    . "    last_seen_at = VALUES(last_seen_at),\n"
                    . "    last_synced_at = VALUES(last_synced_at)",
                    $entry['domain'],
                    $entry['status'],
                    $syncedAt,
                    $syncedAt,
                    $syncedAt
                );
            }

            $this->execute('COMMIT');
            $transactionStarted = false;
        } catch (Throwable $exception) {
            if($transactionStarted) {
                try {
                    $this->db->query('ROLLBACK');
                } catch (Throwable $rollbackException) {
                }
            }

            throw new ZoneInventorySyncException(
                'Das DBS-Domaininventar konnte nicht sicher aktualisiert werden.',
                ZoneInventorySyncException::ERROR_DATABASE,
                $exception
            );
        }

        return array(
            'total' => count($inventory),
            'created' => $created,
            'updated' => $updated,
            'missing' => $missing,
            'synced_at' => $syncedAt
        );
    }

    private function normalizeInventory($domains)
    {
        $inventory = array();

        foreach($domains as $entry) {
            if(!is_array($entry)) {
                throw new ZoneInventorySyncException(
                    'Das DBS-Domaininventar enthält einen ungültigen Eintrag.',
                    ZoneInventorySyncException::ERROR_INVALID_INVENTORY
                );
            }

            $domainCandidate = '';

            if(isset($entry['origin']) && is_string($entry['origin'])) {
                $domainCandidate = $entry['origin'];
            } elseif(isset($entry['domain_name']) && is_string($entry['domain_name'])) {
                $domainCandidate = $entry['domain_name'];
            }

            $domain = $this->matcher->normalizeDomain($domainCandidate);
            $status = isset($entry['status']) && is_scalar($entry['status'])
                ? trim((string)$entry['status'])
                : '';

            if($domain === false || strlen($status) > 64 || isset($inventory[$domain])) {
                throw new ZoneInventorySyncException(
                    'Das DBS-Domaininventar enthält einen ungültigen oder doppelten Eintrag.',
                    ZoneInventorySyncException::ERROR_INVALID_INVENTORY
                );
            }

            $inventory[$domain] = array(
                'domain' => $domain,
                'status' => $status
            );
        }

        ksort($inventory, SORT_STRING);

        return $inventory;
    }

    private function execute($query)
    {
        $arguments = func_get_args();
        $result = call_user_func_array(array($this->db, 'query'), $arguments);

        if(
            $result === false ||
            (property_exists($this->db, 'errorMessage') && $this->db->errorMessage !== '')
        ) {
            throw new RuntimeException('Datenbankoperation fehlgeschlagen.');
        }

        return $result;
    }

    private function isValidTimestamp($timestamp)
    {
        $date = DateTime::createFromFormat('!Y-m-d H:i:s', $timestamp, new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d H:i:s') === $timestamp;
    }

    private function hasDatabaseError()
    {
        return property_exists($this->db, 'errorMessage')
            && $this->db->errorMessage !== '';
    }
}
