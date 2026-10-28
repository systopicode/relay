<?php

declare(strict_types=1);

namespace Systopic\Relay;

use Systopic\System\Auth\Session;

/**
 * The debug output of one browser, live in a window on another machine - a
 * phone's requests read at the desk (packages/tools/.cursor/docs/debug-remote.md).
 *
 * Every browser on a stage with debug on gets an id of its own (cookie
 * `sysdebug_client`, before any login, also on the screen page) and lands in
 * the list of `cms/tools/debug`, which opens a window for it. That window is a
 * session: while it lives, every request of that browser streams its debug
 * output (systopic\debug\stream) to the session's channel instead of into its
 * page, and the window shows it. The page's own scripts print there too: their
 * prompts come back as a POST to the page's url (?sysdebugPush), which boot()
 * answers before anything renders - the js prompt and the client layer tree.
 * Hovering that tree in the window outlines the layer in the watched page:
 * the window posts the node's mark to the panel, the page hears it on
 * markChannel().
 *
 * The channel `debug:<prefix>/<session>` is in a namespace clients cannot
 * subscribe to on their own: the window gets a subscription token, and only
 * a superuser gets the window. The session id is random; the list and the
 * sessions live in `var/debug/remote.json`.
 */
final class RemoteDebug
{
    public const COOKIE = 'sysdebug_client';

    /** seconds a session lives without a sign of its window - it pings every 30 */
    public const SESSION_TTL = 120;

    /** the panel lists who was seen this recently */
    public const LIST_SECONDS = 1800;

    /** who has not been seen for this long is forgotten */
    private const CLIENT_TTL = 86400;

    /** a request writes the list only when its entry is older - not on every ajax call */
    private const TOUCH_GAP = 5;

    /** the query parameters of the window (cms/tools/debug) - its own requests are never streamed */
    public const WATCH = 'debugWatch';
    public const PING = 'debugPing';
    public const CLOSE = 'debugClose';
    public const MARK = 'debugMark';

    /** the session this request streams to - NULL when none watches it */
    private static ?string $streamed = null;

    /** Every request: who this is, and is a window watching? */
    public static function boot(): void
    {
        if (PHP_SAPI === 'cli' || !self::available()) {
            return;
        }
        // what the scripts of a watched page printed (tools/debug js/remote.js) -
        // never a page, and not a visit: the list keeps the page's url
        if (isset($_GET[\systopic\debug\stream::PUSH])) {
            $session = self::session(self::read(), self::clientId());
            $channel = $session === null ? null : self::channel($session);
            \systopic\debug\sysdebug::streamPush($channel === null ? null : static fn(array $message) => Relay::publishNow($channel, $message));
        }
        $session = self::touch(self::clientId());
        if ($session !== null && !isset($_GET[self::WATCH]) && !isset($_GET[self::PING]) && !isset($_GET[self::CLOSE]) && !isset($_GET[self::MARK])) {
            self::$streamed = $session;
            $channel = self::channel($session);
            \systopic\debug\sysdebug::stream(static fn(array $message) => Relay::publishNow($channel, $message));
        }
    }

    /** the session this request streams to (ClientData hands its page the marks channel) */
    public static function streamed(): ?string
    {
        return self::$streamed;
    }

    /** The hub is there and the debug tool is loaded, on and new enough to stream, page scripts included (since 2026-10-28). */
    public static function available(): bool
    {
        return Relay::enabled()
            && class_exists(\systopic\debug\sysdebug::class, false)
            && method_exists(\systopic\debug\sysdebug::class, 'streamPush')
            && function_exists('DEBUG') && DEBUG();
    }

