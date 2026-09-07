<?php

function dbsRuntimeAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

function dbsRuntimeRemoveTree($path)
{
    if(!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach($iterator as $item) {
        if($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($path);
}

function dbsRuntimeRunGuard($runner, $workingDirectory)
{
    $process = proc_open(
        array(PHP_BINARY, $runner),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        $workingDirectory,
        null,
        array('bypass_shell' => true)
    );

    if(!is_resource($process)) {
        throw new RuntimeException('Der Laufzeit-Guard-Testprozess konnte nicht gestartet werden.');
    }

    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return array('exit_code' => proc_close($process), 'output' => $output);
}

class DbsRuntimeDeferredTemplateFake
{
    private $filename;

    public function newTemplate($filename)
    {
        $this->filename = $filename;
    }

    public function render()
    {
        return file_get_contents($this->filename);
    }
}

$testFailure = null;
$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'dbsdns-runtime-' . bin2hex(random_bytes(8));

try {
    $root = dirname(__DIR__);
    require_once $root . '/src/dbsdns/lib/classes/DbsRuntime.inc.php';

    $originalDirectory = getcwd();
    $insideDirectory = DbsRuntime::inModuleDirectory(function() {
        return getcwd();
    });
    dbsRuntimeAssertTrue(
        $insideDirectory === DbsRuntime::moduleRoot(),
        'Die lokale Dateioperation verwendet nicht das Modulverzeichnis.'
    );
    dbsRuntimeAssertTrue(
        getcwd() === $originalDirectory,
        'Das ursprüngliche Arbeitsverzeichnis wurde nicht wiederhergestellt.'
    );

    $deferredTemplate = new DbsRuntimeDeferredTemplateFake();
    $deferredTemplate->newTemplate(DbsRuntime::templatePath('settings.htm'));
    $deferredOriginalDirectory = getcwd();
    dbsRuntimeAssertTrue(
        chdir(sys_get_temp_dir()),
        'Das fremde Arbeitsverzeichnis konnte nicht gesetzt werden.'
    );
    try {
        $deferredContents = $deferredTemplate->render();
    } finally {
        chdir($deferredOriginalDirectory);
    }
    dbsRuntimeAssertTrue(
        is_string($deferredContents) && strpos($deferredContents, 'settings_title_txt') !== false,
        'Ein später Template-Lesevorgang verliert den absoluten Modulpfad.'
    );
    dbsRuntimeAssertTrue(
        DbsRuntime::templatePath('settings.htm') === $root . '/src/dbsdns/templates/settings.htm'
        && DbsRuntime::languagePath('de', 'dbsdns') === $root . '/src/dbsdns/lib/lang/de_dbsdns.lng',
        'Die lokalen Template-/Sprachpfade sind nicht absolut.'
    );

    $entrypoints = array(
        'zone_list.php',
        'zone_view.php',
        'record_edit.php',
        'record_delete.php',
        'zone_settings.php',
        'assignment_list.php',
        'assignment_edit.php',
        'settings.php',
        'domain_sync.php'
    );
    foreach($entrypoints as $entrypoint) {
        $source = file_get_contents($root . '/src/dbsdns/' . $entrypoint);
        dbsRuntimeAssertTrue(
            strpos($source, '$dbsdnsModuleRoot = __DIR__;') !== false
            && strpos($source, 'dirname($dbsdnsModuleRoot, 2)') !== false,
            'Der Einstiegspunkt ' . $entrypoint . ' verankert seine Pfade nicht an __DIR__. '
        );

        $tokens = token_get_all($source);
        foreach($tokens as $tokenIndex => $token) {
            if(!is_array($token) || !in_array($token[0], array(
                T_REQUIRE,
                T_REQUIRE_ONCE,
                T_INCLUDE,
                T_INCLUDE_ONCE
            ), true)) {
                continue;
            }

            $nextIndex = $tokenIndex + 1;
            while(
                isset($tokens[$nextIndex])
                && is_array($tokens[$nextIndex])
                && in_array($tokens[$nextIndex][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)
            ) {
                $nextIndex++;
            }

            if(!isset($tokens[$nextIndex]) || !is_array($tokens[$nextIndex])) {
                continue;
            }

            if($tokens[$nextIndex][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $literal = trim($tokens[$nextIndex][1], "'\"");
            $normalizedLiteral = str_replace('\\', '/', $literal);
            dbsRuntimeAssertTrue(
                !preg_match('#^(?:\.\.?/|lib/|form/|list/|templates/)#i', $normalizedLiteral),
                'Relativer Dateipfad im Einstiegspunkt ' . $entrypoint . ': ' . $literal
            );
        }
    }

    $zoneViewSource = file_get_contents($root . '/src/dbsdns/zone_view.php');
    $rendererSource = file_get_contents($root . '/src/dbsdns/lib/classes/DbsRecordListRenderer.inc.php');
    dbsRuntimeAssertTrue(
        strpos($zoneViewSource, "newTemplate(DbsRuntime::templatePath('zone_view.htm'))") !== false
        && strpos($rendererSource, "newTemplate(DbsRuntime::templatePath('dbsdns_record_list.htm'))") !== false,
        'Lokale Templates werden nicht mit einem dauerhaft gültigen absoluten Pfad geladen.'
    );

    $moduleRoot = $testRoot . DIRECTORY_SEPARATOR . 'dbsdns';
    $classRoot = $moduleRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'classes';
    $runner = $moduleRoot . DIRECTORY_SEPARATOR . 'guard.php';
    dbsRuntimeAssertTrue(
        mkdir($classRoot, 0777, true)
        && copy(
            $root . '/src/dbsdns/lib/classes/DbsRuntime.inc.php',
            $classRoot . DIRECTORY_SEPARATOR . 'DbsRuntime.inc.php'
        ),
        'Die Guard-Testumgebung konnte nicht angelegt werden.'
    );
    file_put_contents(
        $runner,
        <<<'PHP'
<?php

require __DIR__ . '/lib/classes/DbsRuntime.inc.php';

class DbsRuntimeGuardFakeApp
{
    public function log($message, $level)
    {
    }

    public function error($message)
    {
        echo '<div class="alert" data-status="' . http_response_code() . '">' . $message . '</div>';
    }
}

$app = new DbsRuntimeGuardFakeApp();
error_reporting(E_ALL);
DbsRuntime::installRequestGuard('Guard-Test');
echo 'PRIVATE_PARTIAL_OUTPUT';
trigger_error('credential=secret-value', E_USER_WARNING);
echo 'CONTINUED';
PHP
    );

    $guardResult = dbsRuntimeRunGuard($runner, $moduleRoot);
    dbsRuntimeAssertTrue(
        $guardResult['exit_code'] === 0
        && strpos($guardResult['output'], 'Die DBS-DNS-Seite konnte nicht geladen werden.') !== false
        && strpos($guardResult['output'], 'secret-value') === false
        && strpos($guardResult['output'], 'data-status="500"') !== false
        && strpos($guardResult['output'], 'PRIVATE_PARTIAL_OUTPUT') === false
        && strpos($guardResult['output'], 'CONTINUED') === false,
        'Der zentrale Laufzeit-Guard liefert keine sichere generische Antwort.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
} finally {
    dbsRuntimeRemoveTree($testRoot);
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'DBS-DNS-Laufzeitpfade und Fehler-Guard erfolgreich geprüft.' . PHP_EOL;
