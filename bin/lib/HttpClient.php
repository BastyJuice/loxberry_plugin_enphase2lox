<?php
namespace Enphase;

class HttpError extends \RuntimeException
{
    public $status;
    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

/** Uses the system PHP cURL extension; cloud certificate checks stay enabled. */
class HttpClient
{
    public function request(string $method, string $url, array $options = []): array
    {
        $curl = $this->create($method, $url, $options);
        $body = '';
        curl_setopt($curl, CURLOPT_WRITEFUNCTION, function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 2097152) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        });
        try {
            if (curl_exec($curl) === false) {
                throw new \RuntimeException('HTTP-Verbindung fehlgeschlagen: ' . curl_error($curl));
            }
            return ['status' => (int) curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => $body];
        } finally {
            curl_close($curl);
        }
    }

    private function create(string $method, string $url, array $options)
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('cURL konnte nicht gestartet werden.');
        }
        $verify = $options['verify'] ?? true;
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $options['timeout'] ?? 10,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $options['headers'] ?? [],
            CURLOPT_USERAGENT => 'enphase2lox/1.0',
        ]);
        if (isset($options['form'])) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($options['form']));
        }
        if (isset($options['json'])) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($options['json'], JSON_THROW_ON_ERROR));
            curl_setopt($curl, CURLOPT_HTTPHEADER, array_merge($options['headers'] ?? [], ['Content-Type: application/json']));
        }
        if (isset($options['digest'])) {
            curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
            curl_setopt($curl, CURLOPT_USERPWD, $options['digest'][0] . ':' . $options['digest'][1]);
        }
        return $curl;
    }

    /** Nonblocking cURL loop keeps the stop file responsive even on a quiet SSE stream. */
    public function stream(string $url, array $options, callable $receive, callable $stop): void
    {
        $options['timeout'] = 0;
        $curl = $this->create('GET', $url, $options);
        $multi = curl_multi_init();
        $parser = new SseParser($receive);
        $status = 0;
        curl_setopt($curl, CURLOPT_LOW_SPEED_LIMIT, 1);
        curl_setopt($curl, CURLOPT_LOW_SPEED_TIME, 20);
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, function ($handle, string $line) use (&$status): int {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $match)) {
                $status = (int) $match[1];
            }
            return strlen($line);
        });
        curl_setopt($curl, CURLOPT_WRITEFUNCTION, function ($handle, string $chunk) use ($parser, &$status): int {
            if ($status === 200) {
                $parser->feed($chunk);
            }
            return strlen($chunk);
        });
        curl_multi_add_handle($multi, $curl);
        try {
            do {
                if ($stop()) {
                    return;
                }
                $result = curl_multi_exec($multi, $running);
                if ($result !== CURLM_OK) {
                    throw new \RuntimeException('Envoy-Stream konnte nicht gelesen werden.');
                }
                if ($running) {
                    if (curl_multi_select($multi, 0.2) === -1) {
                        usleep(10000);
                    }
                }
            } while ($running);
            if ($status !== 200) {
                throw new HttpError($status, 'Envoy-Stream: HTTP ' . $status);
            }
            if (curl_errno($curl)) {
                throw new \RuntimeException('Envoy-Stream unterbrochen: ' . curl_error($curl));
            }
            throw new \RuntimeException('Envoy hat den Stream beendet.');
        } finally {
            curl_multi_remove_handle($multi, $curl);
            curl_close($curl);
            curl_multi_close($multi);
        }
    }
}

/** Handles fragmented network chunks, CRLF and multiline SSE events. */
class SseParser
{
    private $buffer = '';
    private $event = [];
    private $receive;
    public function __construct(callable $receive) { $this->receive = $receive; }
    public function feed(string $chunk): void
    {
        $this->buffer .= $chunk;
        if (strlen($this->buffer) + strlen(implode('', $this->event)) > 2097152) {
            throw new \RuntimeException('Envoy-Stream: Ereignis ist zu groß.');
        }
        while (($end = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $end), "\r");
            $this->buffer = substr($this->buffer, $end + 1);
            if ($line === '') {
                if ($this->event) {
                    $json = implode("\n", $this->event);
                    $this->event = [];
                    $data = json_decode($json, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                        call_user_func($this->receive, $data, $json);
                    }
                }
            } elseif (strpos($line, 'data:') === 0) {
                $value = substr($line, 5);
                $this->event[] = strpos($value, ' ') === 0 ? substr($value, 1) : $value;
            }
        }
    }
}
