<?php
declare(strict_types=1);

namespace Enphase;

use InvalidArgumentException;
use RuntimeException;

final class Config
{
    public static function defaults(): array
    {
        return [
            'ENABLED' => false,
            'MQTT_USE_LOXBERRY' => true,
            'MQTT_HOST' => '',
            'MQTT_USER' => '',
            'MQTT_PASS' => '',
            'MQTT_PORT' => 1883,
            'MQTT_TOPIC' => 'envoy/data',
            'MQTT_TOPIC_FREEDS' => 'envoy/inverter/gridwatts',
            'ENVOY_HOST' => '',
            'ENVOY_SERIAL' => '',
            'ENPHASE_USER' => '',
            'ENPHASE_PASS' => '',
            'USE_FREEDS' => true,
            'ENVOY_USE_HTTPS' => false,
            'ENVOY_VERIFY_TLS' => false,
            'BATTERY_INSTALLED' => false,
            'DEBUG' => false,
            'POLL_INTERVAL' => 0.6,
            'LOG_REPEAT_EVERY' => 10,
            'LIVEDATA_RECOVERY_FAILURES' => 5,
            'LIVEDATA_ACTIVATION_COOLDOWN' => 300,
        ];
    }

    public static function load(string $path): array
    {
        if (!is_file($path)) {
            return self::defaults();
        }
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Konfiguration konnte nicht gelesen werden.');
        }
        try {
            $values = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new InvalidArgumentException('options.json enthält kein gültiges JSON.', 0, $error);
        }
        if (!is_object($values)) {
            throw new InvalidArgumentException('options.json muss ein JSON-Objekt enthalten.');
        }
        return self::validate((array) $values);
    }

    public static function validate(array $values): array
    {
        $result = array_replace(self::defaults(), array_intersect_key($values, self::defaults()));
        foreach (['ENABLED', 'MQTT_USE_LOXBERRY', 'USE_FREEDS', 'ENVOY_USE_HTTPS',
                  'ENVOY_VERIFY_TLS', 'BATTERY_INSTALLED', 'DEBUG'] as $key) {
            if (!is_bool($result[$key])) {
                throw new InvalidArgumentException("$key muss true oder false sein.");
            }
        }
        foreach (['MQTT_HOST', 'MQTT_USER', 'MQTT_PASS', 'MQTT_TOPIC', 'MQTT_TOPIC_FREEDS',
                  'ENVOY_HOST', 'ENVOY_SERIAL', 'ENPHASE_USER', 'ENPHASE_PASS'] as $key) {
            if (!is_string($result[$key]) || strlen($result[$key]) > 4096 || strpos($result[$key], "\0") !== false) {
                throw new InvalidArgumentException("$key muss eine gültige Zeichenfolge sein (maximal 4096 Bytes).");
            }
        }
        foreach (['MQTT_HOST', 'ENVOY_HOST'] as $key) {
            $result[$key] = trim($result[$key]);
            if (strlen($result[$key]) > 255 || preg_match('~[\s/?#@\\\\]~', $result[$key])) {
                throw new InvalidArgumentException("$key muss ein Hostname oder eine IP-Adresse ohne Protokoll oder Pfad sein.");
            }
        }
        foreach (['MQTT_USER', 'MQTT_PASS'] as $key) {
            if (strpbrk($result[$key], "\r\n") !== false) {
                throw new InvalidArgumentException("$key darf keine Zeilenumbrüche enthalten.");
            }
        }
        foreach (['MQTT_PORT' => [1, 65535], 'LOG_REPEAT_EVERY' => [1, 1000],
                  'LIVEDATA_RECOVERY_FAILURES' => [1, 100], 'LIVEDATA_ACTIVATION_COOLDOWN' => [10, 86400]] as $key => $range) {
            $value = $result[$key];
            $number = (is_int($value) || is_string($value))
                ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $range[0], 'max_range' => $range[1]]])
                : false;
            if ($number === false) {
                throw new InvalidArgumentException("$key muss zwischen {$range[0]} und {$range[1]} liegen.");
            }
            $result[$key] = $number;
        }
        if (!is_numeric($result['POLL_INTERVAL']) || !is_finite((float) $result['POLL_INTERVAL'])
            || (float) $result['POLL_INTERVAL'] < 0.2 || (float) $result['POLL_INTERVAL'] > 3600) {
            throw new InvalidArgumentException('POLL_INTERVAL muss zwischen 0,2 und 3600 Sekunden liegen.');
        }
        $result['POLL_INTERVAL'] = (float) $result['POLL_INTERVAL'];
        foreach (['MQTT_TOPIC', 'MQTT_TOPIC_FREEDS'] as $key) {
            if ($result[$key] === '' || strlen($result[$key]) > 65535 || preg_match('/[+#\x00-\x1f\x7f]/', $result[$key])) {
                throw new InvalidArgumentException("$key muss ein MQTT-Publish-Topic ohne Platzhalter sein.");
            }
            $result[$key] = strtolower($result[$key]);
        }
        if ($result['ENABLED'] && $result['ENVOY_HOST'] === '') {
            throw new InvalidArgumentException('Bitte zuerst den Envoy-Host eintragen.');
        }
        if ($result['ENABLED'] && !$result['MQTT_USE_LOXBERRY'] && $result['MQTT_HOST'] === '') {
            throw new InvalidArgumentException('Bitte den externen MQTT-Host eintragen oder den LoxBerry-MQTT-Broker auswählen.');
        }
        return $result;
    }

    public static function save(string $path, array $values): void
    {
        $values = self::validate($values);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Konfigurationsverzeichnis konnte nicht erstellt werden.');
        }
        $temporary = tempnam($directory, '.options-');
        if ($temporary === false) {
            throw new RuntimeException('Temporäre Konfigurationsdatei konnte nicht erstellt werden.');
        }
        try {
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('Dateiberechtigungen konnten nicht gesetzt werden.');
            }
            $json = json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !rename($temporary, $path)) {
                throw new RuntimeException('Konfiguration konnte nicht gespeichert werden.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
