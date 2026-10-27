<#
Installs or updates the Centrifugo hub of systopic/relay on a Laragon
workstation (DEV): binary, config, Apache proxy, Laragon autostart. See
..\README.md.

  powershell -ExecutionPolicy Bypass -File install.ps1            install or update
  powershell -ExecutionPolicy Bypass -File install.ps1 -Print     only print the relay block
  powershell -ExecutionPolicy Bypass -File install.ps1 -Uninstall remove hub, config, Apache file, Procfile line

Options: -Version 6.9.7  -Laragon C:\laragon  -Port 8000 (browser; API and
admin on Port+1)  -Origins https://192.168.10.157,https://localhost (first
run only; default: https://localhost and every IPv4 address of this machine).
Running it again keeps config and secrets; binary, Apache file and the
Procfile line are renewed, the hub is restarted.
#>
param(
	[string]$Version = '6.9.7',
	[string]$Laragon = 'C:\laragon',
	[int]$Port = 8000,
	[string[]]$Origins = @(),
	[switch]$Print,
	[switch]$Uninstall
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'   # Invoke-WebRequest is slow with the progress bar

$binDir = Join-Path $Laragon 'bin\centrifugo'
$exe = Join-Path $binDir 'centrifugo.exe'
$confDir = Join-Path $Laragon 'etc\centrifugo'
$conf = Join-Path $confDir 'config.json'
$alias = Join-Path $Laragon 'etc\apache2\alias\centrifugo.conf'
$procfile = Join-Path $Laragon 'usr\Procfile'

function Say($text) { Write-Host ''; Write-Host "=== $text" }

# Centrifugo fails on a BOM, PowerShell 5.1 writes one with -Encoding utf8
function Write-NoBom($path, $text) {
	[IO.File]::WriteAllText($path, $text, (New-Object Text.UTF8Encoding $false))
}

function New-Secret([int]$bytes) {
	$b = New-Object byte[] $bytes
	[Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
	return -join ($b | ForEach-Object { $_.ToString('x2') })
}

function Show-Block {
	$c = Get-Content $conf -Raw | ConvertFrom-Json
	Say "relay block for <project>/config/DEV.<machine>.config.json"
	Write-Host @"
	"relay": {
		"//": "Centrifugo on this machine: $($conf.Replace('\', '/')) (secret = client.token.hmac_secret_key, apiKey = http_api.key)",
		"api": "http://127.0.0.1:$($c.http_server.internal_port)",
		"apiKey": "$($c.http_api.key)",
		"secret": "$($c.client.token.hmac_secret_key)",
		"client": "/connection/",
		"plainTopics": true
	}
"@
	Write-Host ''
	Write-Host "Admin UI: http://127.0.0.1:$($c.http_server.internal_port)  (password: admin.password in $conf)"
}

if ($Print) {
	if (-not (Test-Path $conf)) { throw "$conf missing - install first" }
	Show-Block
	exit 0
}

if ($Uninstall) {
	Get-Process centrifugo -ErrorAction SilentlyContinue | Where-Object { $_.Path -eq $exe } | Stop-Process -Force
	Start-Sleep -Milliseconds 500
	if (Test-Path $procfile) {
		Write-NoBom $procfile (((Get-Content $procfile) | Where-Object { $_ -notmatch '^Centrifugo:' }) -join "`r`n")
	}
	Remove-Item -Force $alias -ErrorAction SilentlyContinue
	Remove-Item -Recurse -Force $binDir, $confDir -ErrorAction SilentlyContinue
	Write-Host "removed: hub, $confDir (secrets), $alias, Procfile line"
	Write-Host "left to you: reload Apache in Laragon, the relay block per project"
	exit 0
}

if (-not (Test-Path (Join-Path $Laragon 'bin\apache'))) { throw "no Laragon at $Laragon (use -Laragon)" }
$first = -not (Test-Path $conf)
$apiPort = $Port + 1
if ($first) {
	$taken = Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Where-Object { $_.LocalPort -in @($Port, $apiPort) }
	if ($taken) { throw "port $Port or $apiPort is taken - choose another with -Port" }
	if (-not $Origins) {
		$Origins = @('https://localhost') + @(Get-NetIPAddress -AddressFamily IPv4 |
			Where-Object { $_.IPAddress -ne '127.0.0.1' -and $_.IPAddress -notlike '169.254.*' } |
			ForEach-Object { "https://$($_.IPAddress)" })
	}
}

Say "Centrifugo $Version (windows_amd64)"
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$tmp = Join-Path ([IO.Path]::GetTempPath()) ("centrifugo-" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force $tmp | Out-Null
try {
	$file = "centrifugo_${Version}_windows_amd64.zip"
	$url = "https://github.com/centrifugal/centrifugo/releases/download/v$Version"
	Invoke-WebRequest -UseBasicParsing -Uri "$url/$file" -OutFile (Join-Path $tmp $file)
	Invoke-WebRequest -UseBasicParsing -Uri "$url/centrifugo_${Version}_checksums.txt" -OutFile (Join-Path $tmp 'checksums.txt')
	$line = Get-Content (Join-Path $tmp 'checksums.txt') | Where-Object { $_ -match "\s$([regex]::Escape($file))$" }
	if (-not $line) { throw "$file not in checksums.txt" }
	$expected = ($line -split '\s+')[0].ToLower()
	$actual = (Get-FileHash (Join-Path $tmp $file) -Algorithm SHA256).Hash.ToLower()
	if ($expected -ne $actual) { throw "checksum mismatch: $actual, expected $expected" }
	Write-Host "checksum OK"

	# the running hub locks its exe
	Get-Process centrifugo -ErrorAction SilentlyContinue | Where-Object { $_.Path -eq $exe } | Stop-Process -Force
	Start-Sleep -Milliseconds 500
	Expand-Archive (Join-Path $tmp $file) -DestinationPath (Join-Path $tmp 'x') -Force
	New-Item -ItemType Directory -Force $binDir | Out-Null
	Copy-Item (Join-Path $tmp 'x\centrifugo.exe') $exe -Force
} finally {
	Remove-Item -Recurse -Force $tmp -ErrorAction SilentlyContinue
}
& $exe version

Say "config"
New-Item -ItemType Directory -Force $confDir | Out-Null
if ($first) {
	$originsJson = ($Origins | ForEach-Object { '"' + $_ + '"' }) -join ', '
	Write-NoBom $conf @"
{
	"http_server": {
		"address": "127.0.0.1",
		"port": $Port,
		"internal_address": "127.0.0.1",
		"internal_port": "$apiPort"
	},
	"log": { "level": "info" },
	"health": { "enabled": true },
	"admin": {
		"enabled": true,
		"password": "$(New-Secret 12)",
		"secret": "$(New-Secret 32)"
	},
	"client": {
		"allowed_origins": [$originsJson],
		"token": { "hmac_secret_key": "$(New-Secret 32)" }
	},
	"http_api": { "key": "$(New-Secret 24)" },
	"channel": {
		"namespaces": [
			{ "name": "data", "allow_subscribe_for_client": true, "allow_subscribe_for_anonymous": true },
			{ "name": "user", "allow_user_limited_channels": true, "allow_subscribe_for_client": true },
			{ "name": "device", "allow_subscribe_for_client": true },
			{ "name": "debug" }
		]
	}
}
"@
	Write-Host "written: $conf (new secrets, origins: $($Origins -join ', '))"
} else {
	Write-Host "kept: $conf (secrets unchanged)"
}
& $exe checkconfig -c $conf
if ($LASTEXITCODE -ne 0) { throw "checkconfig failed" }
$c = Get-Content $conf -Raw | ConvertFrom-Json
$clientPort = $c.http_server.port
$apiPort = $c.http_server.internal_port

Say "Apache proxy /connection/"
$httpdConf = Get-ChildItem (Join-Path $Laragon 'bin\apache\*\conf\httpd.conf') | Select-Object -Last 1
foreach ($m in 'proxy_module', 'proxy_http_module', 'proxy_wstunnel_module') {
	if (-not (Select-String -Path $httpdConf.FullName -Pattern "^\s*LoadModule\s+$m\s" -Quiet)) {
		Write-Warning "$m is not loaded in $($httpdConf.FullName)"
	}
}
Write-NoBom $alias (@(
	"# systopic/relay - the browser's socket to the local Centrifugo hub"
	"# ($($conf.Replace('\', '/'))). With a slash: our urls without one"
	"# are actions; the hub itself serves /connection/websocket. Order matters."
	"ProxyPass /connection/websocket/ http://127.0.0.1:$clientPort/connection/websocket upgrade=websocket"
	"ProxyPass /connection/ http://127.0.0.1:$clientPort/connection/ upgrade=websocket flushpackets=on"
	""
) -join "`r`n")
Write-Host "written: $alias"

Say "Laragon autostart"
$line = "Centrifugo: autorun $exe -c $conf"
$lines = @()
if (Test-Path $procfile) { $lines = @(Get-Content $procfile | Where-Object { $_ -notmatch '^Centrifugo:' }) }
while ($lines.Count -and $lines[-1] -eq '') { $lines = $lines[0..($lines.Count - 2)] }
Write-NoBom $procfile ((@($lines) + '' + $line + '') -join "`r`n")
Write-Host "Procfile: $line"

Say "start and check"
Start-Process -FilePath $exe -ArgumentList '-c', $conf -WorkingDirectory $binDir -WindowStyle Hidden
$health = $null
for ($i = 0; $i -lt 20 -and -not $health; $i++) {
	Start-Sleep -Milliseconds 250
	try { $health = Invoke-WebRequest -UseBasicParsing "http://127.0.0.1:$apiPort/health" } catch {}
}
if (-not $health) { throw "hub does not answer on 127.0.0.1:$apiPort" }
Write-Host "health:  $($health.Content)"
$pub = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "http://127.0.0.1:$apiPort/api/publish" `
	-Headers @{ 'X-API-Key' = $c.http_api.key } -Body '{"channel":"data:test/x","data":{}}'
Write-Host "publish: $($pub.Content)"
try {
	$r = Invoke-WebRequest -UseBasicParsing "http://127.0.0.1:$clientPort/connection/websocket"
	$code = $r.StatusCode
} catch { $code = [int]$_.Exception.Response.StatusCode }
Write-Host "socket:  $code (400 = the hub answers, only without upgrade)"

Show-Block
Say "done - next: reload Apache in Laragon (Menu > Apache > Reload), then the relay block per project"
