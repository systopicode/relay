# systopic/relay

Live updates for the systopic packages: what a page rendered from, what a
request wrote, signalled through a hub ([Centrifugo](https://centrifugal.dev))
to the open browsers, which re-render just the layers that read the changed
data. No data travels through the hub, only the names of what changed.

```bash
composer require systopic/relay
```

Requires PHP 8.4, `ext-curl`, [systopic/system](https://github.com/systopicode/system)
and [systopic/db](https://github.com/systopicode/db). Without a `relay` block in the
instance config the package does nothing.

Installing the hub on Ubuntu/Plesk, Windows/Laragon or macOS: [bin/README.md](bin/README.md).

MIT License, © systopic GmbH.
