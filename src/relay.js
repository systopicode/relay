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

		/** A channel changed - gather for a moment, then ask once. */
		relay.heard = channel => {
			pending.add(channel);
			if (!timer) {
				timer = setTimeout(relay.refresh, GATHER_MS);
			}
		};

		/**
		 * Nothing took the changes along: one PUT to the url on screen, the
		 * header carries them, the server re-renders what read them. Not while
		 * a request is on its way - its answer may be what was missing, and
		 * the next request takes along what is left.
		 */
		relay.refresh = () => {
			timer = null;
			if (!pending.size) {
				return;   // a request took them along meanwhile
			}
			if (document.hidden) {   // a hidden tab asks when it is looked at again
				if (!waitingVisible) {
					waitingVisible = true;
					document.addEventListener('visibilitychange', () => {
						waitingVisible = false;
						relay.refresh();
					}, {once: true});
				}
				return;
			}
			if (sys.core.server.inFlight > 0) {
				sys.core.server.onIdle(() => {
					if (!timer) {
						timer = setTimeout(relay.refresh, 0);
					}
				});
				return;
			}
			p('relay: refresh', sys.http.renderedUrl(), Array.from(pending));
			sys.core.server.sendCall({}, sys.http.renderedUrl());
		};
	});
})();
