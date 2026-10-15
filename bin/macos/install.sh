#!/usr/bin/env bash
# Installs or updates the Centrifugo hub of systopic/relay on a Mac workstation
# (DEV, ServBay): binary, config, launchd agent of the logged-in user (no sudo).
# The proxy /connection/ in ServBay's web server is printed, not written.
# See ../README.md. Untested so far (written on Windows, bash 3.2 compatible).
#
#   bash install.sh                       install or update
#   bash install.sh --print               only print proxy and relay blocks
#   bash install.sh --uninstall           remove agent, binary, config
#
# Options: --version=6.9.7  --port=8000 (browser; API and admin on port+1)
# --origins=https://a,https://b (first run only; default: https://localhost
# and every IPv4 address of this Mac). Running it again keeps config and
# secrets; binary and agent are renewed, the hub is restarted.
set -euo pipefail

VERSION=6.9.7
PORT=8000
ORIGINS=""
MODE=install
for a in "$@"; do
	case "$a" in
		--version=*) VERSION=${a#*=} ;;
		--port=*) PORT=${a#*=} ;;
		--origins=*) ORIGINS=${a#*=} ;;
		--print) MODE=print ;;
		--uninstall) MODE=uninstall ;;
		-h|--help) awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "$0"; exit 0 ;;
		*) echo "unknown option: $a" >&2; exit 2 ;;
	esac
done

DIR=$HOME/.centrifugo
BIN=$DIR/centrifugo
CONF=$DIR/config.json
LOG=$DIR/centrifugo.log
LABEL=net.systopic.centrifugo
PLIST=$HOME/Library/LaunchAgents/$LABEL.plist
DOMAIN=gui/$(id -u)

say() { printf '\n=== %s\n' "$*"; }
die() { echo "ERROR: $*" >&2; exit 1; }
conf_value() { grep -oE "\"$1\": *\"?[^\",}]*" "$CONF" | head -1 | sed -E 's/.*: *"?//'; }
listening() { lsof -nP -iTCP:"$1" -sTCP:LISTEN >/dev/null 2>&1; }

