#!/bin/sh
# Starts CLIProxyAPI on the persistent state directory (compose/base.yaml). The first start of an empty directory
# writes config.yaml from config.template.yaml with the access key from CLIPROXY_API_KEY; later starts keep the file,
# which the dashboard and CLIProxyAPI itself change.
set -eu
state=/CLIProxyAPI/state
mkdir -p "$state/auth" "$state/logs"
if [ ! -s "$state/config.yaml" ]; then
    case "$CLIPROXY_API_KEY" in
        *[!A-Za-z0-9_-]*|'') echo "CLIPROXY_API_KEY may hold only letters, digits, - and _" >&2; exit 1 ;;
    esac
    umask 077
    sed "s/@CLIPROXY_API_KEY@/$CLIPROXY_API_KEY/" /opt/cliproxy/config.template.yaml > "$state/config.yaml.new"
    mv "$state/config.yaml.new" "$state/config.yaml"
    echo "Wrote $state/config.yaml from the template"
fi
cd "$state"
exec /CLIProxyAPI/CLIProxyAPI -config "$state/config.yaml"
