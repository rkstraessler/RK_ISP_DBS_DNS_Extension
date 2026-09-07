<?php

require_once __DIR__ . '/DomainAccess.inc.php';
require_once __DIR__ . '/DomainAssignment.inc.php';
require_once __DIR__ . '/DomainMatcher.inc.php';
require_once __DIR__ . '/DbsModuleAccess.inc.php';
require_once __DIR__ . '/ZoneCache.inc.php';

class DbsZoneAccessException extends RuntimeException
{
    const ERROR_CONFIGURATION_REQUIRED = 1;
}

class DbsZoneAccess
{
    private $db;
    private $matcher;
    private $cache;
    private $domainAccess;

    public function __construct($db, DomainMatcher $matcher)
    {
        $this->db = $db;
        $this->matcher = $matcher;
        $this->cache = new ZoneCache($db, $matcher);
        $this->domainAccess = new DomainAccess($db, $matcher);
    }

    public function createContextFromIspConfig($auth, $sessionUser)
    {
        $context = $this->domainAccess->createContextFromIspConfig($auth, $sessionUser);

        if(
            isset($context['role'])
            && in_array($context['role'], array(DomainAccess::ROLE_CLIENT, DomainAccess::ROLE_RESELLER), true)
            && !DbsModuleAccess::isProviderConfigurationAvailable($this->db)
        ) {
            throw new DbsZoneAccessException(
                'DBS-Verbindung ist für Kunden noch nicht freigegeben.',
                DbsZoneAccessException::ERROR_CONFIGURATION_REQUIRED
            );
        }

        return $context;
    }

    public function getAccessibleZones($context)
    {
        $cachedDomains = $this->cache->getPresentDomains();
        $candidateDomains = $this->domainAccess->getAccessibleDomains($context, $cachedDomains);

        if(count($candidateDomains) === 0) {
            return array();
        }

        $domainAssignment = new DomainAssignment($this->db, $this->matcher);
        $candidateNames = array();

        foreach($candidateDomains as $cachedDomain) {
            if(isset($cachedDomain['normalized_domain'])) {
                $candidateNames[] = $cachedDomain['normalized_domain'];
            }
        }

        $assignments = $domainAssignment->getNativeAssignments($candidateNames);
        $accessible = array();

        foreach($candidateDomains as $cachedDomain) {
            $domain = isset($cachedDomain['normalized_domain'])
                ? (string)$cachedDomain['normalized_domain']
                : '';

            if(
                $domain === ''
                || !isset($assignments[$domain])
                || empty($assignments[$domain]['relation_valid'])
            ) {
                continue;
            }

            $accessible[] = array_merge($cachedDomain, array(
                'native_assignment' => $assignments[$domain]
            ));
        }

        usort($accessible, function($left, $right) {
            return strcmp($left['normalized_domain'], $right['normalized_domain']);
        });

        return $accessible;
    }

    public function getAccessibleZoneByCacheId($context, $cacheId)
    {
        $cacheId = (int)$cacheId;

        if($cacheId <= 0) {
            return null;
        }

        foreach($this->getAccessibleZones($context) as $zone) {
            if((int)$zone['cache_id'] === $cacheId) {
                return $zone;
            }
        }

        return null;
    }
}
