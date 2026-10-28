<?php

declare(strict_types=1);

namespace Systopic\Relay;

use Systopic\Db\Scope;
use Systopic\Relay\Publisher\Centrifugo;
use Systopic\Relay\Publisher\Publisher;
use Systopic\System\Config\Config;
use Systopic\System\Dom\Dependencies;

/**
 * The relay of this request: is it on, what are the channels called, what
 * goes out at the end. Like a switchboard it decides who is connected to what
 * and passes signals on — it never changes or carries the content.
 *
 * On only with a `relay` block in the instance config (on STAGING and LIVE the
 * secrets in config.local.json):
 *
 *   "relay": {
 *     "api":         "http://127.0.0.1:8001",  the hub's API, internal port
 *     "apiKey":      "…",                      the hub's http_api.key
 *     "secret":      "…",                      the hub's client.token.hmac_secret_key
 *     "client":      "/connection/",           where the browser reaches the hub, same host
 *     "prefix":      "spaceticker",            optional, see prefix()
 *     "plainTopics": false,                    DEV: readable channel names
 *     "tokenTtl":    43200                     seconds
 *   }
 *
 * Without it nothing is registered: no observer, no script, no publish.
 */
final class Relay
{
    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    private static ?Publisher $publisher = null;

    /** @var array<string, true> channels written to in this request */
    private static array $changed = [];

    /** @var array<string, string> table => channel */
    private static array $topics = [];

    /** @var array<string, array<string, mixed>> channel => message, sent at the end like the changes */
    private static array $messages = [];

    /** @var array<string, true>|null channels with a subscriber, once this request has asked */
    private static ?array $openChannels = null;

    private static bool $openChannelsAsked = false;

    private static bool $flushRegistered = false;

    /** afterConnect (autoload.php): the config is read, the database is there. */
    public static function boot(): void
    {
        $config = Config::current()?->instance->get('relay');
        if (!is_array($config) || ($config['api'] ?? '') === '' || ($config['secret'] ?? '') === '') {
            return;
        }
        self::$config = $config;
        self::$publisher = new Centrifugo((string) $config['api'], (string) ($config['apiKey'] ?? ''));
        Scope::addObserver(new Observer());
        ClientData::register();
        RemoteDebug::boot();
    }

    public static function enabled(): bool
    {
        return self::$config !== null;
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        return self::$config[$key] ?? $default;
    }

    /**
     * The project's part of every channel name: from the config, else the
     * project namespace — plus the stage unless LIVE, so a STAGING and a LIVE
     * on one hub never hear each other (same ids, different databases).
     */
    public static function prefix(): string
    {
        $prefix = (string) (self::$config['prefix'] ?? '');
        if ($prefix !== '') {
            return $prefix;
        }
        $prefix = strtolower(defined('PROJECT_NAMESPACE') ? (string) PROJECT_NAMESPACE : basename(rtrim((string) \fs::$projectRoot, '/')));
        $stage = strtolower((string) (Config::current()?->instance->stage ?? ''));
        return $stage === '' || $stage === 'live' ? $prefix : "$prefix.$stage";
    }

    /**
     * The channel of a table. Its name is the permission: a browser learns it
     * only by being rendered something from that table. Hence an HMAC with the
     * hub's secret, not a plain hash — a list of table names would undo that
     * in seconds.
     */
    public static function topic(string $table): string
    {
        return self::$topics[$table] ??= 'data:' . self::prefix() . '/' . (
            (self::$config['plainTopics'] ?? false)
                ? $table
                : substr(hash_hmac('sha256', self::prefix() . '|' . $table, (string) self::$config['secret']), 0, 24)
        );
    }

    /**
     * Which of these devices have the page open. Null when the hub is off
     * or did not answer — last seen is not the same as online.
     *
     * @param list<int> $ids
     * @return list<int>|null
     */
    public static function presentDevices(array $ids): ?array
    {
        return self::subscribed($ids, self::deviceTopic(...));
    }

    /**
     * Which of these devices are being used: the page is visible and someone
     * touched it in the last few minutes. A hidden or idle tab stays in
     * presentDevices(). Null when the hub is off or did not answer.
     *
     * @param list<int> $ids
     * @return list<int>|null
     */
    public static function activeDevices(array $ids): ?array
    {
        return self::subscribed($ids, self::activeTopic(...));
    }