print_blocks() {
	local port api
	port=$(conf_value port); api=$(conf_value internal_port)
	say "proxy in ServBay's web server (the site of the projects) - pick the one in use"
	cat <<EOF
# nginx
location = /connection/websocket/ {
	proxy_pass http://127.0.0.1:$port/connection/websocket;
	proxy_http_version 1.1;
	proxy_set_header Upgrade \$http_upgrade;
	proxy_set_header Connection "upgrade";
	proxy_set_header Host \$host;
	proxy_read_timeout 1h;
}
location /connection/ {
	proxy_pass http://127.0.0.1:$port;
	proxy_http_version 1.1;
	proxy_set_header Upgrade \$http_upgrade;
	proxy_set_header Connection "upgrade";
	proxy_set_header Host \$host;
	proxy_buffering off;
	proxy_read_timeout 1h;
}

# Caddy
rewrite /connection/websocket/ /connection/websocket
reverse_proxy /connection/* 127.0.0.1:$port {
	flush_interval -1
}

# Apache
ProxyPass /connection/websocket/ http://127.0.0.1:$port/connection/websocket upgrade=websocket
ProxyPass /connection/ http://127.0.0.1:$port/connection/ upgrade=websocket flushpackets=on
EOF
	say "relay block for <project>/config/DEV.<machine>.config.json"
	cat <<EOF
	"relay": {
		"//": "Centrifugo on this machine: $CONF (secret = client.token.hmac_secret_key, apiKey = http_api.key)",
		"api": "http://127.0.0.1:$api",
		"apiKey": "$(conf_value key)",
		"secret": "$(conf_value hmac_secret_key)",
		"client": "/connection/",
		"plainTopics": true
	}

Admin UI: http://127.0.0.1:$api  (password: admin.password in $CONF)
EOF
}

[ "$(id -u)" != 0 ] || die "run as your own user, not with sudo"

if [ "$MODE" = print ]; then
	[ -f "$CONF" ] || die "$CONF missing - install first"
	print_blocks
	exit 0
fi

if [ "$MODE" = uninstall ]; then
	launchctl bootout "$DOMAIN/$LABEL" >/dev/null 2>&1 || true
	rm -f "$PLIST"
	rm -rf "$DIR"
	echo "removed: launchd agent, $DIR (binary, config, secrets, log)"
	echo "left to you: the proxy in ServBay, the relay block per project"
	exit 0
fi

case "$(uname -m)" in
	arm64) ARCH=darwin_arm64 ;;
	x86_64) ARCH=darwin_amd64 ;;
	*) die "unsupported architecture $(uname -m)" ;;
esac

FIRST=0
[ -f "$CONF" ] || FIRST=1
if [ "$FIRST" = 1 ]; then
	API_PORT=$((PORT + 1))
	if listening "$PORT" || listening "$API_PORT"; then
		die "port $PORT or $API_PORT is taken - choose another with --port="
	fi
	if [ -z "$ORIGINS" ]; then
		ORIGINS=https://localhost
		for ip in $(ifconfig | awk '/inet / && $2 != "127.0.0.1" { print $2 }'); do
			ORIGINS="$ORIGINS,https://$ip"
		done
	fi
fi

say "Centrifugo $VERSION ($ARCH)"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
FILE=centrifugo_${VERSION}_${ARCH}.tar.gz
URL=https://github.com/centrifugal/centrifugo/releases/download/v$VERSION
curl -fsSL -o "$TMP/$FILE" "$URL/$FILE"
curl -fsSL -o "$TMP/checksums.txt" "$URL/centrifugo_${VERSION}_checksums.txt"
(cd "$TMP" && grep " $FILE\$" checksums.txt | shasum -a 256 -c -) || die "checksum mismatch"
tar -xzf "$TMP/$FILE" -C "$TMP" centrifugo
mkdir -p "$DIR"
install -m 755 "$TMP/centrifugo" "$BIN.new"
mv -f "$BIN.new" "$BIN"
"$BIN" version

say "config"
if [ "$FIRST" = 1 ]; then
	ORIGINS_JSON=$(printf '%s' "$ORIGINS" | sed -E 's/ //g; s/,/", "/g; s/^/"/; s/$/"/')
	umask 077
	cat > "$CONF" <<EOF
{
	"http_server": {
		"address": "127.0.0.1",
		"port": $PORT,
		"internal_address": "127.0.0.1",
		"internal_port": "$API_PORT"
	},
	"log": { "level": "info" },
	"health": { "enabled": true },
	"admin": {
		"enabled": true,
		"password": "$(openssl rand -hex 12)",
		"secret": "$(openssl rand -hex 32)"
	},
	"client": {
		"allowed_origins": [$ORIGINS_JSON],
		"token": { "hmac_secret_key": "$(openssl rand -hex 32)" }
	},
	"http_api": { "key": "$(openssl rand -hex 24)" },
	"channel": {
		"namespaces": [
			{ "name": "data", "allow_subscribe_for_client": true, "allow_subscribe_for_anonymous": true },
			{ "name": "user", "allow_user_limited_channels": true, "allow_subscribe_for_client": true },
			{ "name": "debug", "allow_subscribe_for_client": true }
		]
	}
}
EOF
	echo "written: $CONF (new secrets, origins: $ORIGINS)"
else
	echo "kept: $CONF (secrets unchanged)"
fi
"$BIN" checkconfig -c "$CONF"

say "launchd agent (starts at login, restarts on exit)"
mkdir -p "$(dirname "$PLIST")"
cat > "$PLIST" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>Label</key><string>$LABEL</string>
	<key>ProgramArguments</key>
	<array>
		<string>$BIN</string>
		<string>-c</string>
		<string>$CONF</string>
	</array>
	<key>WorkingDirectory</key><string>$DIR</string>
	<key>RunAtLoad</key><true/>
	<key>KeepAlive</key><true/>
	<key>StandardOutPath</key><string>$LOG</string>
	<key>StandardErrorPath</key><string>$LOG</string>
</dict>
</plist>
EOF
launchctl bootout "$DOMAIN/$LABEL" >/dev/null 2>&1 || true
launchctl bootstrap "$DOMAIN" "$PLIST"
echo "agent: $PLIST"

say "check"
API=$(conf_value internal_port)
for _ in 1 2 3 4 5 6 7 8 9 10; do
	curl -fs "http://127.0.0.1:$API/health" >/dev/null 2>&1 && break
	sleep 0.5
done
echo "health:  $(curl -s "http://127.0.0.1:$API/health")"
echo "publish: $(curl -s -H "X-API-Key: $(conf_value key)" -d '{"channel":"data:test/x","data":{}}' "http://127.0.0.1:$API/api/publish")"
echo "socket:  $(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$(conf_value port)/connection/websocket") (400 = the hub answers, only without upgrade)"

print_blocks
say "done - next: the proxy in ServBay (once), then the relay block per project; log: $LOG"
