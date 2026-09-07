<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsClient.inc.php';

function dbsInspectorTargetTypes()
{
    return array('ALIAS', 'CAA', 'NS', 'SRV', 'TLSA', 'TXT');
}

function dbsInspectorParseOptions($arguments)
{
    $options = array(
        'maximum_examples' => 3,
        'delay_milliseconds' => 250,
        'help' => false
    );

    foreach(array_slice($arguments, 1) as $argument) {
        if($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }

        if(preg_match('/\A--examples=([0-9]+)\z/', $argument, $matches)) {
            $options['maximum_examples'] = (int)$matches[1];
            continue;
        }

        if(preg_match('/\A--delay-ms=([0-9]+)\z/', $argument, $matches)) {
            $options['delay_milliseconds'] = (int)$matches[1];
            continue;
        }

        throw new InvalidArgumentException('Unbekannte Option.');
    }

    if($options['maximum_examples'] < 1 || $options['maximum_examples'] > 3) {
        throw new InvalidArgumentException('--examples muss zwischen 1 und 3 liegen.');
    }

    if($options['delay_milliseconds'] < 0 || $options['delay_milliseconds'] > 10000) {
        throw new InvalidArgumentException('--delay-ms muss zwischen 0 und 10000 liegen.');
    }

    return $options;
}

function dbsInspectorAllTypesFound($examples)
{
    foreach(dbsInspectorTargetTypes() as $type) {
        if(count($examples[$type]) === 0) {
            return false;
        }
    }

    return true;
}

function dbsInspectorRecordTuple($record)
{
    $fields = array('name', 'type', 'data', 'aux', 'ttl');
    $tuple = array();

    if(!is_array($record)) {
        return null;
    }

    foreach($fields as $field) {
        if(!array_key_exists($field, $record) || !is_string($record[$field])) {
            return null;
        }

        $tuple[$field] = $record[$field];
    }

    return $tuple;
}

function dbsInspectorOriginKey($origin)
{
    if(!is_string($origin)) {
        return null;
    }

    $origin = strtolower(rtrim(trim($origin), '.'));

    return $origin === '' ? null : $origin;
}

function dbsInspectorScan($client, $maximumExamples, $delayMilliseconds, $sleeper = null)
{
    $targetTypes = array_fill_keys(dbsInspectorTargetTypes(), true);
    $examples = array();
    $seenExamples = array();

    foreach(dbsInspectorTargetTypes() as $type) {
        $examples[$type] = array();
        $seenExamples[$type] = array();
    }

    if($sleeper === null) {
        $sleeper = function($milliseconds) {
            usleep($milliseconds * 1000);
        };
    }

    $domains = $client->listAllDomains();

    if(!is_array($domains)) {
        throw new RuntimeException('Die DBS-Domainliste ist ungültig.');
    }

    $seenOrigins = array();
    $domainRequests = 0;
    $domainErrors = 0;
    $duplicateDomains = 0;

    foreach($domains as $domain) {
        if(!is_array($domain) || !array_key_exists('origin', $domain)) {
            $domainErrors++;
            continue;
        }

        $originKey = dbsInspectorOriginKey($domain['origin']);

        if($originKey === null) {
            $domainErrors++;
            continue;
        }

        if(isset($seenOrigins[$originKey])) {
            $duplicateDomains++;
            continue;
        }

        $seenOrigins[$originKey] = true;

        if($domainRequests > 0 && $delayMilliseconds > 0) {
            call_user_func($sleeper, $delayMilliseconds);
        }

        $domainRequests++;

        try {
            $zone = $client->getZoneInfo($domain['origin']);
        } catch (Throwable $exception) {
            $domainErrors++;
            continue;
        }

        if(!is_array($zone) || !isset($zone['records']) || !is_array($zone['records'])) {
            $domainErrors++;
            continue;
        }

        foreach($zone['records'] as $record) {
            $tuple = dbsInspectorRecordTuple($record);

            if($tuple === null) {
                continue;
            }

            $targetType = strtoupper(trim($tuple['type']));

            if(!isset($targetTypes[$targetType]) || count($examples[$targetType]) >= $maximumExamples) {
                continue;
            }

            $fingerprint = hash('sha256', serialize($tuple));

            if(isset($seenExamples[$targetType][$fingerprint])) {
                continue;
            }

            $seenExamples[$targetType][$fingerprint] = true;
            $examples[$targetType][] = $tuple;
        }

        if(dbsInspectorAllTypesFound($examples)) {
            break;
        }
    }

    return array(
        'examples' => $examples,
        'domain_requests' => $domainRequests,
        'domain_errors' => $domainErrors,
        'duplicate_domains' => $duplicateDomains
    );
}

