#!/bin/bash
# Plugin worker controller. No root privileges or PID-based signals are needed.
set -u
umask 077

bin_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P) || exit 1
folder=$(basename "$bin_dir")
home_dir=${LBHOMEDIR:-${bin_dir%/bin/plugins/*}}
config_dir="${LBPCONFIG:-$home_dir/config/plugins}/$folder"
data_dir="${LBPDATA:-$home_dir/data/plugins}/$folder"
log_dir="${LBPLOG:-$home_dir/log/plugins}/$folder"
php_bin=/usr/bin/php

if [ "$(id -u)" -eq 0 ]; then
    echo 'Run this controller as loxberry, not root.' >&2
    exit 1
fi
mkdir -p "$log_dir" "$data_dir" "$config_dir" || exit 1
exec 9>"$log_dir/service.lock"
flock -w 55 -x 9 || { echo 'Another service operation is still running.' >&2; exit 1; }

running() {
    "$php_bin" -r '
        $lock = fopen($argv[1], "c");
        if (!$lock) { exit(2); }
        $available = flock($lock, LOCK_EX | LOCK_NB);
        if ($available) { flock($lock, LOCK_UN); }
        fclose($lock);
        exit($available ? 1 : 0);
    ' "$log_dir/worker.lock"
}

enabled() {
    "$php_bin" -r '
        require $argv[1];
        try { $values = Enphase\Config::load($argv[2]); }
        catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(2); }
        exit($values["ENABLED"] ? 0 : 3);
    ' "$bin_dir/lib/Config.php" "$config_dir/options.json"
}

start_worker() {
    if running; then
        echo 'running'
        return 0
    fi
    enabled
    local enabled_result=$?
    if [ "$enabled_result" -eq 3 ]; then
        echo 'disabled'
        return 0
    elif [ "$enabled_result" -ne 0 ]; then
        return 1
    fi
    rm -f "$log_dir/stop"
    nohup "$php_bin" -d "include_path=.:$home_dir/libs/phplib" "$bin_dir/envoy.php" \
        --config "$config_dir/options.json" --data "$data_dir" --log "$log_dir" \
        --stop-file "$log_dir/stop" </dev/null >/dev/null 2>&1 9>&- &
    for attempt in {1..20}; do
        sleep 0.1
        if running; then
            echo 'running'
            return 0
        fi
    done
    echo 'Worker could not be started; check the plugin log.' >&2
    return 1
}

stop_worker() {
    if ! running; then
        echo 'stopped'
        return 0
    fi
    : >"$log_dir/stop"
    for attempt in {1..225}; do
        if ! running; then
            echo 'stopped'
            return 0
        fi
        sleep 0.2
    done
    echo 'Worker is finishing its current request; stop remains requested.' >&2
    return 1
}

case "${1:-status}" in
    start) start_worker ;;
    stop) stop_worker ;;
    restart) stop_worker && start_worker ;;
    status)
        if running; then echo 'running'; else echo 'stopped'; fi
        ;;
    *) echo 'Usage: service.sh start|stop|restart|status' >&2; exit 2 ;;
esac
