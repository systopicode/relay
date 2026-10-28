<?php

declare(strict_types=1);

namespace Systopic\Relay\Panels\Root\Cms\Tools\Debug;

use Systopic\Relay\ClientData;
use Systopic\Relay\Relay;
use Systopic\Relay\RemoteDebug;
use Systopic\Relay\Token;
use Systopic\System\Auth\Session;
use Systopic\System\Panels\PanelNode;
use Systopic\System\Sys\Packages;

/**
 * tools/debug - the browsers seen lately, and a window per browser that shows
 * its debug output live (Systopic\Relay\RemoteDebug).
 *
 * The window is no panel: `?debugWatch=<client>` answers with a page of its
 * own - the debug tool's scripts and styles and a connection to the hub - and
 * so do its pings and its goodbye. All three end the request before the CMS
 * renders anything. So does `?debugSignal=<session>&op=&n=`: what the window
 * tells its browser - it listens now ('watch'), it hovers a node of the client
 * layer tree ('show', 'hide').
 */
class Panel extends PanelNode
{
    public $icon = 'fa-regular fa-bug';

    /**
     * How the window lays out the prompts (tools/debug js/layout.js): columns
     * with w %, rows with h %, without either they share the rest. A row's
     * tabs: prompt name => label. A prompt not named here lands in the last row.
     */
    public const LAYOUT = [
        ['w' => 60, 'rows' => [
            ['h' => 20, 'tabs' => ['time' => 'time', 'route' => 'route']],
            ['h' => 50, 'tabs' => ['domLayersServer' => 'serverRender','panelTreeDebug' => 'panelTreeDebug']],
            ['tabs' => ['default' => 'php']],
        ]],
        ['rows' => [
            ['h' => 20, 'tabs' => ['js' => 'js']],
            ['h' => 50, 'tabs' => ['domLayersClient' => 'domLayersClient']],
            ['tabs' => ['ajax' => 'ajax']],
        ]],
    ];

    public function buildGranted()
    {
        return Session::isSuperuser() && RemoteDebug::available();
    }

    public function onLoadSelected(): void
    {
        $ping = (string) \http::get(RemoteDebug::PING, '');
        if ($ping !== '') {
            $this->answer(['alive' => RemoteDebug::extend($ping)]);
        }
        $signal = (string) \http::get(RemoteDebug::SIGNAL, '');
        if ($signal !== '') {
            $this->answer(['alive' => RemoteDebug::signal($signal, (string) \http::get('op', ''), (int) \http::get('n', -1))]);
        }
        $close = (string) \http::get(RemoteDebug::CLOSE, '');
        if ($close !== '') {
            RemoteDebug::close($close);
            $this->answer(['closed' => true]);
        }
        $watch = (string) \http::get(RemoteDebug::WATCH, '');
        if ($watch !== '') {
            $this->window($watch);
        }
    }

    /** @return list<array<string, mixed>> */
    public function clients(): array
    {
        return RemoteDebug::clients();
    }

    /** the window of a browser on the list */
    public function windowHref(string $client): string
    {
        return $this->href . '?' . RemoteDebug::WATCH . '=' . rawurlencode($client);
    }

    /**
     * The page of the window. The debug tool sets itself into it as into any
     * page (scripts, styles, its own windows) - it is made here if no p() has
     * made it yet, before anything is printed, so its buffer takes the page.
     */
    private function window(string $client): never
    {
        $known = RemoteDebug::client($client);
        $debug = \systopic\debug\sysdebug::$debug ??= new \systopic\debug\sysdebug();
        $debug->http || $debug->setRoot();
        // this request's own output (the time marks of the CMS) would mix with
        // the watched browser's in the same windows
        $debug->prompts = [];
        $session = $known === null ? null : RemoteDebug::open($client);
        $subject = ClientData::subject();
        $ttl = (int) Relay::config('tokenTtl', 43200);
        $secret = (string) Relay::config('secret');
        $window = [
            'client'  => $client,
            'label'   => $known === null ? 'unknown browser' : RemoteDebug::label((string) $known['ua']),
            'ip'      => $known['ip'] ?? '',
            'ws'      => (string) Relay::config('client', '/connection/') . 'websocket/',
            'token'   => Token::connection($subject, $ttl, $secret),
            'channel' => $session === null ? '' : RemoteDebug::channel($session),
            'subToken' => $session === null ? '' : Token::subscription($subject, RemoteDebug::channel($session), $ttl, $secret),
            'ping'    => $session === null ? '' : $this->href . '?' . RemoteDebug::PING . '=' . $session,
            'close'   => $session === null ? '' : $this->href . '?' . RemoteDebug::CLOSE . '=' . $session,
            'signal'  => $session === null ? '' : $this->href . '?' . RemoteDebug::SIGNAL . '=' . $session,
            'pingSeconds' => 30,
            'layout'  => self::LAYOUT,
        ];
        $centrifuge = (Packages::urls()['systopic/relay'] ?? '') . 'public/js/centrifuge.js';
        include __DIR__ . '/window.tpl.php';
        exit;
    }

    /** a small JSON answer for the window's own calls - without the debug tool's block in it */
    private function answer(array $data): never
    {
        \systopic\debug\sysdebug::$debug?->erase(true);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data);
        exit;
    }
}