function dbsInspectorDescribeForm($value)
{
    if($value === '') {
        return 'leer';
    }

    $trimmed = trim($value);
    $parts = $trimmed === '' ? 0 : count(preg_split('/\s+/', $trimmed));
    $details = array(
        'Bytes=' . strlen($value),
        'Leerraumteile=' . $parts,
        'Punkte=' . substr_count($value, '.'),
        'Endpunkt=' . (substr($value, -1) === '.' ? 'ja' : 'nein'),
        'Doppelquotes=' . substr_count($value, '"'),
        'Backslashes=' . substr_count($value, '\\'),
        'Unterstrich=' . (strpos($value, '_') === false ? 'nein' : 'ja')
    );

    return implode(', ', $details);
}

function dbsInspectorUniqueForms($records, $field)
{
    $forms = array();

    foreach($records as $record) {
        $form = dbsInspectorDescribeForm($record[$field]);
        $forms[$form] = true;
    }

    return count($forms) === 0 ? '—' : implode(' / ', array_keys($forms));
}

function dbsInspectorExactValues($records, $field)
{
    $values = array();

    foreach($records as $record) {
        $values[$record[$field]] = true;
    }

    if(count($values) === 0) {
        return '—';
    }

    $encoded = json_encode(array_keys($values), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return $encoded === false ? '[nicht darstellbar]' : $encoded;
}

function dbsInspectorTableCell($value)
{
    return str_replace(
        array('|', "\r", "\n"),
        array('\\|', '\\r', '\\n'),
        $value
    );
}

function dbsInspectorAnonymize($value)
{
    if($value === '') {
        return '';
    }

    $anonymized = preg_replace_callback('/[\p{L}\p{N}]+/u', function($matches) {
        return '{mask:' . strlen($matches[0]) . '}';
    }, $value);

    if($anonymized === null) {
        return '[maskiert; Bytes=' . strlen($value) . ']';
    }

    return $anonymized;
}

function dbsInspectorRenderReport($report)
{
    $lines = array(
        'Typ | gefunden? | name-Form | data-Form | aux | ttl | Anzahl Beispiele',
        '--- | --- | --- | --- | --- | --- | ---'
    );

    foreach(dbsInspectorTargetTypes() as $type) {
        $records = $report['examples'][$type];
        $lines[] = implode(' | ', array(
            $type,
            count($records) > 0 ? 'ja' : 'nein',
            dbsInspectorTableCell(dbsInspectorUniqueForms($records, 'name')),
            dbsInspectorTableCell(dbsInspectorUniqueForms($records, 'data')),
            dbsInspectorTableCell(dbsInspectorExactValues($records, 'aux')),
            dbsInspectorTableCell(dbsInspectorExactValues($records, 'ttl')),
            (string)count($records)
        ));
    }

    $lines[] = '';
    $lines[] = sprintf(
        'Untersuchte Zonen: %d; Zonenfehler: %d; übersprungene doppelte Origins: %d.',
        $report['domain_requests'],
        $report['domain_errors'],
        $report['duplicate_domains']
    );

    foreach(dbsInspectorTargetTypes() as $type) {
        if(count($report['examples'][$type]) === 0) {
            continue;
        }

        $lines[] = '';
        $lines[] = $type . ':';

        foreach($report['examples'][$type] as $index => $record) {
            $anonymizedRecord = array(
                'name' => dbsInspectorAnonymize($record['name']),
                'type' => $record['type'],
                'data' => dbsInspectorAnonymize($record['data']),
                'aux' => $record['aux'],
                'ttl' => $record['ttl']
            );
            $json = json_encode(
                $anonymizedRecord,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );

            $lines[] = 'Beispiel ' . ($index + 1) . ':';
            $lines[] = $json === false ? '{"Fehler":"nicht darstellbar"}' : $json;
        }
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function dbsInspectorUsage()
{
    return implode(PHP_EOL, array(
        'Verwendung: php scripts/inspect-dbs-record-types.php [--examples=1..3] [--delay-ms=0..10000]',
        'Die drei bestehenden DBS_* Environment-Variablen müssen im CLI-Prozess verfügbar sein.',
        'name und data werden immer anonymisiert; eine Rohdatenausgabe ist nicht vorgesehen.'
    )) . PHP_EOL;
}

function dbsInspectorMain($arguments)
{
    try {
        $options = dbsInspectorParseOptions($arguments);

        if($options['help']) {
            fwrite(STDOUT, dbsInspectorUsage());
            return 0;
        }

        $client = new DbsClient();
        $report = dbsInspectorScan(
            $client,
            $options['maximum_examples'],
            $options['delay_milliseconds']
        );
        fwrite(STDOUT, dbsInspectorRenderReport($report));

        return 0;
    } catch (InvalidArgumentException $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL . dbsInspectorUsage());
    } catch (DbsConfigurationException $exception) {
        fwrite(STDERR, 'Die bestehende DBS-Konfiguration ist für diesen CLI-Prozess nicht verfügbar.' . PHP_EOL);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Die READ-ONLY-Diagnose ist fehlgeschlagen; Details wurden nicht ausgegeben.' . PHP_EOL);
    }

    return 1;
}

if(
    PHP_SAPI === 'cli' &&
    isset($_SERVER['SCRIPT_FILENAME']) &&
    realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
) {
    exit(dbsInspectorMain($argv));
}
