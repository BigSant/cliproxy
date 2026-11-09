#!/bin/sh
# Starts CLIProxyAPI on the persistent state directory (compose/base.yaml). The first start of an empty directory
# writes config.yaml from config.template.yaml with the access key from CLIPROXY_API_KEY; later starts keep the file,
# which the dashboard and CLIProxyAPI itself change.
set -eu
# Everything CLIProxyAPI writes (accounts, config, logs) is for the owner only.
umask 077
state=/CLIProxyAPI/state
mkdir -p "$state/auth" /CLIProxyAPI/traffic/logs /CLIProxyAPI/traffic/archive
if [ ! -s "$state/config.yaml" ]; then
    case "$CLIPROXY_API_KEY" in
        *[!A-Za-z0-9_-]*|'') echo "CLIPROXY_API_KEY may hold only letters, digits, - and _" >&2; exit 1 ;;
    esac
    sed "s/@CLIPROXY_API_KEY@/$CLIPROXY_API_KEY/" /opt/cliproxy/config.template.yaml > "$state/config.yaml.new"
    mv "$state/config.yaml.new" "$state/config.yaml"
    echo "Wrote $state/config.yaml from the template"
fi
# CLIProxyAPI writes main.log to logs/ of its working directory and the request logs to logs/ beside config.yaml. Both
# go to the traffic directory, which the collector reads: the state directory's logs/ becomes a link to it (its old
# files move along).
traffic=/CLIProxyAPI/traffic/logs
if [ -d "$state/logs" ] && [ ! -L "$state/logs" ]; then
    find "$state/logs" -mindepth 1 -maxdepth 1 -type f -exec mv -f {} "$traffic/" \;
    rmdir "$state/logs"
fi
ln -sfn "$traffic" "$state/logs"
cd /CLIProxyAPI/traffic
exec /CLIProxyAPI/CLIProxyAPI -config "$state/config.yaml"