    /**
     * @param list<int> $ids
     * @param callable(int): string $topic
     * @return list<int>|null
     */
    private static function subscribed(array $ids, callable $topic): ?array
    {
        $open = self::openChannels();
        if ($open === null) {
            return null;
        }
        $found = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && isset($open[$topic($id)])) {
                $found[] = $id;
            }
        }
        return $found;
    }

    /** @return array<string, true>|null */
    private static function openChannels(): ?array
    {
        if (!self::enabled() || !(self::$publisher instanceof Centrifugo)) {
            return null;
        }
        if (!self::$openChannelsAsked) {
            self::$openChannelsAsked = true;
            try {
                self::$openChannels = self::$publisher->channels();
            } catch (\Throwable) {
                self::$openChannels = null;
            }
        }
        return self::$openChannels;
    }

    /**
     * The channel of one device (Systopic\System\Auth\Devices): what is meant
     * for exactly this browser, app or screen - today "log out" when it is
     * revoked, later the remote control of a screen. An HMAC like topic()
     * unless plainTopics: a device id is easy to guess.
     */
    public static function deviceTopic(int $deviceId): string
    {
        return 'device:' . self::prefix() . '/' . (
            (self::$config['plainTopics'] ?? false)
                ? (string) $deviceId
                : substr(hash_hmac('sha256', self::prefix() . '|device|' . $deviceId, (string) self::$config['secret']), 0, 24)
        );
    }

    /**
     * Same namespace as deviceTopic(), so the hub already allows it. The
     * browser subscribes only while the tab is visible and in use.
     */
    public static function activeTopic(int $deviceId): string
    {
        return 'device:' . self::prefix() . '/' . (
            (self::$config['plainTopics'] ?? false)
                ? 'live/' . $deviceId
                : substr(hash_hmac('sha256', self::prefix() . '|live|' . $deviceId, (string) self::$config['secret']), 0, 24)
        );
    }

    /**
     * A message for one channel - `['op' => 'logout']` to a device. Out at the
     * end of the request with the changes, never with an exception.
     *
     * @param array<string, mixed> $data
     */
    public static function tell(string $channel, array $data): void
    {
        if (self::$config === null) {
            return;
        }
        self::registerFlush();
        self::$messages[$channel] = $data;
    }

    /**
     * Straight to the hub, now - not at the end of the request like tell().
     * For what has to arrive while the request still runs (RemoteDebug).
     * Throws when the hub does not answer within the publisher's timeout.
     *
     * @param array<string, mixed> $data
     */
    public static function publishNow(string $channel, array $data): void
    {
        if (self::$publisher === null) {
            throw new \RuntimeException('relay is off');
        }
        self::$publisher->broadcast([$channel], $data);
    }

    private static function registerFlush(): void
    {
        if (!self::$flushRegistered) {
            self::$flushRegistered = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    /**
     * These tables were written to. This request's own render takes them as
     * changed (Dependencies::wrote() — the window that saved gets everything
     * the save touched in its answer), the hub hears of them at the end, for
     * every other window.
     */
    public static function changed(string ...$tables): void
    {
        if (self::$config === null || $tables === []) {
            return;
        }
        self::registerFlush();
        $topics = [];
        foreach ($tables as $table) {
            $topics[] = $topic = self::topic($table);
            self::$changed[$topic] = true;
        }
        Dependencies::wrote(...$topics);
    }

    /** For a write past the ORM (raw SQL, a restore) — the same as a commit to these tables. */
    public static function touch(string ...$tables): void
    {
        self::changed(...$tables);
    }

    /**
     * Out to the hub, once per request, one message per channel however many
     * commits there were. After the response where the SAPI allows it; never
     * longer than the publisher's timeout and never with an exception — the
     * data is in the database either way.
     */
    public static function flush(): void
    {
        if ((self::$changed === [] && self::$messages === []) || self::$publisher === null) {
            return;
        }
        $channels = array_keys(self::$changed);
        $messages = self::$messages;
        self::$changed = [];
        self::$messages = [];
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        try {
            if ($channels !== []) {
                self::$publisher->broadcast($channels, ['op' => 'change'] + self::origin());
            }
            foreach ($messages as $channel => $data) {
                self::$publisher->broadcast([$channel], $data);
            }
        } catch (\Throwable $e) {
            error_log('systopic/relay: ' . $e->getMessage());
        }
    }

    /**
     * The window the request came from (header `X-Relay-Origin`, set by
     * relay.js). It has what the write changed already — its own answer —
     * and skips the signal; every other window takes it. A write without a
     * window (CLI, cron) has no origin and reaches everybody.
     *
     * @return array{origin?: string}
     */
    private static function origin(): array
    {
        $origin = substr((string) preg_replace('~[^A-Za-z0-9-]~', '', (string) ($_SERVER['HTTP_X_RELAY_ORIGIN'] ?? '')), 0, 64);
        return $origin === '' ? [] : ['origin' => $origin];
    }
}
