<?php
declare(strict_types=1);

require_once 'loxberry_system.php';
require_once 'loxberry_web.php';
require_once $lbpbindir . '/lib/Config.php';

use Enphase\Config;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('enphase2lox_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (!isset($_SESSION['enphase2lox_csrf'])) {
    $_SESSION['enphase2lox_csrf'] = bin2hex(random_bytes(32));
}

function html($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function postedString(string $name): string
{
    $value = $_POST[$name] ?? '';
    if (!is_string($value)) {
        throw new InvalidArgumentException('Ungültiger Formularwert.');
    }
    return $value;
}

function serviceAction(string $action): string
{
    global $lbpbindir;
    if (!in_array($action, ['start', 'stop', 'restart'], true)) {
        throw new InvalidArgumentException('Ungültige Dienstaktion.');
    }
    @set_time_limit(120);
    $process = proc_open(['/bin/bash', $lbpbindir . '/service.sh', $action], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Dienststeuerung konnte nicht gestartet werden.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException(trim($errors ?: $output) ?: 'Dienstaktion fehlgeschlagen. Bitte das Protokoll prüfen.');
    }
    return trim($output);
}

function workerRunning(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }
    $lock = @fopen($path, 'r');
    if ($lock === false) {
        return false;
    }
    $free = flock($lock, LOCK_EX | LOCK_NB);
    if ($free) {
        flock($lock, LOCK_UN);
    }
    fclose($lock);
    return !$free;
}

function readLogTail(string $path): string
{
    $stream = @fopen($path, 'rb');
    if ($stream === false) {
        return 'Noch keine Protokolleinträge vorhanden.';
    }
    $size = fstat($stream)['size'];
    $offset = max(0, $size - 16384);
    fseek($stream, $offset);
    $text = stream_get_contents($stream, 16384);
    fclose($stream);
    $lines = explode("\n", $text === false ? '' : $text);
    if ($offset > 0) {
        array_shift($lines);
    }
    return implode("\n", array_slice($lines, -50));
}

