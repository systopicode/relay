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
        if (self::$changed === []) {
            register_shutdown_function([self::class, 'flush']);
        }
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
        if (self::$changed === [] || self::$publisher === null) {
            return;
        }
        $channels = array_keys(self::$changed);
        self::$changed = [];
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        try {
            self::$publisher->broadcast($channels, ['op' => 'change'] + self::origin());
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
