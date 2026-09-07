<?php

function releaseHygieneAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

function releaseHygieneFiles($root)
{
    $files = array();

    if(!is_dir($root)) {
        return $files;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach($iterator as $item) {
        if($item->isFile()) {
            $files[] = $item->getPathname();
        }
    }

    return $files;
}

$testFailure = null;

try {
    $root = dirname(__DIR__);
    $environmentExample = file_get_contents($root . '/config/env.example');
    $normalizedEnvironmentExample = str_replace("\r\n", "\n", trim($environmentExample));

    releaseHygieneAssertTrue(
        $normalizedEnvironmentExample === "DBS_WSDL_URL=\nDBS_USERNAME=\nDBS_PASSWORD=",
        'config/env.example enthält mehr als leere DBS-Platzhalter.'
    );

    $gitignore = file_get_contents($root . '/.gitignore');
    releaseHygieneAssertTrue(
        preg_match('/^\.env$/m', $gitignore) === 1
        && preg_match('/^\.env\.\*$/m', $gitignore) === 1
        && preg_match('/^credentials\.key$/m', $gitignore) === 1
        && preg_match('/^\.idea\/$/m', $gitignore) === 1
        && preg_match('/^dist\/$/m', $gitignore) === 1,
        'Lokale ENV- oder Credential-Key-Dateien sind nicht vom Release ausgeschlossen.'
    );

    $runtimeFiles = array_merge(
        releaseHygieneFiles($root . '/src'),
        releaseHygieneFiles($root . '/scripts'),
        releaseHygieneFiles($root . '/config'),
        releaseHygieneFiles($root . '/install'),
        releaseHygieneFiles($root . '/migration'),
        releaseHygieneFiles($root . '/packaging'),
        releaseHygieneFiles($root . '/.github')
    );

    foreach($runtimeFiles as $file) {
        $basename = basename($file);
        $contents = file_get_contents($file);

        releaseHygieneAssertTrue(
            !preg_match('/\.(?:tmp|bak|orig|rej|log)\z/i', $basename)
            && !preg_match('/(?:^\.env(?:\..+)?|\.env)\z/i', $basename)
            && strcasecmp($basename, 'credentials.key') !== 0,
            'Temporäres Diagnose- oder Secret-Artefakt im Release: ' . $file
        );
        releaseHygieneAssertTrue(
            preg_match('/[A-Za-z]:\\\\(?:Users|Repository)\\\\/i', $contents) !== 1
            && preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/', $contents) !== 1,
            'Lokaler Runtimepfad oder privater Schlüssel im Release: ' . $file
        );

        if(str_replace('\\', '/', $file) !== str_replace('\\', '/', $root . '/config/env.example')) {
            releaseHygieneAssertTrue(
                preg_match('/DBS_PASSWORD\s*=\s*[^\r\n]*\S/', $contents) !== 1,
                'Ein fest eingetragenes DBS-Passwort befindet sich im Runtimecode: ' . $file
            );
        }
    }

    $fixtureFiles = array_merge(
        releaseHygieneFiles($root . '/tests/fixtures'),
        releaseHygieneFiles($root . '/tests/browser')
    );

    foreach($fixtureFiles as $fixture) {
        $contents = file_get_contents($fixture);
        preg_match_all(
            '/\b(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:com|de|net|org|io|ch|at|eu)\b/i',
            $contents,
            $matches
        );

        foreach($matches[0] as $domain) {
            releaseHygieneAssertTrue(
                preg_match('/(?:^|\.)example\.(?:com|net|org)\z/i', $domain) === 1,
                'Eine nicht anonymisierte Domain befindet sich in einer Testfixture.'
            );
        }
    }
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Release-Hygiene für Credentials, Runtimepfade und Fixtures erfolgreich geprüft.' . PHP_EOL;
