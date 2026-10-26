# Hub installieren: Centrifugo für systopic/relay

Relay braucht je Rechner bzw. Server **einen** Hub ([Centrifugo](https://centrifugal.dev),
Go-Binary, Apache-2.0). Dieser Hub gilt für alle Projekte. PHP veröffentlicht über die
interne API (nur 127.0.0.1), der Browser verbindet sich über `/connection/` auf derselben
Domain, und der Webserver leitet das an den Hub weiter. Der Hub hat keine Logik und keinen
DB-Zugriff.

Je Plattform gibt es ein Skript, das installiert und beim nächsten Aufruf aktualisiert:

| Plattform | Skript | Als | Ports (Browser / API) | Weiterleitung `/connection/` |
|---|---|---|---|---|
| Ubuntu/Debian, Plesk (STAGING, LIVE) | `ubuntu/install.sh` | root | 8100 / 8101 | nginx-Direktiven in Plesk, **von Hand** (Skript gibt sie aus) |
| Windows, Laragon (DEV) | `windows/install.ps1` | eigener Benutzer | 8000 / 8001 | Apache-Datei, **schreibt das Skript** |
| macOS, ServBay (DEV) | `macos/install.sh` | eigener Benutzer, ohne sudo | 8000 / 8001 | im ServBay-Webserver, **von Hand** (Skript gibt sie aus) |

Getestet am 2026-10-15: Windows auf DEV.ojoffice, Ubuntu 24.04 in WSL. macOS am
2026-10-20 auf DEV.lsmacbook (ServBay, Apache).

Alle drei Skripte:

- laden die Version (Vorgabe **6.9.7**, Option `--version` bzw. `-Version`) von GitHub
  und prüfen die SHA-256 gegen die `checksums.txt` des Releases,
- schreiben die Config **nur beim ersten Lauf** und erzeugen dabei neue Geheimnisse:
  `client.token.hmac_secret_key` signiert die Browser-Tokens, `http_api.key` schützt die
  API. Ein weiterer Lauf ist ein Update: neues Binary, Neustart, Config und Geheimnisse
  bleiben,
- lassen den Hub nur auf 127.0.0.1 lauschen und brechen ab, wenn beim ersten Lauf ein Port
  belegt ist (dann `--port` bzw. `-Port`, die API liegt immer auf Port+1),
- prüfen am Ende: Health `{}`, Publish `{"result":{}}`, Socket 400 (der Hub antwortet,
  nur ohne WebSocket-Upgrade),
- geben den `relay`-Block für das Projekt aus; `--print` bzw. `-Print` gibt ihn später
  noch einmal aus,
- entfernen mit `--uninstall` bzw. `-Uninstall` alles, was sie angelegt haben. Die
  Weiterleitung von Hand und den `relay`-Block im Projekt entfernst du selbst.

## Ubuntu / Plesk (STAGING, LIVE)

Das Paket kommt per Sync auf den Server, das Skript liegt dann im Webroot (`bin/` ist per
`.htaccess` gesperrt). Als root (Plesk: Tools & Settings → SSH Terminal):

```bash
bash /var/www/vhosts/staging.systopic.net/httpdocs/packages/relay/bin/ubuntu/install.sh \
	--origins=https://staging.systopic.net
```

`--origins` sind **alle** Domains, deren Seiten den Hub benutzen. Nur sie dürfen sich
verbinden. Spätere Domains trägst du in `/etc/centrifugo/config.json` unter
`client.allowed_origins` ein, danach `systemctl restart centrifugo`.

Was das Skript tut:

1. Architektur bestimmen (`linux_amd64`/`linux_arm64`), Binary laden, prüfen, nach
   `/usr/local/bin/centrifugo` legen.
2. Systembenutzer `centrifugo` anlegen (ohne Login, ohne Home).
3. `/etc/centrifugo/config.json` schreiben, `root:centrifugo`, Modus 640. Namespaces
   `data` (Signale der Tabellen, auch für Anonyme) und `user` (pro Benutzer). Ohne
   Admin-Oberfläche und ohne `debug`, beides gibt es nur auf DEV.
4. systemd-Dienst `centrifugo` (`/etc/systemd/system/centrifugo.service`): startet mit
   dem Server, startet nach einem Absturz neu, läuft abgeschottet (`ProtectSystem`,
   `ProtectHome`, `NoNewPrivileges`).
5. Prüfen, dann zwei Blöcke ausgeben:
   - **nginx-Direktiven**, einmal je Domain: Plesk → Domain → Apache & nginx Settings →
     „Additional nginx directives“. Prüfen mit
     `curl -s -o /dev/null -w '%{http_code}\n' https://<domain>/connection/websocket/`.
     400 ist richtig. 404 heißt, die Regel greift nicht. 502 heißt, der Hub ist nicht
     erreichbar.
   - **`relay`-Block** für `<projekt>/config/config.local.json`. Die Datei wird nie
     gesynct, deshalb stehen die Geheimnisse dort und nicht in `STAGING.config.json`.

Betrieb: `systemctl status centrifugo`, `journalctl -u centrifugo -n 50`.
Der ganze Ablauf mit Composer, Test und Fehlertabelle steht in
[system/.cursor/docs/relay-deployment.md](../../system/.cursor/docs/relay-deployment.md).

## Windows / Laragon (DEV)

In PowerShell (keine Admin-Rechte nötig):

```powershell
powershell -ExecutionPolicy Bypass -File V:\www\packages\relay\bin\windows\install.ps1
```

Was das Skript tut:

1. Binary laden, prüfen, nach `C:\laragon\bin\centrifugo\centrifugo.exe` legen. Ein
   laufender Hub wird dafür beendet, weil Windows die exe sperrt.
2. `C:\laragon\etc\centrifugo\config.json` schreiben, **ohne BOM**, sonst bricht
   Centrifugo beim Lesen ab. Erlaubte Origins sind `https://localhost` und jede
   IPv4-Adresse des Rechners, oder die Liste aus `-Origins`. Dazu die Admin-Oberfläche
   (`http://127.0.0.1:8001`, Passwort unter `admin.password`) und der Namespace `debug`.
3. `C:\laragon\etc\apache2\alias\centrifugo.conf`: zwei `ProxyPass`-Regeln, zuerst
   `/connection/websocket/` (mit Schrägstrich, denn bei uns sind URLs ohne Schrägstrich
   Actions), dann `/connection/` (mit `flushpackets` für SSE). Warnt, wenn
   `mod_proxy`, `mod_proxy_http` oder `mod_proxy_wstunnel` in der `httpd.conf` nicht
   geladen sind.
4. Laragon-Autostart: Zeile `Centrifugo: autorun …` in `C:\laragon\usr\Procfile`.
5. Hub versteckt starten, prüfen, `relay`-Block ausgeben.

Danach: **Apache in Laragon neu laden** (Menü → Apache → Reload), den Block in
`<projekt>/config/DEV.<rechner>.config.json` eintragen. Auf DEV mit `plainTopics: true`,
dann stehen die Tabellennamen lesbar in den Kanälen.

Option `-Laragon` für einen anderen Laragon-Ordner (Vorgabe `C:\laragon`).

## macOS / ServBay (DEV)

Im Terminal als eigener Benutzer, **ohne** sudo. Autostart ist der launchd-Agent,
kein extra Schritt:

```bash
bash /pfad/zu/packages/relay/bin/macos/install.sh \
	--origins=https://dev.local,https://localhost
```

`--origins` beim **ersten** Lauf. Die Vorgabe des Skripts ist nur
`https://localhost` und die IPv4-Adressen des Macs. Die Seite läuft unter
`https://dev.local`, und ohne diese Origin lehnt der Hub den Browser ab
(`relay: hub error`). Weitere Origins später in `client.allowed_origins` in
`~/.centrifugo/config.json`, danach der Agent neu (siehe unten).

Was das Skript tut:

1. Binary (`darwin_arm64`/`darwin_amd64`) laden, prüfen, nach `~/.centrifugo/centrifugo`
   legen.
2. `~/.centrifugo/config.json` (Modus 600) wie unter Windows schreiben.
3. launchd-Agent `~/Library/LaunchAgents/net.systopic.centrifugo.plist`: startet beim
   Anmelden (`RunAtLoad`), startet nach einem Absturz neu (`KeepAlive`), Log in
   `~/.centrifugo/centrifugo.log`.
4. Prüfen (`health: {}`, `publish: {"result":{}}`, Socket 400), dann die
   Weiterleitung für nginx, Caddy und Apache ausgeben, plus den `relay`-Block.

**Weiterleitung:** ServBay bedient `dev.local` mit Apache
(`/Applications/ServBay/etc/apache/`, dieselbe Datei wie unter `package/etc/apache/`).
`mod_proxy`, `mod_proxy_http` und `mod_proxy_wstunnel` sind in der `httpd.conf`
schon geladen. Die Datei `vhosts/dev.local.conf` ist von ServBay erzeugt und wird
überschrieben — die beiden `ProxyPass`-Zeilen gehören in eine **eigene** Datei
daneben, `vhosts/centrifugo.conf`. `httpd.conf` lädt `vhosts/*.conf`, die Regeln
gelten für die VirtualHosts mit.

```apache
# systopic/relay — Centrifugo on 127.0.0.1:8000.
ProxyPass /connection/websocket/ http://127.0.0.1:8000/connection/websocket upgrade=websocket
ProxyPass /connection/ http://127.0.0.1:8000/connection/ upgrade=websocket flushpackets=on
```

Danach Apache neu laden (nicht die generierte vhost-Datei anfassen):

```bash
bash /Applications/ServBay/script/apache.sh reload
curl -sk -o /dev/null -w '%{http_code}\n' https://dev.local/connection/websocket/
```

400 ist richtig. 404 heißt, die Datei liegt nicht im geladenen `vhosts/`. 502
heißt, der Hub ist nicht da.

Den ausgegebenen `relay`-Block nach
`<projekt>/config/DEV.<rechner>.config.json` (hier `DEV.lsmacbook.config.json`),
mit `"plainTopics": true`.

Betrieb: `launchctl print gui/$(id -u)/net.systopic.centrifugo`,
`tail -f ~/.centrifugo/centrifugo.log`. Agent neu laden, nachdem die Config
geändert wurde:

```bash
launchctl bootout gui/$(id -u)/net.systopic.centrifugo
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/net.systopic.centrifugo.plist
```

## Aktualisieren

Dasselbe Skript noch einmal ausführen, auf Wunsch mit `--version=…` bzw. `-Version …`.
Vorher den [Changelog](https://github.com/centrifugal/centrifugo/releases) auf
Änderungen an der Config prüfen. Das Skript ruft `centrifugo checkconfig` auf und bricht
ab, wenn die alte Config nicht mehr passt. Offene Browser verbinden sich nach dem
Neustart von selbst neu.

## Geheimnis wechseln

Neues `hmac_secret_key` eintragen, das alte als `hmac_previous_secret_key` mit
`hmac_previous_secret_key_valid_until` daneben, den Hub neu starten und danach die
`relay`-Blöcke der Projekte anpassen. Offene Seiten bleiben verbunden, bis das alte
Geheimnis abläuft.
