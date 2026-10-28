<!DOCTYPE html>
<html lang="en">
<head>
<?php
/**
 * The window of one watched browser: the debug tool's own windows (default,
 * ajax, route, time), filled from the hub instead of from this request.
 * Every request of the watched browser starts with a line in `default`
 * (method, url) and ends with one (duration, status, the fatal error) - a
 * start without an end after a minute was killed.
 *
 * @var \Systopic\Relay\Panels\Root\Cms\Tools\Debug\Panel $this
 * @var array<string, mixed> $window
 * @var string $centrifuge
 */
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES);
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>debug · <?= $e($window['label']) ?></title>
<style>
	body { margin: 0; background: #f4f4f6; color: #333; font: 13px/1.5 system-ui, sans-serif; }
	header.remote { position: fixed; left: 50%; bottom: 0; transform: translateX(-50%); padding: 2px 12px;
		background: #fff; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; white-space: nowrap; }
	header.remote .state { color: #0a8f6c; }
	header.remote .state.bad { color: #b00020; }
</style>
<script src="<?= $e($centrifuge) ?>"></script>
</head>
<body>
<header class="remote">
	watching <strong><?= $e($window['label']) ?></strong> <?= $e($window['ip']) ?>
	· <span class="state" id="remoteState">connecting</span>
</header>
<script type="application/json" id="remoteWindow"><?= json_encode($window, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script>
(function () {
	var cfg = JSON.parse(document.getElementById('remoteWindow').textContent);
	var stateNode = document.getElementById('remoteState');
	var say = (text, bad) => {
		stateNode.textContent = text;
		stateNode.className = 'state' + (bad ? ' bad' : '');
	};
	if (!cfg.channel) {
		say('this browser is not on the list (any more) - open the window from cms/tools/debug again', true);
		return;
	}
	if (typeof Centrifuge === 'undefined' || typeof sysdebug === 'undefined') {
		say(typeof sysdebug === 'undefined' ? 'the debug tool is not loaded here' : 'centrifuge.js did not load', true);
		return;
	}
	var lib = sysdebug.export()[2];
	var config = null;
	var open = {};   // request id => timer that reports a missing end

	/** a line of our own in a prompt - the way the tool shows a js value */
	var line = (prompt, text) => sysdebug.getPrompt(prompt).update({events: [{values: lib.event.parseValues([text])}]});

	var handle = message => {
		switch (message.op) {
			case 'start':
				config = message.config || config;
				line('default', '▶ ' + message.method + ' ' + message.url + (message.ajax ? '  (ajax)' : '') + '  #' + message.req);
				open[message.req] = setTimeout(() => {
					line('default', '⚠ #' + message.req + ' ' + message.url + ' - no end after 60 s: killed?');
					delete open[message.req];
				}, 60000);
				break;
			case 'events':
				sysdebug.update({config: config || {}, prompts: message.prompts || []});
				break;
			case 'note':
				line('default', '⚠ #' + message.req + ' ' + message.text);
				break;
			case 'end':
				clearTimeout(open[message.req]);
				delete open[message.req];
				line('default', '■ #' + message.req + ' ' + message.ms + ' ms' + (message.status ? ', ' + message.status : '')
					+ (message.error ? '  — ' + message.error : ''));
				break;
		}
	};

	/**
	 * Hovering the client layer tree outlines the layer in the watched page
	 * (boxTreePrompt.js calls this for a node with a mark). One call at a
	 * time and only the latest wish: leaving one header and entering the next
	 * sends just the show, and a hide can never overtake it.
	 */
	var markWanted = null, markSent = 'hide', markBusy = false;
	var sendMark = () => {
		var wish = markWanted;
		if (markBusy || wish === markSent) {
			return;
		}
		markBusy = true;
		markSent = wish;
		var op = wish === 'hide' ? 'hide' : 'show';
		fetch(cfg.signal + '&op=' + op + '&n=' + (op === 'show' ? wish : -1), {credentials: 'same-origin', cache: 'no-store'})
			.catch(() => say('server not reachable', true))
			.finally(() => {
				markBusy = false;
				sendMark();
			});
	};
	sysdebug.onRemoteMark = (op, mark) => {
		markWanted = op === 'show' ? mark : 'hide';
		setTimeout(sendMark, 0);   // a hide right before a show is dropped
	};

	var url = (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + cfg.ws;
	var client = new Centrifuge(url, {token: cfg.token});
	client.on('disconnected', ctx => say('disconnected: ' + ctx.reason, true));
	client.on('error', ctx => say('hub error: ' + (ctx.error && ctx.error.message), true));
	var sub = client.newSubscription(cfg.channel, {token: cfg.subToken});
	// listening now: an open page of that browser hands over the prompts it
	// shows (and takes them back when this window closes) - also after a
	// reconnect, then it sends everything again
	sub.on('subscribed', () => {
		say('waiting for its next request');
		fetch(cfg.signal + '&op=watch', {credentials: 'same-origin', cache: 'no-store'})
			.catch(() => say('server not reachable', true));
	});
	sub.on('error', ctx => say('subscription error: ' + (ctx.error && ctx.error.message), true));
	sub.on('publication', ctx => {
		say('live');
		handle(ctx.data || {});
	});
	sub.subscribe();
	client.connect();

	// the session lives as long as this window says so - closing it ends the session at once
	setInterval(() => {
		fetch(cfg.ping, {credentials: 'same-origin', cache: 'no-store'})
			.then(r => r.json())
			.then(a => a.alive || say('session ended - open the window from cms/tools/debug again', true))
			.catch(() => say('server not reachable', true));
	}, cfg.pingSeconds * 1000);
	addEventListener('pagehide', () => navigator.sendBeacon(cfg.close));
})();
</script>
</body>
</html>