$configPath = $lbpconfigdir . '/options.json';
$options = Config::defaults();
$message = '';
$error = '';
try {
    $options = Config::load($configPath);
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!hash_equals($_SESSION['enphase2lox_csrf'], postedString('csrf'))) {
            throw new RuntimeException('Die Sitzung ist abgelaufen. Bitte die Seite neu laden und erneut speichern.');
        }
        $action = postedString('action');
        if ($action === 'save') {
            $updated = $options;
            foreach (['ENABLED', 'USE_FREEDS', 'ENVOY_USE_HTTPS',
                      'ENVOY_VERIFY_TLS', 'BATTERY_INSTALLED', 'DEBUG'] as $key) {
                $updated[$key] = postedString($key) === '1';
            }
            $updated['MQTT_USE_LOXBERRY'] = true;
            foreach (['ENVOY_HOST', 'ENPHASE_USER', 'MQTT_TOPIC', 'MQTT_TOPIC_FREEDS', 'POLL_INTERVAL',
                      'LOG_REPEAT_EVERY', 'LIVEDATA_RECOVERY_FAILURES', 'LIVEDATA_ACTIVATION_COOLDOWN'] as $key) {
                $updated[$key] = trim(postedString($key));
            }
            foreach (['ENPHASE_PASS'] as $key) {
                $password = postedString($key);
                $clear = postedString('clear_' . $key) === '1';
                if ($password !== '' || $clear) {
                    $updated[$key] = $clear ? '' : $password;
                }
            }
            $updated = Config::validate($updated);
            Config::save($configPath, $updated);
            $options = $updated;
            $error = '';
            $message = 'Einstellungen gespeichert.';
            serviceAction($options['ENABLED'] ? 'restart' : 'stop');
            $message .= $options['ENABLED'] ? ' Der Dienst wurde gestartet.' : ' Der Dienst ist deaktiviert.';
        } elseif (in_array($action, ['start', 'stop', 'restart'], true)) {
            $state = serviceAction($action);
            $message = $state === 'disabled' ? 'Bitte den Dienst in den Einstellungen aktivieren und speichern.'
                : ($action === 'stop' ? 'Dienst gestoppt.' : 'Dienst gestartet.');
        } else {
            throw new InvalidArgumentException('Unbekannte Aktion.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$running = workerRunning($lbplogdir . '/worker.lock');
$status = [];
if (is_file($lbplogdir . '/status.json') && filesize($lbplogdir . '/status.json') < 65536) {
    $decoded = json_decode((string) @file_get_contents($lbplogdir . '/status.json'), true);
    if (is_array($decoded)) {
        $status = $decoded;
    }
}
$logText = readLogTail($lbplogdir . '/envoy.log');
$secrets = [$options['ENPHASE_PASS'], $options['MQTT_PASS']];
if (is_file($lbpconfigdir . '/token.txt') && filesize($lbpconfigdir . '/token.txt') < 16384) {
    $secrets[] = trim((string) @file_get_contents($lbpconfigdir . '/token.txt'));
}
foreach ($secrets as $secret) {
    if ($secret !== '') {
        $logText = str_replace($secret, '[redacted]', $logText);
        foreach ($status as $key => $value) {
            if (is_string($value)) {
                $status[$key] = str_replace($secret, '[redacted]', $value);
            }
        }
    }
}

// Let LoxBerry render the navigation and all standard controls in its active theme.
$form = $_GET['form'] ?? 'main';
if (!is_string($form) || !in_array($form, ['main', 'logs'], true)) {
    $form = 'main';
}
$navbar = [
    10 => ['Name' => 'Einstellungen', 'URL' => 'index.php?form=main', 'active' => $form === 'main'],
    20 => ['Name' => 'Protokoll', 'URL' => 'index.php?form=logs', 'active' => $form === 'logs'],
];
LBWeb::lbheader('enphase2lox', 'https://github.com/BastyJuice/enphase-envoy-mqtt', '', true);
?>
<style>
.enphase2lox { max-width: 1100px; margin: auto; }
.enphase2lox .lb-card { margin-bottom: 1rem; }
.enphase2lox .lb-input { max-width: 460px; }
.enphase2lox .lb-table td { overflow-wrap: anywhere; }
.enphase2lox pre { white-space: pre-wrap; overflow-wrap: anywhere; max-height: 28rem; overflow: auto; }
</style>
<div class="lb-content enphase2lox">
    <?php if ($message !== ''): ?><p class="lb-callout" role="status"><?= html($message) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="lb-callout lb-callout-warning" role="alert"><?= html($error) ?></p><?php endif; ?>

    <?php if ($form === 'logs'): ?>
    <section class="lb-card">
        <h2 class="lb-section-title">Protokoll</h2>
        <p>Letzte 50 Zeilen. Status und Protokoll liegen im LoxBerry-RAM-Verzeichnis und werden nach einem Systemstart neu aufgebaut.</p>
        <a class="lb-btn" href="index.php?form=logs">Aktualisieren</a>
        <pre><?= html($logText) ?></pre>
    </section>
    <?php else: ?>
    <p>Enphase-Envoy-Daten über MQTT an LoxBerry senden.</p>
    <section class="lb-card">
        <h2 class="lb-section-title">Dienst: <?= $running ? 'läuft' : 'gestoppt' ?></h2>
        <p><?= $options['ENABLED'] ? 'Automatischer Start beim LoxBerry-Systemstart ist aktiviert.' : 'Automatischer Start ist deaktiviert.' ?></p>
        <?php if ($status): ?>
        <table class="lb-table">
            <tbody>
            <?php foreach (['status' => 'Status', 'mode' => 'Betriebsart', 'firmware' => 'Firmware', 'last_success' => 'Letzte Übertragung', 'last_error' => 'Letzter Fehler'] as $key => $label): ?>
            <?php if (isset($status[$key]) && is_scalar($status[$key])): ?>
            <tr><th scope="row"><?= html($label) ?></th><td><?= html($status[$key]) ?></td></tr>
            <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <form method="post" action="index.php">
            <input type="hidden" name="csrf" value="<?= html($_SESSION['enphase2lox_csrf']) ?>">
            <button class="lb-btn" type="submit" name="action" value="start" <?= !$options['ENABLED'] || $running ? 'disabled' : '' ?>>Starten</button>
            <button class="lb-btn" type="submit" name="action" value="stop" <?= !$running ? 'disabled' : '' ?>>Stoppen</button>
            <button class="lb-btn" type="submit" name="action" value="restart" <?= !$options['ENABLED'] ? 'disabled' : '' ?>>Neu starten</button>
            <a class="lb-btn" href="index.php">Aktualisieren</a>
        </form>
    </section>

    <form method="post" action="index.php" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= html($_SESSION['enphase2lox_csrf']) ?>">
        <input type="hidden" name="action" value="save">
        <section class="lb-card">
            <h2 class="lb-section-title">Envoy</h2>
            <div class="lb-form-row">
                <span class="lb-form-label">Dienst</span>
                <div class="lb-form-field"><label for="enabled"><input type="checkbox" id="enabled" name="ENABLED" value="1" <?= $options['ENABLED'] ? 'checked' : '' ?>>Aktivieren und beim Systemstart automatisch starten</label></div>
            </div>
            <div class="lb-form-row">
                <label class="lb-form-label" for="envoy-host">Envoy-Host</label>
                <div class="lb-form-field"><input class="lb-input" type="text" id="envoy-host" name="ENVOY_HOST" value="<?= html($options['ENVOY_HOST']) ?>" placeholder="192.168.1.50" aria-describedby="envoy-host-help"></div>
                <p class="lb-form-help" id="envoy-host-help">IP-Adresse oder Hostname, ohne http:// und Pfad.</p>
            </div>
            <div class="lb-form-row">
                <label class="lb-form-label" for="enphase-user">Enphase-Benutzername</label>
                <div class="lb-form-field"><input class="lb-input" type="text" id="enphase-user" name="ENPHASE_USER" value="<?= html($options['ENPHASE_USER']) ?>" autocomplete="username"></div>
            </div>
            <div class="lb-form-row">
                <label class="lb-form-label" for="enphase-pass">Enphase-Passwort</label>
                <div class="lb-form-field">
                    <input class="lb-input" type="password" id="enphase-pass" name="ENPHASE_PASS" value="" autocomplete="new-password" placeholder="<?= $options['ENPHASE_PASS'] !== '' ? 'Gespeichert – leer lassen zum Beibehalten' : 'Noch nicht gespeichert' ?>">
                    <label for="clear-enphase-pass"><input type="checkbox" id="clear-enphase-pass" name="clear_ENPHASE_PASS" value="1">Gespeichertes Passwort löschen</label>
                </div>
            </div>
            <div class="lb-form-row">
                <label class="lb-form-label" for="poll-interval">Abfrageintervall (Sekunden)</label>
                <div class="lb-form-field"><input class="lb-input" type="number" id="poll-interval" name="POLL_INTERVAL" min="0.2" max="3600" step="0.1" value="<?= html($options['POLL_INTERVAL']) ?>" aria-describedby="poll-interval-help"></div>
                <p class="lb-form-help" id="poll-interval-help">Standard: 0,6 Sekunden. Bei Firmware 5 wird der laufende Datenstrom verwendet.</p>
            </div>
            <div class="lb-form-row">
                <span class="lb-form-label">Lokale Verbindung</span>
                <div class="lb-form-field"><label for="envoy-use-https"><input type="checkbox" id="envoy-use-https" name="ENVOY_USE_HTTPS" value="1" <?= $options['ENVOY_USE_HTTPS'] ? 'checked' : '' ?>>HTTPS für lokale Envoy-Abfragen verwenden</label></div>
            </div>
            <div class="lb-form-row">
                <span class="lb-form-label">TLS-Zertifikat</span>
                <div class="lb-form-field"><label for="envoy-verify-tls"><input type="checkbox" id="envoy-verify-tls" name="ENVOY_VERIFY_TLS" value="1" <?= $options['ENVOY_VERIFY_TLS'] ? 'checked' : '' ?>>Zertifikat des Envoy prüfen</label></div>
                <p class="lb-form-help">Lokale Envoy-Zertifikate sind häufig selbst signiert. Die Enphase-Cloud-Verbindung prüft ihr Zertifikat immer.</p>
            </div>
            <div class="lb-form-row">
                <span class="lb-form-label">Batterie</span>
                <div class="lb-form-field"><label for="battery-installed"><input type="checkbox" id="battery-installed" name="BATTERY_INSTALLED" value="1" <?= $options['BATTERY_INSTALLED'] ? 'checked' : '' ?>>Batterie vorhanden (Firmware 7/8)</label></div>
            </div>
        </section>

        <section class="lb-card">
            <h2 class="lb-section-title">MQTT</h2>
            <p>Die MQTT-Verbindung wird aus dem LoxBerry-MQTT-Widget übernommen. Hier legst du die Topics fest. Topics werden kleingeschrieben.</p>
            <div class="lb-form-row">
                <label class="lb-form-label" for="mqtt-topic">Daten-Topic</label>
                <div class="lb-form-field"><input class="lb-input" type="text" id="mqtt-topic" name="MQTT_TOPIC" value="<?= html($options['MQTT_TOPIC']) ?>"></div>
            </div>
            <div class="lb-form-row">
                <label class="lb-form-label" for="mqtt-topic-freeds">FreeDS-Topic</label>
                <div class="lb-form-field"><input class="lb-input" type="text" id="mqtt-topic-freeds" name="MQTT_TOPIC_FREEDS" value="<?= html($options['MQTT_TOPIC_FREEDS']) ?>"></div>
            </div>
            <div class="lb-form-row">
                <span class="lb-form-label">FreeDS</span>
                <div class="lb-form-field"><label for="use-freeds"><input type="checkbox" id="use-freeds" name="USE_FREEDS" value="1" <?= $options['USE_FREEDS'] ? 'checked' : '' ?>>Netzleistung zusätzlich für FreeDS veröffentlichen</label></div>
            </div>
        </section>
        <details class="lb-collapsible">
            <summary>Erweiterte Einstellungen und Diagnose</summary>
            <div class="lb-collapsible-content">
                <div class="lb-form-row">
                    <span class="lb-form-label">Diagnose</span>
                    <div class="lb-form-field"><label for="debug"><input type="checkbox" id="debug" name="DEBUG" value="1" <?= $options['DEBUG'] ? 'checked' : '' ?>>Ausführliches Diagnoseprotokoll</label></div>
                </div>
                <div class="lb-form-row">
                    <label class="lb-form-label" for="log-repeat-every">Gleichen Fehler nach N Wiederholungen protokollieren</label>
                    <div class="lb-form-field"><input class="lb-input" type="number" id="log-repeat-every" name="LOG_REPEAT_EVERY" min="1" max="1000" step="1" value="<?= html($options['LOG_REPEAT_EVERY']) ?>"></div>
                </div>
                <div class="lb-form-row">
                    <label class="lb-form-label" for="livedata-recovery-failures">Live-Daten nach N Fehlern erneut aktivieren</label>
                    <div class="lb-form-field"><input class="lb-input" type="number" id="livedata-recovery-failures" name="LIVEDATA_RECOVERY_FAILURES" min="1" max="100" step="1" value="<?= html($options['LIVEDATA_RECOVERY_FAILURES']) ?>"></div>
                </div>
                <div class="lb-form-row">
                    <label class="lb-form-label" for="livedata-activation-cooldown">Mindestabstand der Aktivierung (Sekunden)</label>
                    <div class="lb-form-field"><input class="lb-input" type="number" id="livedata-activation-cooldown" name="LIVEDATA_ACTIVATION_COOLDOWN" min="10" max="86400" step="1" value="<?= html($options['LIVEDATA_ACTIVATION_COOLDOWN']) ?>"></div>
                </div>
            </div>
        </details>
        <div class="lb-actions"><button class="lb-btn lb-btn-primary" type="submit">Speichern und anwenden</button></div>
        <p>Das Speichern startet einen aktivierten Dienst neu. Leere Passwortfelder behalten gespeicherte Passwörter bei.</p>
    </form>
    <?php endif; ?>
</div>
<?php LBWeb::lbfooter(); ?>
