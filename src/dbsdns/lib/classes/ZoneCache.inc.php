<?php

require_once __DIR__ . '/DomainMatcher.inc.php';

class ZoneCacheException extends RuntimeException
{
    const ERROR_DATABASE = 1;
    const ERROR_INVALID_DOMAIN = 2;
    const ERROR_INVALID_RECORD = 3;
}

class ZoneCache
{
    const TABLE = 'dbsdns_zone_cache';

    private $db;
    private $matcher;

    public function __construct($db, DomainMatcher $matcher)
    {
        if(
            !is_object($db) ||
            !method_exists($db, 'queryAllRecords') ||
            !method_exists($db, 'queryOneRecord')
        ) {
            throw new ZoneCacheException(
                'ISPConfig-Datenbankzugriff nicht verfügbar.',
                ZoneCacheException::ERROR_DATABASE
            );
        }

        $this->db = $db;
        $this->matcher = $matcher;
    }

    public function getPresentDomains()
    {
        $records = $this->db->queryAllRecords(
            "SELECT cache_id, domain, provider_status, provider_present,\n"
            . "       first_seen_at, last_seen_at, last_synced_at\n"
            . "FROM `" . self::TABLE . "`\n"
            . "WHERE provider_present = 'Y'\n"
            . "ORDER BY domain"
        );

        if(!is_array($records) || $this->hasDatabaseError()) {
            throw new ZoneCacheException(
                'Das DBS-Domaininventar konnte nicht geladen werden.',
                ZoneCacheException::ERROR_DATABASE
            );
        }

        $domains = array();

        foreach($records as $record) {
            $domain = $this->normalizeRecord($record);

            if($domain !== null) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    public function getPresentDomainByName($domain)
    {
        $domain = $this->matcher->normalizeDomain($domain);

        if($domain === false) {
            return null;
        }

        $record = $this->db->queryOneRecord(
            "SELECT cache_id, domain, provider_status, provider_present,\n"
            . "       first_seen_at, last_seen_at, last_synced_at\n"
            . "FROM `" . self::TABLE . "`\n"
            . "WHERE domain = ? AND provider_present = 'Y'",
            $domain
        );

        if($this->hasDatabaseError()) {
            throw new ZoneCacheException(
                'Das DBS-Domaininventar konnte nicht geladen werden.',
                ZoneCacheException::ERROR_DATABASE
            );
        }

        if(!is_array($record)) {
            return null;
        }

        return $this->normalizeRecord($record);
    }

    public function getSummary()
    {
        $record = $this->db->queryOneRecord(
            "SELECT COUNT(*) AS total_count,\n"
            . "       SUM(CASE WHEN provider_present = 'Y' THEN 1 ELSE 0 END) AS present_count,\n"
            . "       SUM(CASE WHEN provider_present = 'N' THEN 1 ELSE 0 END) AS missing_count,\n"
            . "       MAX(last_synced_at) AS last_synced_at\n"
            . "FROM `" . self::TABLE . "`"
        );

        if(!is_array($record) || $this->hasDatabaseError()) {
            throw new ZoneCacheException(
                'Der Status des DBS-Domaininventars konnte nicht geladen werden.',
                ZoneCacheException::ERROR_DATABASE
            );
        }

        return array(
            'total_count' => isset($record['total_count']) ? (int)$record['total_count'] : 0,
            'present_count' => isset($record['present_count']) ? (int)$record['present_count'] : 0,
            'missing_count' => isset($record['missing_count']) ? (int)$record['missing_count'] : 0,
            'last_synced_at' => isset($record['last_synced_at']) && is_scalar($record['last_synced_at'])
                ? (string)$record['last_synced_at']
                : ''
        );
    }

    private function normalizeRecord($record)
    {
        if(!is_array($record)) {
            return null;
        }

        $cacheId = isset($record['cache_id']) ? (int)$record['cache_id'] : 0;
        $domain = isset($record['domain']) && is_string($record['domain'])
            ? $this->matcher->normalizeDomain($record['domain'])
            : false;

        if($cacheId <= 0 || $domain === false) {
            throw new ZoneCacheException(
                'Das DBS-Domaininventar enthält einen ungültigen Datensatz.',
                ZoneCacheException::ERROR_INVALID_RECORD
            );
        }

        return array(
            'cache_id' => $cacheId,
            'domain_name' => $domain,
            'origin' => $domain,
            'normalized_domain' => $domain,
            'status' => isset($record['provider_status']) && is_scalar($record['provider_status'])
                ? (string)$record['provider_status']
                : '',
            'provider_present' => isset($record['provider_present'])
                ? (string)$record['provider_present']
                : 'N',
            'first_seen_at' => isset($record['first_seen_at']) ? (string)$record['first_seen_at'] : '',
            'last_seen_at' => isset($record['last_seen_at']) ? (string)$record['last_seen_at'] : '',
            'last_synced_at' => isset($record['last_synced_at']) ? (string)$record['last_synced_at'] : ''
        );
    }

    private function hasDatabaseError()
    {
        return property_exists($this->db, 'errorMessage')
            && $this->db->errorMessage !== '';
    }
}
