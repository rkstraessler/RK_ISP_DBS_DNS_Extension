<?php

function mutationLockAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

$testFailure = null;

try {
    $root = dirname(__DIR__);
    $script = file_get_contents($root . '/src/dbsdns/js/dbsdns-mutation-lock.js');
    $styles = file_get_contents($root . '/src/dbsdns/css/dbsdns-ui.css');
    $zoneTemplate = file_get_contents($root . '/src/dbsdns/templates/zone_view.htm');
    $recordTemplate = file_get_contents($root . '/src/dbsdns/templates/record_edit.htm');
    $listTemplate = file_get_contents($root . '/src/dbsdns/templates/dbsdns_record_list.htm');
    $zoneView = file_get_contents($root . '/src/dbsdns/zone_view.php');
    $recordService = file_get_contents($root . '/src/dbsdns/lib/classes/DbsRecordService.inc.php');

    mutationLockAssertTrue(
        strpos($zoneTemplate, 'class="dbsdns-mutation-root"') !== false
            && strpos($zoneTemplate, 'data-dbsdns-region="records"') !== false
            && strpos($zoneTemplate, 'dbsdns-loading-overlay') < strpos($zoneTemplate, '{tmpl_var name="records_html"}')
            && strpos($zoneTemplate, 'data-dbsdns-mutation="zone-save"') !== false,
        'Recordtabelle oder SOA-Formular besitzen keinen korrekt positionierten Blocking-State.'
    );
    mutationLockAssertTrue(
        strpos($recordTemplate, 'data-dbsdns-region="record-form"') !== false
            && strpos($recordTemplate, 'data-dbsdns-mutation="record-save"') !== false
            && strpos($recordTemplate, 'dbsdns-loading-overlay') !== false,
        'Create/Edit besitzt keinen Mutation-Lock mit Loading-State.'
    );
    mutationLockAssertTrue(
        strpos($listTemplate, 'data-dbsdns-delete-action=') !== false
            && strpos($listTemplate, 'data-dbsdns-delete-csrf-id=') !== false
            && strpos($listTemplate, 'javascript: ISPConfig.confirm_action') === false
            && strpos($listTemplate, '<table class="table dbsdns-record-table">') !== false
            && strpos($listTemplate, 'search_limit') !== false
            && strpos($listTemplate, 'data-column=') !== false
            && strpos($listTemplate, 'name="Filter"') !== false,
        'Delete, Tabelle, Search, Sorting oder Pagination sind nicht im blockierbaren Bereich erhalten.'
    );
    mutationLockAssertTrue(
        strpos($script, 'pending: false') !== false
            && strpos($script, 'document.addEventListener(\'click\', handleClick, true)') !== false
            && strpos($script, 'state.pending = true') !== false
            && strpos($script, 'state.pending = false') !== false
            && strpos($script, 'stopImmediatePropagation') !== false
            && strpos($script, 'window.ISPConfig.submitForm(form.id, deleteUrl)') !== false
            && strpos($script, 'window.ISPConfig.loadContent(deleteUrl)') === false
            && strpos($script, 'setTimeout(disableControls, 0)') !== false,
        'Der zonenweite Lock sperrt Doppelclicks oder Formsubmits nicht vor dem ISPConfig-Handler.'
    );
    mutationLockAssertTrue(
        strpos($script, 'ajaxComplete') !== false
            && strpos($script, 'ajaxSend') !== false
            && strpos($script, "registerHook('onAfterContentLoad', init)") !== false
            && strpos($script, 'request.readyState !== 4') !== false
            && strpos($script, 'window.setTimeout(failSafeRelease, 75000)') !== false
            && strpos($script, "addEventListener('submit', handleLockedEvent, true)") !== false,
        'Der Mutation-Lock wird bei Erfolg, Fehler oder abgebrochenem Reload nicht zuverlässig aufgehoben.'
    );
    mutationLockAssertTrue(
        strpos($script, 'getBoundingClientRect().height') !== false
            && strpos($script, "region.style.minHeight = ''") !== false
            && strpos($styles, 'position: absolute') !== false
            && substr_count($styles, 'width: 100%') >= 1
            && strpos($script, ".remove()") === false,
        'Die alte Tabelle bleibt während des Providerreloads nicht in Breite und Höhe stehen.'
    );
    mutationLockAssertTrue(
        strpos($zoneView, "->render(\$zone['records'], \$cacheId, \$zone['origin'])") !== false
            && substr_count($recordService, "getZoneInfo(\$zone['normalized_domain'])") >= 4,
        'Die UI wird nicht aus dem bestätigten Providerzustand neu aufgebaut.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'DBS-Loading-Overlay, Layoutstabilität und zonenweiter Mutation-Lock erfolgreich geprüft.' . PHP_EOL;
