<?php

declare(strict_types=1);

namespace Systopic\Relay;

use Systopic\System\Auth\Devices;
use Systopic\System\Auth\Session;
use Systopic\System\Dom\Dependencies;

/**
 * What the browser needs to listen (`relay.js`, `sys.relay`): where the hub is,
 * a token, the channels of what it shows. With every response — the full
 * page's head and every ajax answer — so a navigation brings its channels,
 * and `sys.relay.sync` runs after each to subscribe and unsubscribe.
 */
final class ClientData
{
    /** relay.js (and the hub's client library) and its data, on every request. */
    public static function register(): void
    {
        \client::loadJS('systopic/relay', 'public/js/centrifuge.js', 'class', []);
        \client::loadJS('systopic/relay', 'src/relay.js', 'class', ['relay']);
        \clientInterfaces::$clientDataClasses[] = self::class;
    }

    public static function clientData_export(): object
    {
        $data = [
            'client' => (string) Relay::config('client', '/connection/'),
            'token'  => Token::connection(self::subject(), (int) Relay::config('tokenTtl', 43200), (string) Relay::config('secret')),
        ];
        // The device this window belongs to: told "logout" when it is revoked
        // (Devices::revoke) - its pages go to the login at once.
        $device = class_exists(Devices::class) ? Devices::current() : null;
        if ($device !== null) {
            $data['device'] = Relay::deviceTopic((int) $device->id);
            $data['active'] = Relay::activeTopic((int) $device->id);
        }
        // A window watches this browser's debug output (RemoteDebug): hovering
        // its client layer tree outlines the layers here.
        $watched = RemoteDebug::streamed();
        if ($watched !== null) {
            $channel = RemoteDebug::markChannel($watched);
            $data['debugMarks'] = [
                'channel' => $channel,
                'token' => Token::subscription(self::subject(), $channel, (int) Relay::config('tokenTtl', 43200), (string) Relay::config('secret')),
            ];
        }
        // Only after a render: a PUT that ran an action without one would
        // otherwise hand the browser an empty list and end every subscription.
        if (Dependencies::rendered()) {
            $data['channels'] = array_values(array_filter(Dependencies::all(), static fn(string $key) => str_starts_with($key, 'data:')));
        }
        return (object) [
            'route' => 'singletons.relay',
            'data' => $data,
            'onLibReady' => ['sys.relay.sync'],
        ];
    }

    /** The user as the hub knows them: always with the project, set here, never by the browser. */
    public static function subject(): string
    {
        $id = Session::id();
        return $id === null ? '' : Relay::prefix() . ':' . $id;
    }
}
