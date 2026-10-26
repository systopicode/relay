#!/usr/bin/env bash
# Installs or updates the Centrifugo hub of systopic/relay on Ubuntu/Debian
# (Plesk or plain): binary, system user, config, systemd service. One hub per
# server, shared by all projects. Run as root. See ../README.md.
#
#   bash install.sh --origins=https://staging.systopic.net[,https://…]
#   bash install.sh                       update: new binary, restart, check
#   bash install.sh --print               only print the nginx and relay blocks
#   bash install.sh --uninstall           remove service, binary, user, config
#
# Options: --version=6.9.7  --port=8100 (browser, behind nginx; the API for
# PHP is port+1)  --origins=… (first run only, it goes into the config).
# Running it again keeps config and secrets; only binary and unit are renewed.
set -euo pipefail

VERSION=6.9.7
PORT=8100
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

CONF_DIR=/etc/centrifugo
CONF=$CONF_DIR/config.json
BIN=/usr/local/bin/centrifugo
UNIT=/etc/systemd/system/centrifugo.service

say() { printf '\n=== %s\n' "$*"; }
die() { echo "ERROR: $*" >&2; exit 1; }

# value of a key in our config; written by this script, so a grep is enough
conf_value() { grep -oE "\"$1\": *\"?[^\",}]*" "$CONF" | head -1 | sed -E 's/.*: *"?//'; }

print_blocks() {
	local port api apikey secret
	port=$(conf_value port); api=$(conf_value internal_port)
	apikey=$(conf_value key); secret=$(conf_value hmac_secret_key)
	say "nginx: Plesk > domain > Apache & nginx Settings > Additional nginx directives"
	cat <<EOF
# systopic/relay - the browser's socket. With a slash: our urls without one
# are actions; the hub itself serves /connection/websocket.
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
EOF
	say "relay block for <project>/config/config.local.json (never synced)"
	cat <<EOF
	"relay": {
		"api": "http://127.0.0.1:$api",
		"apiKey": "$apikey",
		"secret": "$secret",
		"client": "/connection/"
	}
EOF
}

[ "$(id -u)" = 0 ] || die "run as root (Plesk: Tools & Settings > SSH Terminal)"

if [ "$MODE" = print ]; then
	[ -f "$CONF" ] || die "$CONF missing - install first"
	print_blocks
	exit 0
fi

if [ "$MODE" = uninstall ]; then
	systemctl disable --now centrifugo >/dev/null 2>&1 || true
	rm -f "$UNIT" "$BIN"
	systemctl daemon-reload
	rm -rf "$CONF_DIR"
	id centrifugo >/dev/null 2>&1 && userdel centrifugo
	echo "removed: service, $BIN, $CONF_DIR (secrets), user centrifugo"
	echo "left to you: the nginx directives per domain, the relay block per project"
	exit 0
fi

case "$(uname -m)" in
	x86_64) ARCH=linux_amd64 ;;
	aarch64|arm64) ARCH=linux_arm64 ;;
	*) die "unsupported architecture $(uname -m)" ;;
esac

FIRST=0
[ -f "$CONF" ] || FIRST=1
if [ "$FIRST" = 1 ]; then
	[ -n "$ORIGINS" ] || die "first run needs --origins=https://your.domain[,https://other.domain]"
	API_PORT=$((PORT + 1))
	if ss -ltn | grep -qE ":($PORT|$API_PORT)\b"; then
		ss -ltnp | grep -E ":($PORT|$API_PORT)\b" >&2
		die "port $PORT or $API_PORT is taken - choose another with --port="
	fi
fi

say "Centrifugo $VERSION ($ARCH)"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
FILE=centrifugo_${VERSION}_${ARCH}.tar.gz
URL=https://github.com/centrifugal/centrifugo/releases/download/v$VERSION
curl -fsSL -o "$TMP/$FILE" "$URL/$FILE"
curl -fsSL -o "$TMP/checksums.txt" "$URL/centrifugo_${VERSION}_checksums.txt"
(cd "$TMP" && grep " $FILE\$" checksums.txt | sha256sum -c -) || die "checksum mismatch"
tar -xzf "$TMP/$FILE" -C "$TMP" centrifugo
install -m 755 "$TMP/centrifugo" "$BIN.new"
mv -f "$BIN.new" "$BIN"   # atomic, a running hub keeps its old file until restart
"$BIN" version

say "system user and config"
id centrifugo >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin centrifugo
mkdir -p "$CONF_DIR"
if [ "$FIRST" = 1 ]; then
	SECRET=$(openssl rand -hex 32)
	APIKEY=$(openssl rand -hex 24)
	ORIGINS_JSON=$(printf '%s' "$ORIGINS" | sed -E 's/ //g; s/,/", "/g; s/^/"/; s/$/"/')
	umask 027
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
	"client": {
		"allowed_origins": [$ORIGINS_JSON],
		"token": { "hmac_secret_key": "$SECRET" }
	},
	"http_api": { "key": "$APIKEY" },
	"channel": {
		"namespaces": [
			{ "name": "data", "allow_subscribe_for_client": true, "allow_subscribe_for_anonymous": true },
			{ "name": "user", "allow_user_limited_channels": true, "allow_subscribe_for_client": true },
			{ "name": "device", "allow_subscribe_for_client": true }
		]
	}
}
EOF
	echo "written: $CONF (new secrets)"
else
	echo "kept: $CONF (secrets unchanged)"
fi
chown root:centrifugo "$CONF"
chmod 640 "$CONF"
"$BIN" checkconfig -c "$CONF"

say "systemd service"
cat > "$UNIT" <<EOF
[Unit]
Description=Centrifugo hub (systopic/relay), one for all projects
After=network.target

[Service]
User=centrifugo
Group=centrifugo
ExecStart=$BIN -c $CONF
Restart=always
RestartSec=2
LimitNOFILE=65536
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable centrifugo >/dev/null 2>&1
systemctl restart centrifugo

say "check"
API=$(conf_value internal_port)
APIKEY=$(conf_value key)
for _ in 1 2 3 4 5 6 7 8 9 10; do
	curl -fs "http://127.0.0.1:$API/health" >/dev/null 2>&1 && break
	sleep 0.5
done
systemctl is-active centrifugo
ss -ltn | grep -E ":($(conf_value port)|$API)\b" || die "hub does not listen"
echo "health:  $(curl -s "http://127.0.0.1:$API/health")"
echo "publish: $(curl -s -H "X-API-Key: $APIKEY" -d '{"channel":"data:test/x","data":{}}' "http://127.0.0.1:$API/api/publish")"

print_blocks
say "done - next: nginx directives (once per domain), then the relay block per project"