    /** This browser's id - made and set as a cookie the first time. */
    public static function clientId(): string
    {
        $id = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (preg_match('~^[0-9a-f]{32}$~', $id) !== 1) {
            $id = bin2hex(random_bytes(16));
            if (!headers_sent()) {
                setcookie(self::COOKIE, $id, [
                    'expires'  => time() + 365 * 86400,
                    'path'     => '/',
                    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            $_COOKIE[self::COOKIE] = $id;
        }
        return $id;
    }

    public static function channel(string $session): string
    {
        return 'debug:' . Relay::prefix() . '/' . $session;
    }

    /**
     * The way back: the window hovers a node of the client layer tree, the
     * watched page outlines that layer (tools/debug js/remote.js). Apart from
     * the session's channel - the watched page may read this one, never the
     * debug output of its requests.
     */
    public static function markChannel(string $session): string
    {
        return self::channel($session) . '/marks';
    }

    /** The window hovers mark $mark ('show') or leaves it ('hide'). FALSE when the session is gone. */
    public static function mark(string $session, string $op, int $mark): bool
    {
        $entry = self::read()['sessions'][$session] ?? null;
        if ($entry === null || (int) ($entry['expires'] ?? 0) < time() || !in_array($op, ['show', 'hide'], true)) {
            return false;
        }
        Relay::publishNow(self::markChannel($session), ['op' => $op, 'mark' => $mark]);
        return true;
    }

    // ------------------------------------------------------------- sessions

    /** A window for $client: a new session, the one before it (if any) ends. */
    public static function open(string $client): string
    {
        $session = bin2hex(random_bytes(16));
        self::update(static function (array &$data) use ($client, $session): void {
            foreach ($data['sessions'] as $id => $entry) {
                if (($entry['client'] ?? '') === $client) {
                    unset($data['sessions'][$id]);
                }
            }
            $data['sessions'][$session] = [
                'client'  => $client,
                'by'      => Session::id(),
                'expires' => time() + self::SESSION_TTL,
            ];
        });
        return $session;
    }

    /** The window is still there. FALSE when the session is gone - the window has to be opened again. */
    public static function extend(string $session): bool
    {
        $alive = false;
        self::update(static function (array &$data) use ($session, &$alive): void {
            if (isset($data['sessions'][$session]) && (int) $data['sessions'][$session]['expires'] >= time()) {
                $data['sessions'][$session]['expires'] = time() + self::SESSION_TTL;
                $alive = true;
            }
        });
        return $alive;
    }

    /** The window closed: the browser shows its output in its own page again. */
    public static function close(string $session): void
    {
        self::update(static function (array &$data) use ($session): void {
            unset($data['sessions'][$session]);
        });
    }

    // ----------------------------------------------------------------- list

    /**
     * Who was seen lately, the newest first - with the session watching it.
     *
     * @return list<array{id: string, seen: int, ua: string, ip: string, url: string, user: ?int, session: ?string}>
     */
    public static function clients(): array
    {
        $data = self::read();
        $now = time();
        $watched = [];
        foreach ($data['sessions'] as $id => $entry) {
            if ((int) ($entry['expires'] ?? 0) >= $now) {
                $watched[(string) ($entry['client'] ?? '')] = (string) $id;
            }
        }
        $list = [];
        foreach ($data['clients'] as $id => $entry) {
            if ($now - (int) ($entry['seen'] ?? 0) <= self::LIST_SECONDS) {
                $list[] = ['id' => (string) $id] + $entry + ['session' => $watched[$id] ?? null];
            }
        }
        usort($list, static fn(array $a, array $b) => (int) $b['seen'] <=> (int) $a['seen']);
        return $list;
    }

    public static function client(string $id): ?array
    {
        $entry = self::read()['clients'][$id] ?? null;
        return $entry === null ? null : ['id' => $id] + $entry;
    }

    /** 'iPhone · Safari', 'Windows · Chrome' - enough to tell the devices on the list apart. */
    public static function label(string $ua): string
    {
        $device = match (true) {
            str_contains($ua, 'iPhone')     => 'iPhone',
            str_contains($ua, 'iPad')       => 'iPad',
            str_contains($ua, 'Android')    => 'Android',
            str_contains($ua, 'Windows')    => 'Windows',
            str_contains($ua, 'Macintosh')  => 'Mac',
            str_contains($ua, 'Linux')      => 'Linux',
            default                         => '?',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/')       => 'Edge',
            str_contains($ua, 'CriOS'), str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'FxiOS'), str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/')    => 'Safari',
            default                         => '?',
        };
        return "$device · $browser";
    }

    // ----------------------------------------------------------------- file

    /**
     * This browser in the list, when its entry is older than TOUCH_GAP, and
     * the session watching it - NULL when none is.
     */
    private static function touch(string $client): ?string
    {
        $now = time();
        $data = self::read();
        if ($now - (int) ($data['clients'][$client]['seen'] ?? 0) >= self::TOUCH_GAP) {
            $data = self::update(static function (array &$data) use ($client, $now): void {
                $data['clients'][$client] = [
                    'seen' => $now,
                    'ua'   => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
                    'ip'   => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                    'url'  => substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 300),
                    'user' => Session::id(),
                ];
                foreach ($data['clients'] as $id => $entry) {
                    if ($now - (int) ($entry['seen'] ?? 0) > self::CLIENT_TTL) {
                        unset($data['clients'][$id]);
                    }
                }
                foreach ($data['sessions'] as $id => $entry) {
                    if ((int) ($entry['expires'] ?? 0) < $now) {
                        unset($data['sessions'][$id]);
                    }
                }
            });
        }
        return self::session($data, $client);
    }

    /** the session watching $client, NULL when none is */
    private static function session(array $data, string $client): ?string
    {
        foreach ($data['sessions'] as $id => $entry) {
            if (($entry['client'] ?? '') === $client && (int) ($entry['expires'] ?? 0) >= time()) {
                return (string) $id;
            }
        }
        return null;
    }

    private static function file(): string
    {
        return \fs::toNative((string) \fs::$projectRoot . 'var/debug/remote.json');
    }

    /** @return array{clients: array<string, array<string, mixed>>, sessions: array<string, array<string, mixed>>} */
    private static function read(): array
    {
        $data = json_decode((string) @file_get_contents(self::file()), true);
        return self::shape($data);
    }

    /**
     * Read, change, write - under a lock, so two requests of one page do not
     * write over each other.
     *
     * @param callable(array<string, mixed>&): void $change
     * @return array{clients: array<string, array<string, mixed>>, sessions: array<string, array<string, mixed>>}
     */
    private static function update(callable $change): array
    {
        $file = self::file();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            $data = self::read();
            $change($data);
            return $data;
        }
        flock($handle, LOCK_EX);
        $data = self::shape(json_decode((string) stream_get_contents($handle), true));
        $change($data);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        return $data;
    }

    /** @return array{clients: array<string, array<string, mixed>>, sessions: array<string, array<string, mixed>>} */
    private static function shape(mixed $data): array
    {
        $data = is_array($data) ? $data : [];
        return [
            'clients'  => is_array($data['clients'] ?? null) ? $data['clients'] : [],
            'sessions' => is_array($data['sessions'] ?? null) ? $data['sessions'] : [],
        ];
    }
}
