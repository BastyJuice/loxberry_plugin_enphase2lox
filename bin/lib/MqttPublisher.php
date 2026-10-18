<?php
declare(strict_types=1);

namespace Enphase;

use RuntimeException;

/** Publishes through the Mosquitto client already supplied by LoxBerry. */
final class MqttPublisher
{
    private string $binary;
    private string $clientDirectory;
    private array $arguments;
    private array $environment;
    private bool $closed = false;

    public function __construct(array $options, string $logDir, ?string $binary = null, ?array $coreCredentials = null)
    {
        $this->binary = $binary ?? '/usr/bin/mosquitto_pub';
        if (!is_file($this->binary) || !is_executable($this->binary)) {
            throw new RuntimeException('Der vorhandene Mosquitto-Client mosquitto_pub ist nicht ausführbar.');
        }

        $useCore = filter_var($options['MQTT_USE_LOXBERRY'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if ($useCore) {
            if ($coreCredentials === null) {
                if (!function_exists('mqtt_connectiondetails')) {
                    throw new RuntimeException('Die MQTT-Funktionen des LoxBerry wurden nicht geladen.');
                }
                $coreCredentials = \mqtt_connectiondetails();
            }
            $credentials = $coreCredentials;
        } else {
            $credentials = [
                'brokerhost' => $options['MQTT_HOST'] ?? '',
                'brokerport' => $options['MQTT_PORT'] ?? 1883,
                'brokeruser' => $options['MQTT_USER'] ?? '',
                'brokerpass' => $options['MQTT_PASS'] ?? '',
                'tls' => false,
            ];
        }

        $host = self::lineValue($credentials['brokerhost'] ?? '', 'MQTT-Host');
        $user = self::lineValue($credentials['brokeruser'] ?? '', 'MQTT-Benutzer');
        $password = self::lineValue($credentials['brokerpass'] ?? '', 'MQTT-Passwort');
        $tls = filter_var($credentials['tls'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $portValue = $tls
            ? ($credentials['tls_brokerport'] ?? $credentials['brokerport'] ?? 8883)
            : ($credentials['brokerport'] ?? 1883);
        $portValue = self::lineValue($portValue, 'MQTT-Port');
        $port = filter_var($portValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($host === '' || $port === false) {
            throw new RuntimeException('MQTT-Host oder MQTT-Port ist ungültig.');
        }
        if ($password !== '' && $user === '') {
            throw new RuntimeException('Für ein MQTT-Passwort muss ein MQTT-Benutzer angegeben sein.');
        }

        $this->arguments = ['-h', $host, '-p', (string) $port, '-q', '0', '-s'];
        if ($tls) {
            $caFile = self::lineValue($credentials['tls_cafile'] ?? '', 'MQTT-CA-Datei');
            if ($caFile !== '' && is_file($caFile) && is_readable($caFile)) {
                array_push($this->arguments, '--cafile', $caFile);
            } else {
                array_push($this->arguments, '--capath', '/etc/ssl/certs');
            }
            if (!filter_var($credentials['tls_verify'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
                // Mosquitto still verifies the CA; this only relaxes hostname matching.
                $this->arguments[] = '--insecure';
            }
        }

        $this->clientDirectory = rtrim($logDir, '/') . '/mqtt-client-' . bin2hex(random_bytes(12));
        if (!@mkdir($this->clientDirectory, 0700)) {
            throw new RuntimeException('Das private MQTT-Client-Verzeichnis konnte nicht angelegt werden.');
        }
        if (!@chmod($this->clientDirectory, 0700)) {
            $this->close();
            throw new RuntimeException('Das private MQTT-Client-Verzeichnis konnte nicht geschützt werden.');
        }
        $configPath = $this->clientDirectory . '/mosquitto_pub';
        $config = '';
        if ($user !== '') {
            $config .= '-u ' . $user . "\n";
        }
        if ($password !== '') {
            $config .= '-P ' . $password . "\n";
        }
        $file = @fopen($configPath, 'x');
        if ($file === false || !@chmod($configPath, 0600)) {
            if (is_resource($file)) {
                fclose($file);
            }
            $this->close();
            throw new RuntimeException('Die private MQTT-Client-Konfiguration konnte nicht angelegt werden.');
        }
        $written = fwrite($file, $config);
        fclose($file);
        if ($written !== strlen($config)) {
            $this->close();
            throw new RuntimeException('Die MQTT-Client-Konfiguration konnte nicht vollständig gespeichert werden.');
        }

        $environment = getenv();
        $this->environment = is_array($environment) ? $environment : [];
        $this->environment['XDG_CONFIG_HOME'] = $this->clientDirectory;
    }

    public function publish(string $topic, string $payload): void
    {
        if ($this->closed) {
            throw new RuntimeException('Der MQTT-Publisher ist bereits geschlossen.');
        }
        self::lineValue($topic, 'MQTT-Topic', 65535);
        if ($topic === '' || strlen($topic) > 65535 || strpbrk($topic, '+#') !== false) {
            throw new RuntimeException('Das MQTT-Topic ist ungültig.');
        }

        // Argument arrays invoke the executable directly, without a command shell.
        // Mosquitto 2.0 has neither -o (custom config) nor a publisher -W timeout.
        $process = @proc_open(
            array_merge([$this->binary], $this->arguments, ['-t', $topic]),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $this->environment,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Der MQTT-Client konnte nicht gestartet werden.');
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $deadline = microtime(true) + 5.0;
        $offset = 0;
        $length = strlen($payload);
        $stderr = '';
        $exitCode = -1;
        $timedOut = false;
        try {
            while (true) {
                if (isset($pipes[0])) {
                    if ($offset < $length) {
                        $written = @fwrite($pipes[0], substr($payload, $offset, 65536));
                        if ($written === false) {
                            fclose($pipes[0]);
                            unset($pipes[0]);
                        } else {
                            $offset += $written;
                        }
                    }
                    if ($offset === $length && isset($pipes[0])) {
                        fclose($pipes[0]);
                        unset($pipes[0]);
                    }
                }
                // Drain both output pipes so a noisy client cannot block its own exit.
                @stream_get_contents($pipes[1]);
                $chunk = @stream_get_contents($pipes[2]);
                if (is_string($chunk) && strlen($stderr) < 4096) {
                    $stderr .= substr($chunk, 0, 4096 - strlen($stderr));
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    proc_terminate($process);
                    usleep(100000);
                    $status = proc_get_status($process);
                    if ($status['running']) {
                        proc_terminate($process, 9);
                    }
                    break;
                }
                usleep(10000);
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $closeCode = proc_close($process);
            if ($exitCode < 0) {
                $exitCode = $closeCode;
            }
        }

        if ($timedOut) {
            throw new RuntimeException('MQTT-Veröffentlichung nach 5 Sekunden abgebrochen.');
        }
        if ($exitCode !== 0 || $offset !== $length) {
            // Never include arbitrary client output: it could echo a credential.
            $reason = '';
            if (stripos($stderr, 'not authori') !== false || stripos($stderr, 'bad user') !== false) {
                $reason = ' Der Broker hat die Anmeldung abgelehnt.';
            } elseif (stripos($stderr, 'connection refused') !== false) {
                $reason = ' Der Broker hat die Verbindung abgelehnt.';
            } elseif (stripos($stderr, 'tls') !== false || stripos($stderr, 'certificate') !== false) {
                $reason = ' Die TLS-Verbindung konnte nicht hergestellt werden.';
            }
            throw new RuntimeException('MQTT-Veröffentlichung fehlgeschlagen (Exit-Code ' . $exitCode . ').' . $reason);
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if (isset($this->clientDirectory)) {
            @unlink($this->clientDirectory . '/mosquitto_pub');
            @rmdir($this->clientDirectory);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private static function lineValue($value, string $name, int $maxLength = 1000): string
    {
        if (!is_scalar($value) || is_bool($value)) {
            throw new RuntimeException($name . ' ist ungültig.');
        }
        $value = (string) $value;
        if (strpbrk($value, "\r\n\0") !== false || strlen($value) > $maxLength) {
            throw new RuntimeException($name . ' enthält ungültige Zeichen oder ist zu lang.');
        }
        return $value;
    }
}
