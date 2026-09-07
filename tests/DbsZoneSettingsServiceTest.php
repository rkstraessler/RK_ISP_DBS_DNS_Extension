<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsZoneSettingsService.inc.php';

class ZoneSettingsTestAccess
{
    public function getAccessibleZoneByCacheId($context, $cacheId)
    {
        if(!isset($context['allowed']) || !in_array((int)$cacheId, $context['allowed'], true)) {
            return null;
        }

        return array('cache_id' => (int)$cacheId, 'normalized_domain' => 'example.de');
    }
}

class ZoneSettingsTestClient
{
    public $getCalls = 0;
    public $updateCalls = array();
    public $responses = array();
    public $updateException;

    public function getZoneInfo($origin)
    {
        $this->getCalls++;
        $index = min($this->getCalls - 1, count($this->responses) - 1);

        return $this->responses[$index];
    }

    public function updateZoneSettings($origin, $settings)
    {
        $this->updateCalls[] = array($origin, $settings);

        if($this->updateException !== null) {
            throw $this->updateException;
        }
    }
}

function zoneSettingsAssertSame($expected, $actual, $message)
{
    if($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function zoneSettingsExpect($callback, $class, $code)
{
    try {
        $callback();
    } catch (Throwable $exception) {
        zoneSettingsAssertSame($class, get_class($exception), 'Unerwartete Exceptionklasse bei Zoneneinstellungen.');
        zoneSettingsAssertSame($code, $exception->getCode(), 'Unerwarteter Fehlercode bei Zoneneinstellungen.');
        return;
    }

    throw new RuntimeException('Die erwartete Zoneneinstellungs-Exception blieb aus.');
}

function zoneSettingsZone($settings)
{
    return array_merge(array(
        'origin' => 'example.de',
        'primary' => 'dns.dns1.de',
        'records' => array()
    ), $settings);
}

$testFailure = null;

try {
    $old = array(
        'mbox' => 'hostmaster.example.de',
        'refresh' => '28800',
        'retry' => '7200',
        'expire' => '604800',
        'minimum_ttl' => '86400',
        'ttl' => '86400'
    );
    $new = array(
        'mbox' => 'dns.example.de',
        'refresh' => '14400',
        'retry' => '3600',
        'expire' => '1209600',
        'minimum_ttl' => '300',
        'ttl' => '3600'
    );
    $client = new ZoneSettingsTestClient();
    $client->responses = array(zoneSettingsZone($old), zoneSettingsZone($new));
    $identity = new DbsZoneSettingsIdentity(str_repeat('z', 64));
    $oldToken = $identity->sign(42, 'example.de', $old);
    $newToken = $identity->sign(42, 'example.de', $new);
    $factoryCalls = 0;
    $service = new DbsZoneSettingsService(new ZoneSettingsTestAccess(), $identity, function() use ($client, &$factoryCalls) {
        $factoryCalls++;
        return $client;
    });
    $result = $service->update(array('allowed' => array(42)), 42, $oldToken, $new);

    zoneSettingsAssertSame(true, $result['changed'], 'Eine geänderte SOA wird als No-op behandelt.');
    zoneSettingsAssertSame(2, $client->getCalls, 'Die SOA wird nicht vor und nach dem Write live geladen.');
    zoneSettingsAssertSame(array(array('example.de', $new)), $client->updateCalls, 'Der SOA-Service übergibt nicht exakt die sechs freigegebenen Felder.');
    zoneSettingsAssertSame(false, isset($client->updateCalls[0][1]['primary']), 'Primary wird unerwartet geschrieben.');

    $client->getCalls = 0;
    $client->updateCalls = array();
    $client->responses = array(zoneSettingsZone($new));
    $noOp = $service->update(array('allowed' => array(42)), '42', $newToken, $new);
    zoneSettingsAssertSame(false, $noOp['changed'], 'Identische SOA-Werte werden nicht als No-op erkannt.');
    zoneSettingsAssertSame(array(), $client->updateCalls, 'Identische SOA-Werte lösen einen Providerwrite aus.');
    zoneSettingsAssertSame(1, $client->getCalls, 'Ein SOA-No-op lädt die Zone unnötig mehrfach.');

    $factoryBeforeDenied = $factoryCalls;
    zoneSettingsExpect(function() use ($service, $new, $newToken) {
        $service->update(array('allowed' => array()), 42, $newToken, $new);
    }, 'DbsZoneSettingsException', DbsZoneSettingsService::ERROR_ACCESS_DENIED);
    zoneSettingsAssertSame($factoryBeforeDenied, $factoryCalls, 'Eine fremde Zone erzeugt einen DBS-Client.');

    foreach(array(
        array('field' => 'mbox', 'value' => ''),
        array('field' => 'mbox', 'value' => "bad mailbox"),
        array('field' => 'refresh', 'value' => '-1'),
        array('field' => 'retry', 'value' => '1x'),
        array('field' => 'expire', 'value' => '2147483648'),
        array('field' => 'minimum_ttl', 'value' => '+60'),
        array('field' => 'ttl', 'value' => '')
    ) as $invalidCase) {
        $invalid = $new;
        $invalid[$invalidCase['field']] = $invalidCase['value'];
        $factoryBeforeInvalid = $factoryCalls;
        zoneSettingsExpect(function() use ($service, $invalid, $newToken) {
            $service->update(array('allowed' => array(42)), 42, $newToken, $invalid);
        }, 'DbsZoneSettingsException', DbsZoneSettingsService::ERROR_INVALID_SETTINGS);
        zoneSettingsAssertSame($factoryBeforeInvalid, $factoryCalls, 'Ungültige SOA-Werte erzeugen einen DBS-Client.');
    }

    $client->getCalls = 0;
    $client->updateCalls = array();
    $client->responses = array(zoneSettingsZone($old));
    $client->updateException = new DbsClientException('safe', DbsClient::ERROR_REQUEST_REJECTED);
    zoneSettingsExpect(function() use ($service, $new, $oldToken) {
        $service->update(array('allowed' => array(42)), 42, $oldToken, $new);
    }, 'DbsClientException', DbsClient::ERROR_REQUEST_REJECTED);
    $client->updateException = null;

    $client->getCalls = 0;
    $client->updateCalls = array();
    $client->responses = array(zoneSettingsZone($old), zoneSettingsZone($old));
    zoneSettingsExpect(function() use ($service, $new, $oldToken) {
        $service->update(array('allowed' => array(42)), 42, $oldToken, $new);
    }, 'DbsZoneSettingsException', DbsZoneSettingsService::ERROR_VERIFICATION_FAILED);
    zoneSettingsAssertSame(2, $client->getCalls, 'Ein nicht bestätigter SOA-Write lädt den Providerzustand nicht frisch.');

    $client->getCalls = 0;
    $client->updateCalls = array();
    $client->responses = array(zoneSettingsZone($new));
    zoneSettingsExpect(function() use ($service, $new, $oldToken) {
        $service->update(array('allowed' => array(42)), 42, $oldToken, $new);
    }, 'DbsZoneSettingsException', DbsZoneSettingsService::ERROR_STALE_SETTINGS);
    zoneSettingsAssertSame(array(), $client->updateCalls, 'Ein stale SOA-Snapshot löst einen Providerwrite aus.');

    $tamperedToken = substr($newToken, 0, -1) . (substr($newToken, -1) === 'a' ? 'b' : 'a');
    $factoryBeforeTampered = $factoryCalls;
    zoneSettingsExpect(function() use ($service, $new, $tamperedToken) {
        $service->update(array('allowed' => array(42)), 42, $tamperedToken, $new);
    }, 'DbsZoneSettingsException', DbsZoneSettingsService::ERROR_INVALID_IDENTIFIER);
    zoneSettingsAssertSame($factoryBeforeTampered, $factoryCalls, 'Ein manipulierter SOA-HMAC erzeugt einen DBS-Client.');

    $controller = file_get_contents(__DIR__ . '/../src/dbsdns/zone_settings.php');
    $serviceSource = file_get_contents(__DIR__ . '/../src/dbsdns/lib/classes/DbsZoneSettingsService.inc.php');
    zoneSettingsAssertSame(true,
        strpos($controller, "REQUEST_METHOD'] !== 'POST'") !== false
        && strpos($controller, "csrf_token_check('POST')") !== false
        && strpos($controller, 'getAccessibleZoneByCacheId') === false
        && stripos($controller . $serviceSource, 'dns_soa') === false,
        'POST-/CSRF-/DomainAccess-Kapselung oder Core-Freiheit der SOA-Route fehlt.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'DBS-Zoneneinstellungen, Validierung, Live-Verifikation und Core-Freiheit erfolgreich geprüft.' . PHP_EOL;
