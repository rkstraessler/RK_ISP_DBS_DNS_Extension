<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsRecordCapabilities.inc.php';

function zoneViewReadOnlyAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

function zoneViewReadOnlyAssertSame($expected, $actual, $message)
{
    if($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function zoneViewReadOnlyLoadWordbook($filename)
{
    $wb = array();
    include $filename;

    return $wb;
}

function zoneViewReadOnlyFindLine($template, $needle)
{
    foreach(preg_split('/\r?\n/', $template) as $line) {
        if(strpos($line, $needle) !== false) {
            return trim($line);
        }
    }

    return '';
}

function zoneViewReadOnlyRenderConditionalLine($line, $variables)
{
    if(!preg_match('/\A<tmpl_if name=["\']([^"\']+)["\']>(.*)<\/tmpl_if>\z/s', $line, $matches)) {
        throw new RuntimeException('Die Alert-Zeile verwendet keine prüfbare ISPConfig-tmpl_if-Struktur.');
    }

    $conditionName = $matches[1];

    if(!isset($variables[$conditionName]) || trim((string)$variables[$conditionName]) === '') {
        return '';
    }

    return preg_replace_callback(
        '/\{tmpl_var name=["\']([^"\']+)["\']\}/',
        function($variableMatch) use ($variables) {
            return isset($variables[$variableMatch[1]]) ? (string)$variables[$variableMatch[1]] : '';
        },
        $matches[2]
    );
}

$testFailure = null;

try {
    $root = dirname(__DIR__);
    $controller = file_get_contents($root . '/src/dbsdns/zone_view.php');
    $template = file_get_contents($root . '/src/dbsdns/templates/zone_view.htm');
    $recordRenderer = file_get_contents($root . '/src/dbsdns/lib/classes/DbsRecordListRenderer.inc.php');
    $recordTemplate = file_get_contents($root . '/src/dbsdns/templates/dbsdns_record_list.htm');
    $germanWordbook = zoneViewReadOnlyLoadWordbook($root . '/src/dbsdns/lib/lang/de_dbsdns.lng');
    $englishWordbook = zoneViewReadOnlyLoadWordbook($root . '/src/dbsdns/lib/lang/en_dbsdns.lng');

    zoneViewReadOnlyAssertTrue(
        strpos($controller, 'getAccessibleZoneByCacheId') !== false
        && strpos($controller, 'getZoneInfo') !== false
        && strpos($controller, 'getAccessibleZoneByCacheId') < strpos($controller, 'getZoneInfo'),
        'Die Live-Zone wird vor der Rechteprüfung geladen.'
    );
    zoneViewReadOnlyAssertTrue(
        strpos($controller, '$app->tpl->setVar($wb)') !== false
        && strpos($controller, 'include ISPC_ROOT_PATH') !== false,
        'Das frei gerenderte Zonentemplate erhält sein lokalisiertes Wörterbuch nicht.'
    );

    $labelKeys = array(
        'dbsdns_server_txt',
        'dbsdns_client_txt',
        'dbsdns_origin_txt',
        'dbsdns_primary_txt',
        'dbsdns_mbox_txt',
        'dbsdns_refresh_txt',
        'dbsdns_retry_txt',
        'dbsdns_expire_txt',
        'dbsdns_minimum_ttl_txt',
        'dbsdns_ttl_txt'
    );

    foreach($labelKeys as $labelKey) {
        zoneViewReadOnlyAssertTrue(
            isset($germanWordbook[$labelKey]) && trim($germanWordbook[$labelKey]) !== ''
            && isset($englishWordbook[$labelKey]) && trim($englishWordbook[$labelKey]) !== ''
            && strpos($template, 'name="' . $labelKey . '"') !== false,
            'Ein erwartetes lokalisiertes Zonenlabel fehlt: ' . $labelKey
        );
    }

    zoneViewReadOnlyAssertSame('Server', $germanWordbook['dbsdns_server_txt'], 'Das Server-Label ist falsch.');
    zoneViewReadOnlyAssertSame('Kunde', $germanWordbook['dbsdns_client_txt'], 'Das Kunden-Label ist falsch.');
    zoneViewReadOnlyAssertSame('Zone', $germanWordbook['dbsdns_origin_txt'], 'Das Zonen-Label ist falsch.');
    zoneViewReadOnlyAssertSame('NS', $germanWordbook['dbsdns_primary_txt'], 'Das NS-Label ist falsch.');
    zoneViewReadOnlyAssertSame('E-Mail', $germanWordbook['dbsdns_mbox_txt'], 'Das E-Mail-Label ist falsch.');
    zoneViewReadOnlyAssertSame('Refresh', $germanWordbook['dbsdns_refresh_txt'], 'Das Refresh-Label ist falsch.');
    zoneViewReadOnlyAssertSame('Retry', $germanWordbook['dbsdns_retry_txt'], 'Das Retry-Label ist falsch.');
    zoneViewReadOnlyAssertSame('Expire', $germanWordbook['dbsdns_expire_txt'], 'Das Expire-Label ist falsch.');
    zoneViewReadOnlyAssertSame('Minimum TTL', $germanWordbook['dbsdns_minimum_ttl_txt'], 'Das Minimum-TTL-Label ist falsch.');
    zoneViewReadOnlyAssertSame('TTL', $germanWordbook['dbsdns_ttl_txt'], 'Das TTL-Label ist falsch.');
    zoneViewReadOnlyAssertSame('Speichern', $germanWordbook['btn_save_txt'], 'Der deutsche Speichern-Button ist leer oder falsch.');
    zoneViewReadOnlyAssertSame('Abbrechen', $germanWordbook['btn_cancel_txt'], 'Der deutsche Abbrechen-Button ist leer oder falsch.');
    zoneViewReadOnlyAssertSame('Save', $englishWordbook['btn_save_txt'], 'Der englische Save-Button ist leer oder falsch.');
    zoneViewReadOnlyAssertSame('Cancel', $englishWordbook['btn_cancel_txt'], 'Der englische Cancel-Button ist leer oder falsch.');
    zoneViewReadOnlyAssertTrue(
        preg_match('/<label[^>]*>\s*:?\s*<\/label>/i', $template) !== 1,
        'Das Zonentemplate enthält weiterhin ein einzelnes : ohne Label.'
    );

    $alertVariables = array(
        'show_info_msg' => 'Erfolgreich gespeichert.',
        'show_warning_msg' => 'Bitte prüfen.',
        'show_error_msg' => 'Speichern fehlgeschlagen.',
        'dbsdns_readonly_notice_txt' => $germanWordbook['dbsdns_readonly_notice_txt']
    );

    foreach($alertVariables as $variableName => $message) {
        $alertLine = zoneViewReadOnlyFindLine($template, '<tmpl_if name="' . $variableName . '">');
        zoneViewReadOnlyAssertTrue($alertLine !== '', 'Eine Meldungsbox ist nicht an ihren Inhalt gebunden: ' . $variableName);
        zoneViewReadOnlyAssertSame(
            '',
            zoneViewReadOnlyRenderConditionalLine($alertLine, array($variableName => '')),
            'Eine leere Meldung rendert weiterhin einen Alert-Container: ' . $variableName
        );
        $renderedAlert = zoneViewReadOnlyRenderConditionalLine($alertLine, array($variableName => $message));
        zoneViewReadOnlyAssertTrue(
            strpos($renderedAlert, 'class="alert ') !== false && strpos($renderedAlert, $message) !== false,
            'Eine vorhandene Meldung wird nicht sichtbar gerendert: ' . $variableName
        );
    }

    zoneViewReadOnlyAssertTrue(
        strpos($controller, "foreach(array('show_info_msg', 'show_warning_msg', 'show_error_msg')") !== false
        && strpos($controller, "trim((string)\$_SESSION[\$messageName])") !== false
        && strpos($controller, "if(\$message !== '')") !== false,
        'Leere Flash-Meldungen werden im Controller nicht zentral verworfen.'
    );
    zoneViewReadOnlyAssertTrue(
        strpos($controller, "trim((string)\$wb['dbsdns_readonly_notice_txt'])") !== false,
        'Ein leerer oder nur aus Leerzeichen bestehender Read-only-Hinweis wird nicht verworfen.'
    );

    zoneViewReadOnlyAssertTrue(
        strpos($template, 'class="nav nav-tabs"') !== false
        && strpos($template, 'id="dbsdns-records"') !== false
        && strpos($template, 'id="dbsdns-zone-settings"') !== false
        && strpos($template, 'data-toggle="tab"') !== false
        && strpos($template, 'dbsdns_records_tab_txt') !== false
        && strpos($template, 'dbsdns_zone_settings_tab_txt') !== false,
        'Die sichtbaren Einträge-/Zoneneinstellungen-Tabs sind nicht ISPConfig-konsistent aufgebaut.'
    );
    zoneViewReadOnlyAssertSame('Einträge', $germanWordbook['dbsdns_records_tab_txt'], 'Der deutsche Einträge-Tab ist falsch beschriftet.');
    zoneViewReadOnlyAssertSame('Zone settings', $englishWordbook['dbsdns_zone_settings_tab_txt'], 'Der englische Zone-settings-Tab ist falsch beschriftet.');
    zoneViewReadOnlyAssertTrue(
        substr_count($template, 'disabled="disabled"') === 4
        && strpos($template, 'name="mbox"') !== false
        && strpos($template, 'name="refresh"') !== false
        && strpos($template, 'name="retry"') !== false
        && strpos($template, 'name="expire"') !== false
        && strpos($template, 'name="minimum_ttl"') !== false
        && strpos($template, 'name="ttl"') !== false
        && strpos($template, 'name="zone_settings_token"') !== false
        && strpos($controller, 'DbsZoneSettingsIdentity') !== false
        && strpos($template, 'data-form-action="dbsdns/zone_settings.php"') !== false
        && strpos($template, 'data-submit-form="pageForm"') !== false
        && strpos($template, 'btn_save_txt') !== false
        && strpos($template, 'btn_cancel_txt') !== false
        && substr_count($template, 'records_html') === 1
        && strpos($template, 'id="dbsdns-records"') < strpos($template, 'records_html'),
        'Read-only- und editierbare Zoneneinstellungen oder die Live-Recordliste sind falsch aufgebaut.'
    );

    zoneViewReadOnlyAssertSame(
        array('A', 'AAAA', 'CNAME', 'MX', 'NS', 'SRV', 'TXT'),
        DbsRecordCapabilities::supportedWritableRecordTypes(),
        'Die UI-Capability-Liste enthält einen neuen oder verliert einen vorhandenen Recordtyp.'
    );
    zoneViewReadOnlyAssertTrue(
        substr_count($recordRenderer, 'DbsRecordCapabilities::supportedWritableRecordTypes()') === 1
        && strpos($recordTemplate, '<tmpl_loop name="record_buttons">') !== false
        && stripos($recordTemplate, 'disabled') === false,
        'Create-Buttons werden nicht ausschließlich aus der zentralen Capability-Liste gerendert.'
    );

    foreach(array('ALIAS', 'CAA', 'TLSA', 'OPENPGPKEY') as $unsupportedType) {
        zoneViewReadOnlyAssertTrue(
            strpos($recordTemplate, '>' . $unsupportedType . '<') === false,
            'Ein nicht unterstützter Recordtyp wird als Button gerendert: ' . $unsupportedType
        );
    }

    zoneViewReadOnlyAssertTrue(
        strpos($recordTemplate, 'icon-edit') !== false
        && strpos($recordTemplate, '<tmpl_if name="can_edit">') !== false
        && strpos($recordRenderer, 'DbsRecordCapabilities::isWritable($type)') !== false
        && strpos($recordTemplate, 'icon-delete') !== false,
        'Der capability-gebundene Edit-Stift oder die bestehende Delete-Aktion fehlt.'
    );
    zoneViewReadOnlyAssertTrue(
        strpos($recordTemplate, 'class="table dbsdns-record-table"') !== false
        && strpos($recordTemplate, 'class="dark form-group-sm"') !== false
        && strpos($recordTemplate, 'table-wrapper marginTop15') !== false
        && strpos($recordRenderer, 'class="search_limit"') !== false,
        'Tabelle, Suchzeile oder Rows-Auswahl weichen unnötig von ISPConfigs Listform-Struktur ab.'
    );

    $normalizePosition = strpos($recordRenderer, '$normalizedRecords =');
    $filterPosition = strpos($recordRenderer, '$filteredRecords = $this->filterRecords');
    $sortPosition = strpos($recordRenderer, '$filteredRecords = $this->sortRecords');
    $pagingPosition = strpos($recordRenderer, '$paging = $this->buildPaging');
    $slicePosition = strpos($recordRenderer, '$visibleRecords = array_slice');
    zoneViewReadOnlyAssertTrue(
        $normalizePosition < $filterPosition
        && $filterPosition < $sortPosition
        && $sortPosition < $pagingPosition
        && $pagingPosition < $slicePosition,
        'Providerrecords werden nicht in der Reihenfolge Normalisieren, Filtern, Sortieren, Zählen und Paginieren verarbeitet.'
    );
    zoneViewReadOnlyAssertTrue(
        stripos($controller . $template . $recordRenderer . $recordTemplate, 'dns_soa') === false
        && stripos($controller . $template . $recordRenderer . $recordTemplate, 'dns_rr') === false,
        'Die eigene Zonenansicht greift auf native DNS-Tabellen zu.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Lokalisierte Labels, Meldungen, Tabs, Capabilities und editierbare SOA-Zonenansicht erfolgreich geprüft.' . PHP_EOL;
