<?php

/**
 * Laufzeit-Hilfen für die DBS-DNS-Oberfläche.
 */
class DbsRuntime
{
    private static $requestGuardInstalled = false;
    private static $requestGuardHandled = false;
    private static $requestGuardBaseBufferLevel = 0;
    private static $requestGuardOperation = 'Laden der DBS-DNS-Seite';

    public static function moduleRoot()
    {
        return dirname(__DIR__, 2);
    }

    public static function templatePath($filename)
    {
        return self::moduleRoot() . '/templates/' . ltrim($filename, '/\\');
    }

    public static function languagePath($language, $suffix)
    {
        if(!is_string($language) || !preg_match('/\\A[a-z]{2}\\z/i', $language)) {
            $language = 'en';
        }

        return self::moduleRoot() . '/lib/lang/' . strtolower($language) . '_' . $suffix . '.lng';
    }

    /**
     * Führt eine ISPConfig-Dateioperation im Modulverzeichnis aus und stellt
     * danach das ursprüngliche Arbeitsverzeichnis wieder her.
     */
    public static function inModuleDirectory($callback)
    {
        if(!is_callable($callback)) {
            throw new InvalidArgumentException('Ungültiger Laufzeit-Callback.');
        }

        $previousDirectory = getcwd();
        if($previousDirectory === false || !chdir(self::moduleRoot())) {
            throw new RuntimeException('Modulverzeichnis konnte nicht geöffnet werden.');
        }

        try {
            return call_user_func($callback);
        } finally {
            chdir($previousDirectory);
        }
    }

    /**
     * Verhindert Trace-Ausgaben und leere Antworten bei ungefangenen Fehlern.
     */
    public static function installRequestGuard($operation)
    {
        if(self::$requestGuardInstalled) {
            return;
        }

        self::$requestGuardInstalled = true;
        self::$requestGuardOperation = is_string($operation) && $operation !== ''
            ? $operation
            : self::$requestGuardOperation;
        self::$requestGuardBaseBufferLevel = ob_get_level();

        @ini_set('display_errors', '0');
        ob_start();

        set_error_handler(function($severity, $message, $file, $line) {
            if((error_reporting() & $severity) === 0 || !DbsRuntime::isModuleFile($file)) {
                return false;
            }

            throw new ErrorException(
                'Unerwarteter PHP-Fehler.',
                0,
                (int)$severity,
                (string)$file,
                (int)$line
            );
        });

        set_exception_handler(function($exception) {
            DbsRuntime::renderUnexpected($exception, DbsRuntime::$requestGuardOperation);
        });

        register_shutdown_function(function() {
            DbsRuntime::handleShutdownError();
        });
    }

    /**
     * Protokolliert nur Klasse, Moduloperation und sichere relative Position.
     */
    public static function logUnexpected($app, $exception, $operation)
    {
        $exceptionClass = $exception instanceof Throwable ? get_class($exception) : 'Unbekannt';
        $location = self::relativeLocation($exception);
        $line = $exception instanceof Throwable ? (int)$exception->getLine() : 0;
        $message = 'DBS DNS unerwarteter Fehler bei ' . $operation . ' (' . $exceptionClass . ')';

        if($location !== '') {
            $message .= ' in ' . $location . ($line > 0 ? ':' . $line : '');
        }

        $message .= '.';

        if(is_object($app) && method_exists($app, 'log')) {
            $logLevel = defined('LOGLEVEL_ERROR') ? LOGLEVEL_ERROR : 0;
            $app->log($message, $logLevel);
            return;
        }

        // Fallback für frühe Bootstrap-Fehler.
        error_log($message);
    }

    private static function renderUnexpected($exception, $operation)
    {
        if(self::$requestGuardHandled) {
            exit;
        }

        self::$requestGuardHandled = true;
        global $app;

        try {
            self::logUnexpected(is_object($app) ? $app : null, $exception, $operation);
        } catch (Throwable $loggingException) {
            error_log('DBS DNS unerwarteter Fehler konnte nicht protokolliert werden.');
        }

        self::clearGuardOutput();
        http_response_code(500);

        $message = 'Die DBS-DNS-Seite konnte nicht geladen werden.';

        if(is_object($app) && method_exists($app, 'error')) {
            try {
                $app->error($message);
                exit;
            } catch (Throwable $renderingException) {
                // Der Fallback unten verhindert eine leere AJAX-Antwort.
            }
        }

        echo '<div class="alert alert-danger" role="alert">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</div>';
        exit;
    }

    private static function handleShutdownError()
    {
        if(self::$requestGuardHandled) {
            return;
        }

        $lastError = error_get_last();
        if(!is_array($lastError) || !isset($lastError['type'])) {
            return;
        }

        $fatalTypes = array(
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_RECOVERABLE_ERROR,
            E_USER_ERROR
        );
        if(!in_array($lastError['type'], $fatalTypes, true)) {
            return;
        }

        $exception = new ErrorException(
            'Unerwarteter PHP-Fehler.',
            0,
            $lastError['type'],
            isset($lastError['file']) ? $lastError['file'] : '',
            isset($lastError['line']) ? (int)$lastError['line'] : 0
        );
        self::renderUnexpected($exception, self::$requestGuardOperation);
    }

    private static function clearGuardOutput()
    {
        while(ob_get_level() > self::$requestGuardBaseBufferLevel) {
            ob_end_clean();
        }
    }

    private static function relativeLocation($exception)
    {
        if(!$exception instanceof Throwable) {
            return '';
        }

        $file = str_replace('\\', '/', (string)$exception->getFile());
        $moduleRoot = str_replace('\\', '/', rtrim(self::moduleRoot(), '/\\'));
        $modulePrefix = strtolower($moduleRoot . '/');

        if(strtolower(substr($file, 0, strlen($modulePrefix))) === $modulePrefix) {
            return 'dbsdns/' . ltrim(substr($file, strlen($modulePrefix)), '/');
        }

        $basename = basename($file);
        return $basename !== '' ? 'ispconfig/' . $basename : '';
    }

    private static function isModuleFile($file)
    {
        $normalizedFile = str_replace('\\', '/', (string)$file);
        $moduleRoot = str_replace('\\', '/', rtrim(self::moduleRoot(), '/\\'));
        $modulePrefix = strtolower($moduleRoot . '/');

        return strtolower(substr($normalizedFile, 0, strlen($modulePrefix))) === $modulePrefix;
    }
}
