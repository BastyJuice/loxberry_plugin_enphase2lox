#!/usr/bin/php
<?php
// enphase2lox: native PHP 7.4+, no Composer, Python environment or container.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/HttpClient.php';
require_once __DIR__ . '/lib/EnvoyClient.php';
require_once __DIR__ . '/lib/MqttPublisher.php';
require_once __DIR__ . '/lib/RuntimeLog.php';

use Enphase\Config;
use Enphase\EnvoyClient;
use Enphase\HttpError;
use Enphase\MqttPublisher;
use Enphase\RuntimeLog;

$arguments = getopt('', ['config:', 'data:', 'log:', 'stop-file:', 'check', 'help']);
if (isset($arguments['help'])) {
    echo "enphase2lox: php envoy.php --config /path/options.json --data /path/data --log /path/log [--stop-file /path/stop]\n";
    echo "php envoy.php --check prüft vorhandenes PHP und mosquitto_pub.\n";
    exit(0);
}
$missing = [];
if (version_compare(PHP_VERSION, '7.4.0', '<')) { $missing[] = 'PHP >= 7.4'; }
foreach (['curl', 'json', 'SimpleXML', 'openssl'] as $extension) {
    if (!extension_loaded($extension)) { $missing[] = 'PHP-Modul ' . $extension; }
}
foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'] as $function) {
    if (!function_exists($function)) { $missing[] = $function; }
}
if (!is_executable('/usr/bin/mosquitto_pub')) { $missing[] = '/usr/bin/mosquitto_pub'; }
if ($missing) {
    fwrite(STDERR, 'Nicht verfügbar: ' . implode(', ', $missing) . ". Es wurde nichts installiert.\n");
    exit(2);
}
if (isset($arguments['check'])) {
    echo 'OK: PHP ' . PHP_VERSION . ", cURL, JSON, SimpleXML, OpenSSL und vorhandener MQTT-Client.\n";
    exit(0);
}
foreach (['config', 'log'] as $required) {
    if (empty($arguments[$required]) || !is_string($arguments[$required])) {
        fwrite(STDERR, 'Parameter --' . $required . " fehlt.\n"); exit(2);
    }
}
umask(0077);
$logDirectory = rtrim($arguments['log'], '/');
if (!is_dir($logDirectory) && !mkdir($logDirectory, 0700, true) && !is_dir($logDirectory)) {
    fwrite(STDERR, "Log-Verzeichnis konnte nicht erstellt werden.\n"); exit(2);
}
$lock = fopen($logDirectory . '/worker.lock', 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }
$pidFile = $logDirectory . '/worker.pid';
file_put_contents($pidFile, (string) getmypid());
$stopFile = $arguments['stop-file'] ?? $logDirectory . '/stop';
$stopRequested = false;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $signal) {
        pcntl_signal($signal, function () use (&$stopRequested): void { $stopRequested = true; });
    }
}
$stop = function () use (&$stopRequested, $stopFile, $arguments): bool {
    clearstatcache(true, $stopFile);
    clearstatcache(true, $arguments['config']);
    return $stopRequested || is_file($stopFile) || !is_file($arguments['config']);
};
$wait = function (float $seconds) use ($stop): void {
    $until = microtime(true) + $seconds;
    while (!$stop() && microtime(true) < $until) {
        usleep((int) min(200000, max(1000, ($until - microtime(true)) * 1000000)));
    }
};
$logger = new RuntimeLog($logDirectory);
$publisher = null;
try {
    $options = Config::load($arguments['config']);
    // All plugin instances use LoxBerry's centrally managed MQTT connection.
    // Ignore the broker selector from older options files.
    $options['MQTT_USE_LOXBERRY'] = true;
    $logger = new RuntimeLog($logDirectory, $options['LOG_REPEAT_EVERY']);
    if (!$options['ENABLED']) {
        $logger->write('INFO', 'Plugin ist deaktiviert.');
    } else {
        // The SDK initializes globals: include it at file scope, never in a method.
        require_once 'loxberry_system.php';
        require_once 'loxberry_io.php';
        $logger->write('INFO', 'enphase2lox gestartet (PHP ' . PHP_VERSION . ').');
        $logger->status(['status' => 'starting', 'last_error' => ''], true);
        $envoy = new EnvoyClient($options, dirname($arguments['config']) . '/token.txt', [$logger, 'write']);
        $initialized = false;
        $failures = 0;
        $receive = function (array $data, ?string $payload = null) use (&$publisher, &$failures, $options, $envoy, $logger, $logDirectory, $stop): void {
            if ($stop()) { return; }
            if ($publisher === null) { $publisher = new MqttPublisher($options, $logDirectory); }
            // Keep the source JSON intact, including empty objects and decimal types.
            $publisher->publish($options['MQTT_TOPIC'], $payload ?? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if ($options['USE_FREEDS'] && !$stop()) {
                $watts = EnvoyClient::freedsValue($envoy->mode(), $data);
                if ($watts !== null) {
                    $publisher->publish($options['MQTT_TOPIC_FREEDS'], (string) $watts);
                } elseif ($envoy->mode() !== 'stream_v5') {
                    $logger->repeated('FREEDS-Leistungswert fehlt in den Envoy-Messdaten.');
                }
            }
            $failures = 0;
            $logger->status(['status' => 'running', 'last_success' => date('c'), 'last_error' => '']);
        };
        while (!$stop()) {
            try {
                if (!$initialized) {
                    $info = $envoy->initialize();
                    $initialized = true;
                    $logger->write('INFO', 'Envoy-Firmware ' . $info['software'] . ', Modus ' . $envoy->mode() . '.');
                    $logger->status(['firmware' => $info['software'], 'serial' => $info['serial'], 'mode' => $envoy->mode()], true);
                }
                if ($envoy->mode() === 'stream_v5') {
                    $envoy->stream($receive, $stop);
                } else {
                    $data = $envoy->poll();
                    $receive($data, $envoy->lastPayload());
                    $wait($options['POLL_INTERVAL']);
                }
            } catch (\Throwable $error) {
                if ($stop()) { break; }
                $failures++;
                $logger->repeated($error->getMessage());
                $logger->status(['status' => 'retrying', 'last_error' => $error->getMessage()], true);
                if ($initialized && $envoy->mode() === 'livedata'
                    && $failures >= $options['LIVEDATA_RECOVERY_FAILURES']
                    && !($error instanceof HttpError && $error->status === 404)) {
                    try { $envoy->recoverStream(); }
                    catch (\Throwable $activationError) { $logger->repeated($activationError->getMessage()); }
                }
                $delay = $error instanceof HttpError && $error->status === 404
                    ? 300 : min(60, pow(2, min($failures, 6)));
                if ($options['DEBUG']) {
                    $logger->write('DEBUG', 'Fehlversuch ' . $failures . '; erneuter Versuch in ' . $delay . ' Sekunden.');
                }
                $wait($delay);
            }
        }
    }
} catch (\Throwable $error) {
    $logger->write('ERROR', $error->getMessage());
    $logger->status(['last_error' => $error->getMessage()], true);
    $runtimeExitCode = 2;
} finally {
    if ($publisher !== null) { $publisher->close(); }
    $logger->write('INFO', 'enphase2lox beendet.');
    $logger->status(['status' => 'stopped'], true);
    @unlink($pidFile);
    flock($lock, LOCK_UN);
    fclose($lock);
}
exit($runtimeExitCode ?? 0);
