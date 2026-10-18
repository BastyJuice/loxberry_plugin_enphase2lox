#!/bin/bash
# LoxBerry invokes this hook as loxberry, after copying plugin files.
set -eu
umask 077
home_dir=${LBHOMEDIR:-${5:-'REPLACELBHOMEDIR'}}
case "$home_dir" in
    /*) ;;
    *) echo '<ERROR> LoxBerry base directory is missing.' >&2; exit 2 ;;
esac
folder=$3
bin_dir="${LBPBIN:-$home_dir/bin/plugins}/$folder"
config_dir="${LBPCONFIG:-$home_dir/config/plugins}/$folder"
log_dir="${LBPLOG:-$home_dir/log/plugins}/$folder"
data_dir="${LBPDATA:-$home_dir/data/plugins}/$folder"
mkdir -p "$config_dir" "$log_dir" "$data_dir"
chmod 700 "$config_dir"
chmod 755 "$bin_dir/service.sh"
# Never ship an options.json or token.txt: upgrades must preserve user secrets.
/usr/bin/php -r '
    require $argv[1];
    if (!is_file($argv[2])) { Enphase\Config::save($argv[2], Enphase\Config::defaults()); }
' "$bin_dir/lib/Config.php" "$config_dir/options.json"
chmod 600 "$config_dir/options.json"
if [ -f "$config_dir/token.txt" ]; then chmod 600 "$config_dir/token.txt"; fi
/bin/bash "$bin_dir/service.sh" start
echo '<OK> enphase2lox installed. Configure the Envoy and enable the plugin in its web interface.'
