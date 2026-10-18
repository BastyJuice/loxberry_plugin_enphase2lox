#!/bin/bash
# Stop the old worker before replacing its code. Configuration stays in place.
set -u
home_dir=${LBHOMEDIR:-${5:-'REPLACELBHOMEDIR'}}
case "$home_dir" in
    /*) ;;
    *) echo '<ERROR> LoxBerry base directory is missing.' >&2; exit 2 ;;
esac
controller="${LBPBIN:-$home_dir/bin/plugins}/$3/service.sh"
if [ -f "$controller" ] && ! /bin/bash "$controller" stop; then
    echo '<ERROR> Worker is still stopping. Retry the update after its current request finishes.'
    exit 2
fi
exit 0
