<?php

function recordTableTooltipAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

$testFailure = null;

try {
    $root = dirname(__DIR__);
    $template = file_get_contents($root . '/src/dbsdns/templates/dbsdns_record_list.htm');
    $zoneTemplate = file_get_contents($root . '/src/dbsdns/templates/zone_view.htm');
    $styles = file_get_contents($root . '/src/dbsdns/css/dbsdns-ui.css');
    $script = file_get_contents($root . '/src/dbsdns/js/dbsdns-record-table.js');
    $browserSmoke = file_get_contents($root . '/tests/browser/record-table-smoke.html');

    recordTableTooltipAssertTrue(
        substr_count($template, 'class="dbsdns-record-value"') === 2
        && strpos($template, 'data-toggle="tooltip"') === false
        && strpos($template, 'data-placement="bottom"') === false
        && preg_match('/<td[^>]+title=/i', $template) === 0,
        'Name/Data aktivieren weiterhin konkurrierende oder bedingungslose Tooltip-Systeme.'
    );
    recordTableTooltipAssertTrue(
        strpos($template, '<colgroup>') !== false
        && strpos($template, 'dbsdns-record-col-name') !== false
        && strpos($template, 'dbsdns-record-col-data') !== false
        && substr_count($template, 'dbsdns-record-actions') >= 3,
        'Die Record- oder Action-Spalten sind nicht durchgängig stabilisiert.'
    );
    recordTableTooltipAssertTrue(
        strpos($styles, 'table-layout: fixed') !== false
        && strpos($styles, 'text-overflow: ellipsis') !== false
        && strpos($styles, 'white-space: nowrap') !== false
        && strpos($styles, 'overflow: hidden') !== false
        && strpos($styles, 'overflow-x: auto') !== false
        && strpos($styles, 'min-width: 720px') !== false,
        'Lange CNAME-, IPv6- oder TXT-Werte können das Tabellenlayout weiterhin verschieben.'
    );
    recordTableTooltipAssertTrue(
        strpos($script, 'value.scrollWidth > value.clientWidth + 1') !== false
        && strpos($script, "value.removeAttribute('title')") !== false
        && strpos($script, "value.setAttribute('title', value.textContent || '')") !== false,
        'Kurze Werte werden nicht tooltipfrei gehalten oder lange Werte werden nicht anhand realer Kürzung erkannt.'
    );
    recordTableTooltipAssertTrue(
        strpos($script, 'addEventListener') !== false
        && strpos($script, "addEventListener('mouseenter'") === false
        && strpos($script, "addEventListener('mouseleave'") === false
        && strpos($script, 'ajaxComplete(init)') !== false
        && strpos($script, "registerHook('onAfterContentLoad', init)") !== false
        && strpos($script, 'if(!window.DbsDnsRecordTable)') !== false,
        'Hover oder AJAX-Reload kann weiterhin doppelte zeilenweise Handler beziehungsweise Geister-Tooltips erzeugen.'
    );
    recordTableTooltipAssertTrue(
        strpos($zoneTemplate, 'dbsdns/js/dbsdns-record-table.js') !== false
        && strpos($template, 'search_limit') !== false
        && strpos($template, 'data-column=') !== false
        && strpos($template, 'paging') !== false
        && strpos($template, 'data-dbsdns-delete-action') !== false,
        'Tooltip-Fix hat Search, Sorting, Pagination oder Recordaktionen verändert.'
    );
    recordTableTooltipAssertTrue(
        strpos($browserSmoke, 'data-kind="short"') !== false
        && strpos($browserSmoke, 'data-kind="cname"') !== false
        && strpos($browserSmoke, 'data-kind="ipv6"') !== false
        && strpos($browserSmoke, 'data-kind="txt"') !== false
        && strpos($browserSmoke, 'data-kind="mx"') !== false
        && strpos($browserSmoke, 'id="smoke-search"') !== false
        && strpos($browserSmoke, 'id="smoke-sort"') !== false
        && strpos($browserSmoke, 'id="smoke-page"') !== false
        && strpos($browserSmoke, 'id="smoke-reload"') !== false,
        'Das reproduzierbare Browser-Smoke-Fixture deckt die geforderten Record- und Reload-Szenarien nicht ab.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Stabile Recordtabelle und Overflow-abhängige Tooltips erfolgreich geprüft.' . PHP_EOL;
