/* global sys,p,Centrifuge,crypto */
/*
 * sys.relay - live updates (systopic/relay).
 *
 * The server hands over, with every response (Relay\ClientData): where the hub
 * is, a token, the channels of what this window shows. sync() runs after each
 * response and subscribes to exactly those. A message on one of them means
 * "data under this window changed". The channels wait for the next request
 * this window sends anyway and go along with it (header `X-Changed`); when
 * none comes within a moment, refresh() sends a PUT to the url on screen. The
 * server re-renders the layers that read from them - in the same render as
 * whatever else the request asked for - and the diff brings just those
 * (Dom\Dependencies). No data travels through the hub.
 *
 * Signals of this window's own writes are skipped: the answer to the write
 * already re-rendered what it touched (origin, see below). The focused
 * element stays in the document through any rebuild (system:
 * public/js/core/focus.js).
 *
 * The socket is /connection/websocket/ - with the slash, since our urls
 * without one are actions; the web server maps it to the hub's path.
 *
 * Everything that happens goes to p() (the debug tool, else the console):
 * connected, every subscription, every message with its content, every
 * refresh with what it asked for.
 */
(function () {
	var setup = {
		info: 'relay singleton - live updates through the hub',
		route: 'singletons.relay',
		shorthand: 'relay'
	};
	sys.lib.add(setup, function () {
		var relay = this;
		var client = null;
		var subs = new Map();      // channel => subscription
		var pending = new Set();   // channels heard of, not yet asked for
		var timer = null;
		var waitingVisible = false;
		var GATHER_MS = 300;

		/**
		 * This window. Every request says it comes from here; the server puts
		 * it into the signals of what the request wrote. The window that saved
		 * has the result in the answer to its save (the server renders what
		 * the save touched), so it skips its own signal - otherwise it would
		 * render the same thing a second time.
		 */
		relay.origin = (window.crypto && crypto.randomUUID) ? crypto.randomUUID()
			: Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);

		/**
		 * Whatever request goes out next carries what changed (header
		 * `X-Changed`): a click, a save, a navigation - the server renders it
		 * in the same go. Only when nothing goes out does refresh() ask on its
		 * own. So several signals and a request of the user end up in ONE render.
		 */
		sys.core.server.addHeaders(method => {
			var headers = {'X-Relay-Origin': relay.origin};
			if (pending.size) {
				headers['X-Changed'] = Array.from(pending).join(',');
				p('relay: changes go along with the ' + method, Array.from(pending));
				pending.clear();
			}
			return headers;
		});

		var data = () => (sys.singletons.relay && sys.singletons.relay.__data) || {};

		/** After every response: connect once, then follow the channel list. */
		relay.sync = () => {
			var d = data();
			if (!d.client || !d.token || typeof Centrifuge === 'undefined') {
				return;
			}
			if (!client) {
				var url = (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + d.client + 'websocket/';
				client = new Centrifuge(url, {
					token: d.token,
					// every response brings a fresh token; a window that sat
					// longer than its lifetime takes the last one it got
					getToken: () => Promise.resolve(data().token)
				});
				client.on('connecting', ctx => p('relay: connecting', url, ctx.code, ctx.reason));
				client.on('connected', ctx => p('relay: connected', {client: ctx.client, transport: ctx.transport, url: url}));
				client.on('disconnected', ctx => p('relay: disconnected', ctx.code, ctx.reason));
				client.on('error', ctx => p('relay: hub error', ctx.type, ctx.error));
				client.connect();
			}
			relay.followDevice(d.device);
			relay.followActive(d.active);
			if (!Array.isArray(d.channels)) {
				return;   // nothing rendered in that response - keep listening
			}
			var wanted = new Set(d.channels);
			var added = [], removed = [];
			subs.forEach((sub, channel) => {
				if (!wanted.has(channel)) {
					sub.unsubscribe();
					client.removeSubscription(sub);
					subs.delete(channel);
					removed.push(channel);
				}
			});
			wanted.forEach(channel => {
				if (subs.has(channel)) {
					return;
				}
				var sub = client.getSubscription(channel) || client.newSubscription(channel);
				sub.on('publication', ctx => {
					if (ctx.data && ctx.data.origin === relay.origin) {
						p('relay: message', channel, ctx.data, '- own write, already in the answer');
						return;
					}
					p('relay: message', channel, ctx.data);
					relay.heard(channel);
				});
				sub.on('error', ctx => p('relay: subscription error', channel, ctx.error));
				sub.subscribe();
				subs.set(channel, sub);
				added.push(channel);
			});
			if (added.length || removed.length) {
				p('relay: channels', {added: added, removed: removed, total: subs.size});
			}
		};

		/**
		 * The channel of this window's device (Relay::deviceTopic), apart from
		 * the data channels: it holds as long as the device does. "logout"
		 * (the device was revoked - Meine Geräte, cms/users) first asks the
		 * server with the heartbeat header (X-Session-Check): that forces the
		 * device check, which otherwise runs once a minute, and ends the
		 * session. Then the reload shows the login.
		 */
		var deviceSub = null;
		relay.followDevice = channel => {
			if (!channel || (deviceSub && deviceSub.channel === channel)) {
				return;
			}
			if (deviceSub) {
				deviceSub.unsubscribe();
				client.removeSubscription(deviceSub);
			}
			deviceSub = client.getSubscription(channel) || client.newSubscription(channel);
			deviceSub.on('publication', ctx => {
				p('relay: device message', channel, ctx.data);
				if (ctx.data && ctx.data.op === 'logout') {
					fetch(location.pathname, {headers: {'X-Session-Check': '1'}, credentials: 'same-origin', cache: 'no-store'})
						.catch(() => {})
						.then(() => location.reload());
				}
			});
			deviceSub.on('error', ctx => p('relay: device subscription error', channel, ctx.error));
			deviceSub.subscribe();
			p('relay: device channel', channel);
		};

		/**
		 * Online is the device channel (the page is open). Active is this
		 * second channel, and only while the tab is visible and someone used
		 * it in the last five minutes. A hidden or idle tab stays online.
		 */
		var activeSub = null;
		var activeChannel = '';
		var lastUse = Date.now();
		var IDLE_MS = 5 * 60 * 1000;
		var idleTimer = null;
		var pageActive = () => document.visibilityState === 'visible' && (Date.now() - lastUse) < IDLE_MS;
		var armIdle = () => {
			if (idleTimer) {
				clearTimeout(idleTimer);
			}
			idleTimer = setTimeout(() => relay.followActive(activeChannel), Math.max(0, IDLE_MS - (Date.now() - lastUse)) + 50);
		};
		relay.followActive = channel => {
			activeChannel = channel || '';
			if (!client || activeChannel === '') {
				return;
			}
			var want = pageActive();
			if (want && activeSub && activeSub.channel === activeChannel) {
				return;
			}
			if (!want && !activeSub) {
				return;
			}
			if (activeSub) {
				activeSub.unsubscribe();
				client.removeSubscription(activeSub);
				activeSub = null;
			}
			if (!want) {
				if (idleTimer) {
					clearTimeout(idleTimer);
					idleTimer = null;
				}
				p('relay: inactive', activeChannel);
				return;
			}
			activeSub = client.getSubscription(activeChannel) || client.newSubscription(activeChannel);
			activeSub.on('error', ctx => p('relay: active subscription error', activeChannel, ctx.error));
			activeSub.subscribe();
			p('relay: active', activeChannel);
			armIdle();
		};
		var noteUse = () => {
			lastUse = Date.now();
			relay.followActive(activeChannel);
			armIdle();
		};
		document.addEventListener('visibilitychange', () => {
			if (document.visibilityState === 'visible') {
				lastUse = Date.now();
			}
			relay.followActive(activeChannel);
		});
		window.addEventListener('pointerdown', noteUse, {passive: true});
		window.addEventListener('keydown', noteUse);

		/** A channel changed - gather for a moment, then ask once. */
		relay.heard = channel => {
			pending.add(channel);
			if (!timer) {
				timer = setTimeout(() => relay.refresh(false), GATHER_MS);
			}
		};

		/**
		 * iOS (Safari and Chrome, both WebKit) often resumes a frozen page
		 * with pageshow or focus and no further visibilitychange. A scroll or
		 * a tap is also proof the page is on screen, even when document.hidden
		 * still says otherwise — and neither of those sends a request of its
		 * own, so without this the gathered channels would just sit there.
		 */
		var wakeArmed = false;
		var armWake = () => {
			if (wakeArmed) {
				return;
			}
			wakeArmed = true;
			var wake = () => {
				wakeArmed = false;
				waitingVisible = false;
				if (document.visibilityState !== 'visible' && !document.hasFocus()) {
					armWake();
					return;
				}
				relay.refresh(true);
			};
			document.addEventListener('visibilitychange', wake, {once: true});
			window.addEventListener('pageshow', wake, {once: true});
			window.addEventListener('focus', wake, {once: true});
		};
		var poke = () => {
			if (pending.size) {
				relay.refresh(true);
			}
		};
		window.addEventListener('touchend', poke, {passive: true});
		window.addEventListener('pointerup', poke, {passive: true});
		window.addEventListener('scroll', poke, true);

		/**
		 * Nothing took the changes along: one PUT to the url on screen.
		 * The header carries them, and so does the body (`changed`) — the
		 * server reads both. Not while a request is on its way: its answer
		 * may be what was missing, and the next request takes along what is left.
		 * force: the page is being looked at (tap, scroll, pageshow) even if
		 * document.hidden has not caught up yet.
		 */
		relay.refresh = force => {
			if (timer) {
				clearTimeout(timer);
				timer = null;
			}
			if (!pending.size) {
				return;   // a request took them along meanwhile
			}
			var visible = force === true || document.visibilityState === 'visible' || document.hasFocus();
			if (!visible) {
				waitingVisible = true;
				armWake();
				p('relay: wait until visible', document.visibilityState);
				return;
			}
			if (sys.core.server.inFlight > 0) {
				p('relay: wait for the request in flight');
				sys.core.server.onIdle(() => {
					if (!timer) {
						timer = setTimeout(() => relay.refresh(force), 0);
					}
				});
				return;
			}
			var channels = Array.from(pending);
			p('relay: refresh', sys.http.renderedUrl(), channels);
			var answered = false;
			var watchdog = setTimeout(() => {
				if (answered) {
					return;
				}
				channels.forEach(channel => pending.add(channel));
				p('relay: refresh failed, will retry', channels);
				if (!timer) {
					timer = setTimeout(() => relay.refresh(true), GATHER_MS);
				}
			}, 5000);
			sys.core.server.sendCall({changed: channels}, sys.http.renderedUrl()).done(() => {
				answered = true;
				clearTimeout(watchdog);
			});
		};
	});
})();
