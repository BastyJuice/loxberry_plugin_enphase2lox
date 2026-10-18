<?php
namespace Enphase;

class EnvoyClient
{
    private $options;
    private $tokenFile;
    private $http;
    private $log;
    private $info = [];
    private $token = '';
    private $nextTokenAttempt = 0;
    private $lastActivation = 0;
    private $lastPayload = '';

    public function __construct(array $options, string $tokenFile, callable $log, ?HttpClient $http = null)
    {
        $this->options = $options;
        $this->tokenFile = $tokenFile;
        $this->log = $log;
        $this->http = $http ?? new HttpClient();
        if (is_file($tokenFile)) {
            $cached = trim((string) file_get_contents($tokenFile));
            if (strlen($cached) < 16384 && preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $cached)) {
                $this->token = $cached;
            }
            chmod($tokenFile, 0600);
        }
    }

    public static function parseInfo(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            // Do not expand external entities from the network device.
            $root = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
            if ($root === false) {
                throw new \RuntimeException('Envoy /info enthält kein gültiges XML.');
            }
            $serial = $root->xpath('//sn');
            $software = $root->xpath('//software');
            $sn = $serial ? trim((string) $serial[0]) : '';
            $sw = $software ? trim((string) $software[0]) : '';
            if ($sn === '' || $sw === '') {
                throw new \RuntimeException('Seriennummer oder Firmware fehlen in Envoy /info.');
            }
            return ['serial' => $sn, 'software' => $sw, 'firmware' => self::detectFirmware($sw)];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function detectFirmware(string $software): int
    {
        if (preg_match('/(?:D|R)(5|7|8)\./', $software, $match)) {
            return (int) $match[1];
        }
        throw new \RuntimeException('Unbekannte Envoy-Firmware: ' . $software);
    }

    public function initialize(): array
    {
        $response = $this->http->request('GET', $this->base() . '/info', $this->localOptions());
        $this->expectOk($response, '/info');
        $this->info = self::parseInfo($response['body']);
        return $this->info;
    }

    public function mode(): string
    {
        if (!$this->info) {
            throw new \LogicException('Envoy noch nicht initialisiert.');
        }
        return $this->info['firmware'] === 5 ? 'stream_v5' : ($this->options['BATTERY_INSTALLED'] ? 'livedata' : 'meters');
    }

    public function poll(): array
    {
        $path = $this->mode() === 'livedata' ? '/ivp/livedata/status' : '/ivp/meters/readings';
        $response = $this->authorized('GET', $path);
        $this->expectOk($response, $path);
        $data = $this->decode($response['body']);
        $this->lastPayload = $response['body'];
        if ($this->mode() === 'livedata' && ($data['connection']['sc_stream'] ?? '') === 'disabled') {
            $this->recoverStream();
            throw new \RuntimeException('Livedata-Stream ist deaktiviert; warte auf Aktivierung.');
        }
        return $data;
    }

    public function lastPayload(): string
    {
        return $this->lastPayload;
    }

    public function recoverStream(): void
    {
        if ($this->mode() !== 'livedata' || time() - $this->lastActivation < $this->options['LIVEDATA_ACTIVATION_COOLDOWN']) {
            return;
        }
        $this->lastActivation = time();
        call_user_func($this->log, 'INFO', 'Livedata-Stream aktivieren.');
        $response = $this->authorized('POST', '/ivp/livedata/stream', ['enable' => 1]);
        $this->expectOk($response, '/ivp/livedata/stream');
        $data = $this->decode($response['body']);
        if (($data['sc_stream'] ?? '') !== 'enabled') {
            throw new \RuntimeException('Envoy hat die Stream-Aktivierung nicht bestätigt.');
        }
    }

    public function stream(callable $receive, callable $stop): void
    {
        $this->http->stream($this->base() . '/stream/meter', array_merge($this->localOptions(), [
            'digest' => ['installer', self::installerPassword($this->info['serial'])],
        ]), $receive, $stop);
    }

    private function base(): string
    {
        return ($this->options['ENVOY_USE_HTTPS'] ? 'https://' : 'http://') . $this->options['ENVOY_HOST'];
    }
    private function localOptions(): array
    {
        return ['verify' => $this->options['ENVOY_VERIFY_TLS'], 'timeout' => 5];
    }
    private function authorized(string $method, string $path, ?array $json = null): array
    {
        if ($this->token === '') {
            $this->refreshToken();
        }
        // Firmware 7/8 IVP endpoints always use HTTPS, as in the Python script.
        $url = 'https://' . $this->options['ENVOY_HOST'] . $path;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $options = $this->localOptions();
            $options['headers'] = ['Authorization: Bearer ' . $this->token];
            if ($json !== null) {
                $options['json'] = $json;
            }
            $response = $this->http->request($method, $url, $options);
            if ($response['status'] !== 401 || $attempt === 1) {
                return $response;
            }
            $this->refreshToken();
        }
        throw new \LogicException('Token-Wiederholung fehlgeschlagen.');
    }

    private function refreshToken(): void
    {
        if (time() < $this->nextTokenAttempt) {
            throw new \RuntimeException('Token-Erneuerung wartet auf die nächste Wiederholung.');
        }
        $this->nextTokenAttempt = time() + 60;
        if ($this->options['ENPHASE_USER'] === '' || $this->options['ENPHASE_PASS'] === '') {
            throw new \RuntimeException('Firmware 7/8 benötigt Enphase-Zugangsdaten oder eine gültige config/token.txt.');
        }
        call_user_func($this->log, 'INFO', 'Enphase-Token erneuern.');
        $login = $this->http->request('POST', 'https://enlighten.enphaseenergy.com/login/login.json', [
            'verify' => true,
            'form' => ['user[email]' => $this->options['ENPHASE_USER'], 'user[password]' => $this->options['ENPHASE_PASS']],
        ]);
        $this->expectOk($login, 'Enphase-Anmeldung');
        $session = $this->decode($login['body']);
        if (empty($session['session_id']) || !is_string($session['session_id'])) {
            throw new \RuntimeException('Enphase hat keine Session-ID geliefert.');
        }
        $response = $this->http->request('POST', 'https://entrez.enphaseenergy.com/tokens', [
            'verify' => true,
            'json' => ['session_id' => $session['session_id'], 'serial_num' => $this->info['serial'], 'username' => $this->options['ENPHASE_USER']],
        ]);
        $this->expectOk($response, 'Enphase-Token');
        $token = trim($response['body']);
        if (!preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $token)) {
            throw new \RuntimeException('Enphase hat keinen gültigen JWT-Token geliefert.');
        }
        $tmp = tempnam(dirname($this->tokenFile), '.token-');
        if ($tmp === false) {
            throw new \RuntimeException('Token-Datei konnte nicht angelegt werden.');
        }
        try {
            chmod($tmp, 0600);
            if (file_put_contents($tmp, $token) === false || !rename($tmp, $this->tokenFile)) {
                throw new \RuntimeException('Token-Datei konnte nicht gespeichert werden.');
            }
        } finally {
            if (is_file($tmp)) { unlink($tmp); }
        }
        $this->token = $token;
        // Successful refresh may be retried after 60s if the Envoy rejects it.
    }

    private function expectOk(array $response, string $endpoint): void
    {
        if ($response['status'] !== 200) {
            throw new HttpError((int) $response['status'], $endpoint . ': HTTP ' . $response['status']);
        }
    }
    private function decode(string $body): array
    {
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new \RuntimeException('Antwort enthält keine gültigen JSON-Messwerte.');
        }
        return $data;
    }

    public static function freedsValue(string $mode, array $data): ?int
    {
        $value = null;
        if ($mode === 'livedata' && isset($data['meters']['grid']['agg_p_mw'])) {
            $raw = $data['meters']['grid']['agg_p_mw'];
            $value = is_numeric($raw) ? (float) $raw * 0.001 : null;
        } elseif ($mode === 'meters') {
            $value = $data[1]['activePower'] ?? null;
        }
        // Python round() uses ties-to-even, including negative grid power.
        return is_numeric($value) ? (int) round((float) $value, 0, PHP_ROUND_HALF_EVEN) : null;
    }

    public static function installerPassword(string $serial): string
    {
        $digest = md5('[e]installer@enphaseenergy.com#' . $serial . ' EnPhAsE eNeRgY ');
        $zeros = substr_count($digest, '0');
        $ones = substr_count($digest, '1');
        $password = '';
        foreach (str_split(substr(strrev($digest), 0, 8)) as $char) {
            if (in_array($zeros, [3, 6, 9], true)) { $zeros--; }
            $zeros = max(0, min(20, $zeros));
            if (in_array($ones, [9, 15], true)) { $ones--; }
            $ones = max(0, min(26, $ones));
            if ($char === '0') { $password .= chr(ord('f') + $zeros--); }
            elseif ($char === '1') { $password .= chr(ord('@') + $ones--); }
            else { $password .= $char; }
        }
        return $password;
    }
}
