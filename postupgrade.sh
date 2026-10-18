#!/bin/bash
# postinstall already preserves the configuration and starts enabled workers.
set -u
home_dir=${LBHOMEDIR:-${5:-'REPLACELBHOMEDIR'}}
case "$home_dir" in
    /*) ;;
    *) echo '<ERROR> LoxBerry base directory is missing.' >&2; exit 2 ;;
esac
if ! /bin/bash "${LBPBIN:-$home_dir/bin/plugins}/$3/service.sh" start; then
    echo '<WARNING> Updated files were installed, but the worker could not be started. Check the plugin log.'
    exit 1
fi
exit 0
