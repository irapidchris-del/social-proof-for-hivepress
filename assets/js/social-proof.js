/**
 * Social Proof for HivePress — frontend toast engine.
 *
 * Fetches the event feed once per page view and rotates toasts according to
 * the configured timing, order and concurrency rules. All per-visitor rules
 * (snooze, no-repeat, own-activity filtering) run here in the browser so the
 * server feed stays cacheable.
 */
(function () {
	'use strict';

	var cfg = window.HPSPConfig;

	if (!cfg) {
		return;
	}

	var SNOOZE_KEY = 'hpspSnoozeUntil';
	var SEEN_KEY = 'hpspSeen';

	var root = null;
	var queue = [];
	var pointer = 0;
	var visible = 0;
	var shown = 0;
	var stopped = false;
	var spawnTimer = null;

	var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var isMobile = window.matchMedia && window.matchMedia('(max-width: 640px)').matches;

	// ------------------------------------------------------------------
	// Storage helpers (private browsing modes may throw on access).
	// ------------------------------------------------------------------

	function storeGet(storage, key) {
		try {
			return window[storage].getItem(key);
		} catch (e) {
			return null;
		}
	}

	function storeSet(storage, key, value) {
		try {
			window[storage].setItem(key, value);
		} catch (e) {
			// Ignore.
		}
	}

	function isSnoozed() {
		var until = parseInt(storeGet('localStorage', SNOOZE_KEY) || '0', 10);

		return until > Date.now();
	}

	function getSeen() {
		try {
			return JSON.parse(storeGet('sessionStorage', SEEN_KEY) || '[]') || [];
		} catch (e) {
			return [];
		}
	}

	function markSeen(id) {
		var seen = getSeen();

		if (seen.indexOf(id) === -1) {
			seen.push(id);

			if (seen.length > 100) {
				seen = seen.slice(-100);
			}

			storeSet('sessionStorage', SEEN_KEY, JSON.stringify(seen));
		}
	}

	// ------------------------------------------------------------------
	// Formatting.
	// ------------------------------------------------------------------

	function timeAgo(ts) {
		var diff = Math.max(0, Math.floor(Date.now() / 1000) - ts);

		if (diff < 60) {
			return cfg.i18n.justNow;
		}

		var mins = Math.floor(diff / 60);

		if (mins < 60) {
			return mins === 1 ? cfg.i18n.minAgo : cfg.i18n.minsAgo.replace('%d', mins);
		}

		var hours = Math.floor(mins / 60);

		if (hours < 24) {
			return hours === 1 ? cfg.i18n.hourAgo : cfg.i18n.hoursAgo.replace('%d', hours);
		}

		var days = Math.floor(hours / 24);

		return days === 1 ? cfg.i18n.dayAgo : cfg.i18n.daysAgo.replace('%d', days);
	}

	function shuffle(list) {
		for (var i = list.length - 1; i > 0; i--) {
			var j = Math.floor(Math.random() * (i + 1));
			var tmp = list[i];

			list[i] = list[j];
			list[j] = tmp;
		}

		return list;
	}

	// ------------------------------------------------------------------
	// Toast lifecycle.
	// ------------------------------------------------------------------

	function makeInitialBadge(initial) {
		var badge = document.createElement('span');

		badge.className = 'hpsp-toast__img hpsp-toast__initial';
		badge.textContent = initial || '•';

		return badge;
	}

	function buildToast(ev) {
		var toast = document.createElement('div');

		toast.className = 'hpsp-toast';
		toast.setAttribute('role', 'status');

		if (ev.img) {
			var img = document.createElement('img');

			img.className = 'hpsp-toast__img';
			img.alt = '';
			img.src = ev.img;
			img.addEventListener('error', function () {
				if (img.parentNode === toast) {
					toast.replaceChild(makeInitialBadge(ev.initial), img);
				}
			});
			toast.appendChild(img);
		} else if (ev.icon && /^[a-z0-9-]+$/.test(ev.icon)) {
			// Icon names are validated server-side against a fixed list; the
			// pattern test above is belt and braces before touching className.
			var tile = document.createElement('span');

			tile.className = 'hpsp-toast__img hpsp-toast__icon';

			var glyph = document.createElement('i');

			glyph.className = 'fas fa-' + ev.icon;
			glyph.setAttribute('aria-hidden', 'true');
			tile.appendChild(glyph);
			toast.appendChild(tile);
		} else if (ev.initial) {
			toast.appendChild(makeInitialBadge(ev.initial));
		}

		var content = document.createElement('div');

		content.className = 'hpsp-toast__content';

		var message = document.createElement('span');

		message.className = 'hpsp-toast__message';
		// The message is intentionally HTML (listing links, bold names). All
		// user-supplied values inside it are escaped server-side and the whole
		// string passes wp_kses against a small allowlist before it is served.
		message.innerHTML = ev.html;
		content.appendChild(message);

		if (cfg.showTime && ev.time) {
			var time = document.createElement('small');

			time.className = 'hpsp-toast__time';
			time.setAttribute('data-ts', String(ev.time));
			time.textContent = timeAgo(ev.time);
			content.appendChild(time);
		}

		toast.appendChild(content);

		if (cfg.showProgress) {
			var bar = document.createElement('span');

			bar.className = 'hpsp-toast__progress';
			bar.style.animationDuration = cfg.displayDuration + 'ms';
			toast.appendChild(bar);
		}

		if (cfg.showClose) {
			var close = document.createElement('button');

			close.type = 'button';
			close.className = 'hpsp-toast__close';
			close.setAttribute('aria-label', cfg.i18n.close);
			close.textContent = '×';
			close.addEventListener('click', function () {
				hideToast(toast, false);
				snooze();
			});
			toast.appendChild(close);
		}

		return toast;
	}

	function showToast(ev) {
		var toast = buildToast(ev);

		root.appendChild(toast);
		visible++;
		shown++;

		// Only burn the no-repeat token when the tab can actually paint the
		// toast; a hidden tab must not mark events the visitor never saw.
		if (!document.hidden) {
			markSeen(ev.id);
		}

		// Force a layout pass so the enter transition runs.
		void toast.offsetWidth;
		toast.classList.add('hpsp-show');

		var remaining = cfg.displayDuration;
		var startedAt = Date.now();
		var hideTimer = window.setTimeout(function () {
			hideToast(toast, false);
		}, remaining);

		// Hovering pauses the auto-dismiss timer.
		toast.addEventListener('mouseenter', function () {
			if (hideTimer) {
				window.clearTimeout(hideTimer);
				hideTimer = null;
				remaining -= Date.now() - startedAt;
			}
		});

		toast.addEventListener('mouseleave', function () {
			if (!hideTimer && toast.parentNode) {
				startedAt = Date.now();
				hideTimer = window.setTimeout(function () {
					hideToast(toast, false);
				}, Math.max(1000, remaining));
			}
		});

		toast.hpspClearTimer = function () {
			if (hideTimer) {
				window.clearTimeout(hideTimer);
				hideTimer = null;
			}
		};
	}

	function hideToast(toast, immediate) {
		if (!toast.parentNode || toast.hpspHiding) {
			return;
		}

		toast.hpspHiding = true;

		if (toast.hpspClearTimer) {
			toast.hpspClearTimer();
		}

		toast.classList.remove('hpsp-show');
		toast.classList.add('hpsp-hide');

		window.setTimeout(function () {
			if (toast.parentNode) {
				toast.parentNode.removeChild(toast);
			}

			visible = Math.max(0, visible - 1);

			if (!immediate && !stopped) {
				scheduleNext(cfg.gap);
			}
		}, immediate ? 0 : cfg.animationSpeed + 50);
	}

	// ------------------------------------------------------------------
	// Scheduling.
	// ------------------------------------------------------------------

	function nextEvent() {
		if (!queue.length) {
			return null;
		}

		if (pointer >= queue.length) {
			if (cfg.loop && !cfg.noRepeat) {
				pointer = 0;
			} else {
				return null;
			}
		}

		return queue[pointer++];
	}

	function scheduleNext(delay) {
		if (stopped || spawnTimer) {
			return;
		}

		spawnTimer = window.setTimeout(spawnNext, delay);
	}

	function spawnNext() {
		spawnTimer = null;

		if (stopped) {
			return;
		}

		// Don't burn through events while the tab is in the background.
		if (document.hidden) {
			var onVisible = function () {
				if (!document.hidden) {
					document.removeEventListener('visibilitychange', onVisible);
					scheduleNext(1000);
				}
			};

			document.addEventListener('visibilitychange', onVisible);

			return;
		}

		if (cfg.maxPerPage > 0 && shown >= cfg.maxPerPage) {
			stopped = true;

			return;
		}

		if (visible >= cfg.maxVisible) {
			// A toast hiding will call scheduleNext() again.
			return;
		}

		var ev = nextEvent();

		if (!ev) {
			stopped = true;

			return;
		}

		showToast(ev);

		if (visible < cfg.maxVisible) {
			scheduleNext(cfg.gap);
		}
	}

	function stopAll() {
		stopped = true;

		if (spawnTimer) {
			window.clearTimeout(spawnTimer);
			spawnTimer = null;
		}

		var toasts = root.querySelectorAll('.hpsp-toast');

		for (var i = 0; i < toasts.length; i++) {
			hideToast(toasts[i], true);
		}
	}

	function snooze() {
		if (cfg.snooze > 0) {
			storeSet('localStorage', SNOOZE_KEY, String(Date.now() + cfg.snooze));
			stopAll();
		}
	}

	// ------------------------------------------------------------------
	// Bootstrap.
	// ------------------------------------------------------------------

	function start(events) {
		var seen = cfg.noRepeat ? getSeen() : [];

		queue = [];

		for (var i = 0; i < events.length; i++) {
			var ev = events[i];

			if (!ev || !ev.html || !ev.id) {
				continue;
			}

			// Don't tell visitors about their own activity.
			if (cfg.viewer && ev.actor && ev.actor === cfg.viewer) {
				continue;
			}

			if (cfg.noRepeat && seen.indexOf(ev.id) !== -1) {
				continue;
			}

			queue.push(ev);
		}

		if (cfg.order === 'random') {
			shuffle(queue);
		}

		if (queue.length) {
			scheduleNext(Math.max(500, cfg.initialDelay));
		}
	}

	// ------------------------------------------------------------------
	// Coexistence with Notifications for HivePress: while its pop-up stack
	// occupies the same corner, shift our stack clear of it. The offset is
	// live: it grows with their stack and returns to zero when it empties.
	// ------------------------------------------------------------------

	function watchNotificationsToasts() {
		if (!window.MutationObserver) {
			return;
		}

		var theirs = document.querySelector('.hp-notification-toasts');

		// Their container is created lazily when their first pop-up arrives,
		// so watch the body until it appears, then attach to it.
		if (!theirs) {
			var finder = new MutationObserver(function () {
				var found = document.querySelector('.hp-notification-toasts');

				if (found) {
					finder.disconnect();
					watchNotificationsToasts();
				}
			});

			finder.observe(document.body, { childList: true });

			return;
		}

		// Both stacks carry their desktop AND their mobile position class at all
		// times, so a test that accepts either one answers for the wrong
		// viewport: a site set to top on the phone and bottom on the desktop
		// read as "top" on the desktop, the yield was never applied, and the two
		// stacks painted on top of each other in the very corner this code
		// exists to keep clear. Ask which class is in force at this width, and
		// ask it with each plugin's own breakpoint - they are not the same
		// number (this plugin switches at 640px, theirs at 480px).
		function matches(query) {
			return !!(window.matchMedia && window.matchMedia(query).matches);
		}

		function sameVerticalEdge() {
			var ourTop = matches('(max-width: 640px)')
				? /hpsp-posm-top/.test(root.className)
				: /hpsp-pos-top/.test(root.className);

			var theirTop = matches('(max-width: 480px)')
				? /hp-notification-toasts--m-top/.test(theirs.className)
				: /hp-notification-toasts--top-/.test(theirs.className);

			return ourTop === theirTop;
		}

		function update() {
			var yield_ = 0;

			if (theirs.childElementCount > 0 && sameVerticalEdge()) {
				var theirRect = theirs.getBoundingClientRect();
				var ourRect = root.getBoundingClientRect();

				// Horizontal overlap check keeps opposite corners independent.
				if (theirRect.left < ourRect.right && theirRect.right > ourRect.left) {
					yield_ = Math.ceil(theirRect.height) + 12;
				}
			}

			root.style.setProperty('--hpsp-yield', yield_ + 'px');
		}

		new MutationObserver(update).observe(theirs, { childList: true, subtree: true });
		window.addEventListener('resize', update);
		update();
	}

	function bootstrap() {
		root = document.getElementById('hpsp-root');

		if (!root || isSnoozed()) {
			return;
		}

		watchNotificationsToasts();

		if (isMobile && !cfg.mobile) {
			return;
		}

		if (reducedMotion) {
			root.classList.add('hpsp-reduced-motion');
		}

		// Refresh the visible relative-time labels.
		window.setInterval(function () {
			var times = root.querySelectorAll('.hpsp-toast__time[data-ts]');

			for (var i = 0; i < times.length; i++) {
				times[i].textContent = timeAgo(parseInt(times[i].getAttribute('data-ts'), 10));
			}
		}, 30000);

		if (!window.fetch) {
			return;
		}

		window.fetch(cfg.endpoint, { credentials: 'same-origin', cache: 'no-store' })
			.then(function (res) {
				return res.ok ? res.json() : null;
			})
			.then(function (data) {
				if (data && data.events && data.events.length) {
					start(data.events);
				}
			})
			.catch(function () {
				// Network errors: fail silently, popups are non-essential.
			});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bootstrap);
	} else {
		bootstrap();
	}
})();
