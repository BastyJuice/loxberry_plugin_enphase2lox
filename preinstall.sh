#!/bin/bash
# LoxBerry invokes this hook as loxberry, before files are copied.
set -u
source_dir=${6:-$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)}
if [ ! -x /usr/bin/php ]; then
    echo '<ERROR> LoxBerry system PHP (/usr/bin/php) is missing.'
    exit 2
fi
if ! command -v flock >/dev/null 2>&1; then
    echo '<ERROR> The LoxBerry system command flock is missing.'
    exit 2
fi
if ! /usr/bin/php "$source_dir/bin/envoy.php" --check; then
    echo '<ERROR> System PHP requirements are not fulfilled. No packages were installed.'
    exit 2
fi
echo '<OK> Using the existing LoxBerry PHP and MQTT tools; no additional packages.'
exit 0
