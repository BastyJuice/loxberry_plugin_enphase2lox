<?php
namespace Enphase;

final class RuntimeLog
{
    private $directory;
    private $state = [];
    private $lastStatusWrite = 0.0;
    private $repeats = [];
    private $repeatEvery;

    public function __construct(string $directory, int $repeatEvery = 10)
    {
        $this->directory = $directory;
        $this->repeatEvery = max(1, $repeatEvery);
    }
    public function write(string $level, string $message): void
    {
        // All callers supply diagnostics, never response bodies or credentials.
        $message = substr(str_replace(["\r", "\n"], ' ', $message), 0, 2000);
        $path = $this->directory . '/envoy.log';
        clearstatcache(true, $path);
        if (is_file($path) && filesize($path) > 1048576) {
            @rename($path, $path . '.1');
        }
        file_put_contents($path, date('c') . ' [' . $level . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
    }
    public function repeated(string $message): void
    {
        $key = hash('sha256', $message);
        // Bound memory even if a device emits many different errors.
        if (count($this->repeats) > 100) { $this->repeats = []; }
        $this->repeats[$key] = ($this->repeats[$key] ?? 0) + 1;
        $count = $this->repeats[$key];
        if ($count === 1 || $count % $this->repeatEvery === 0) {
            $this->write('ERROR', $message . ($count > 1 ? ' (Wiederholung ' . $count . ')' : ''));
        }
    }
    public function status(array $values, bool $force = false): void
    {
        $this->state = array_replace($this->state, $values);
        if (!$force && microtime(true) - $this->lastStatusWrite < 5) { return; }
        $this->lastStatusWrite = microtime(true);
        $this->state['updated_at'] = date('c');
        $this->state['pid'] = getmypid();
        $temporary = tempnam($this->directory, '.status-');
        if ($temporary === false) { return; }
        file_put_contents($temporary, json_encode($this->state, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        chmod($temporary, 0600);
        if (!rename($temporary, $this->directory . '/status.json')) { @unlink($temporary); }
    }
}
