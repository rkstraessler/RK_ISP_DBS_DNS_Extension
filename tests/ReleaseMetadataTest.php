<?php

function releaseMetadataAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

class extension_installer_base
{
    protected $extension_basedir = '/usr/local/ispconfig/extensions';
    protected $ispconfig_dir = '/usr/local/ispconfig';

    public function install() {}
    public function update() {}
    public function uninstall() {}
    public function enable() {}
    public function disable() {}
}

$testFailure = null;

try {
    $root = dirname(__DIR__);
    $version = trim(file_get_contents($root . '/VERSION'));
    $metadata = json_decode(
        file_get_contents($root . '/packaging/ispconfig/repository-metadata.json'),
        true
    );
    $installerPath = $root . '/install/installer.php';
    $installer = file_get_contents($installerPath);
    $fileListPath = $root . '/install/file.list';
    $readme = file_get_contents($root . '/README.md');
    $workflowPath = $root . '/.github/workflows/release.yml';
    $workflow = file_get_contents($workflowPath);
    require_once $installerPath;

    releaseMetadataAssertTrue(
        preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $version) === 1
        && is_array($metadata)
        && $metadata['name'] === 'dbsdns'
        && $metadata['version'] === $version
        && $metadata['ispconfig_version'] === '3.3'
        && $metadata['link'] === 'https://github.com/rkstraessler/RK_ISP_DBS_DNS_Extension',
        'Version oder ISPConfig-Repository-Metadaten sind inkonsistent.'
    );
    releaseMetadataAssertTrue(
        strpos(file_get_contents($root . '/CHANGELOG.md'), '## ' . $version . ' ') !== false
        && strpos(file_get_contents($root . '/LICENSE'), 'BSD 3-Clause License') === 0,
        'Changelog oder Lizenz fehlen für die aktuelle Version.'
    );
    releaseMetadataAssertTrue(
        strpos($installer, 'class dbsdns_installer extends extension_installer_base') !== false
        && is_subclass_of('dbsdns_installer', 'extension_installer_base')
        && strpos($installer, "runLifecycleScript('install.sh')") !== false
        && strpos($installer, "runLifecycleScript('uninstall.sh')") !== false
        && is_file($fileListPath),
        'Das native ISPConfig-Paket besitzt keinen vollständigen Lebenszyklus.'
    );

    $installerReflection = new ReflectionClass('dbsdns_installer');
    foreach(array('install', 'update', 'enable', 'disable', 'uninstall') as $method) {
        releaseMetadataAssertTrue(
            $installerReflection->hasMethod($method)
            && $installerReflection->getMethod($method)->isPublic(),
            'Öffentliche ISPConfig-Lifecycle-Methode fehlt: ' . $method
        );
    }

    $fileListEntries = array_filter(
        array_map('trim', file($fileListPath, FILE_IGNORE_NEW_LINES)),
        function($line) {
            return $line !== '' && strpos($line, '#') !== 0;
        }
    );
    releaseMetadataAssertTrue(
        $fileListEntries === array(),
        'file.list muss ein dokumentiertes No-op bleiben, solange Lifecycle-Skripte deployen.'
    );

    $workflowFiles = glob($root . '/.github/workflows/*.{yml,yaml}', GLOB_BRACE);
    releaseMetadataAssertTrue(
        is_file($workflowPath)
        && count($workflowFiles) === 1
        && strpos($workflow, 'workflow_dispatch:') !== false
        && strpos($workflow, 'release:') !== false
        && strpos($workflow, 'published') !== false
        && strpos($workflow, 'git archive --format=tar HEAD') !== false
        && strpos($workflow, 'latest_package_name="${EXTENSION_NAME}.pkg"') !== false
        && strpos($workflow, 'SHA256SUMS') !== false
        && strpos($workflow, 'gh release upload') !== false
        && strpos($workflow, 'scripts/build-release.sh') === false
        && !is_file($root . '/scripts/build-release.sh'),
        'Der zentrale GitHub-Release-Prozess ist unvollständig oder doppelt vorhanden.'
    );

    preg_match_all('/uses:\s*[^\s]+@([^\s#]+)/', $workflow, $actionReferences);
    releaseMetadataAssertTrue(
        !empty($actionReferences[1]),
        'Der Release-Workflow verwendet keine überprüfbaren Actions.'
    );
    foreach($actionReferences[1] as $actionReference) {
        releaseMetadataAssertTrue(
            preg_match('/\A[a-f0-9]{40}\z/', $actionReference) === 1,
            'GitHub Action ist nicht auf eine vollständige Commit-SHA gepinnt: ' . $actionReference
        );
    }

    releaseMetadataAssertTrue(
        is_file($root . '/migration/ispconfig-3.3.1p1/restore-manifest.sh')
        && !is_file($root . '/migration/ispconfig-3.3.1p1/restore.env'),
        'Das Laufzeitpaket darf keine als .env benannte Migrationsdatei enthalten.'
    );

    foreach(array(
        '## Features',
        '## Voraussetzungen / unterstützte ISPConfig-Version',
        '## Installation',
        '## Deployment',
        '## Abnahmetest',
        '## Release',
        '## Deinstallation',
        '## Lizenz'
    ) as $heading) {
        releaseMetadataAssertTrue(
            strpos($readme, $heading) !== false,
            'README-Abschnitt fehlt: ' . $heading
        );
    }

    releaseMetadataAssertTrue(
        is_file($root . '/docs/production-acceptance-test.md')
        && is_file($root . '/docs/ispconfig-submission.md')
        && strpos($readme, 'scripts/build-release.sh') === false
        && strpos($readme, 'Ein lokaler Paket-Build ist für Releases weder vorgesehen noch erforderlich.') !== false,
        'README beschreibt weiterhin einen manuellen Paket-Build.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Release-Metadaten und ISPConfig-Paketstruktur erfolgreich geprüft.' . PHP_EOL;
