/* GGSEC Stats: confirms that a real browser displayed the page and counts file downloads. No cookies, no storage. */
(function () {
	var c = window.ggsecStats;
	if (!c || !c.i || !c.t) {
		return;
	}
	var sent = false;

	function post(url, data) {
		var body = new URLSearchParams(data);
		if (navigator.sendBeacon && navigator.sendBeacon(url, body)) {
			return;
		}
		if (window.fetch) {
			fetch(url, { method: 'POST', body: body, keepalive: true, credentials: 'omit' }).catch(function () {});
		}
	}

	function send() {
		// Prerendered or background tabs are only counted once the user actually sees them.
		if (sent || document.prerendering || document.visibilityState !== 'visible') {
			return;
		}
		sent = true;
		post(c.u, { id: String(c.i), t: c.t });
	}

	send();
	document.addEventListener('visibilitychange', send);
	document.addEventListener('prerenderingchange', send);

	// Downloads: clicks (incl. middle click) on same-site links to tracked file types.
	var ext = new RegExp('\\.(' + (c.x || []).join('|') + ')$', 'i');
	function onClick(e) {
		// auxclick also fires for the right button (context menu); only the middle button opens the file.
		if ((e.type === 'auxclick' && e.button !== 1) || !c.d || !c.x || !c.x.length || !e.target || !e.target.closest) {
			return;
		}
		var a = e.target.closest('a[href]');
		if (!a) {
			return;
		}
		var url;
		try {
			url = new URL(a.href, location.href);
		} catch (err) {
			return;
		}
		if (url.host === location.host && ext.test(url.pathname)) {
			post(c.d, { id: String(c.i), t: c.t, f: url.pathname });
		}
	}
	document.addEventListener('click', onClick, true);
	document.addEventListener('auxclick', onClick, true);
})();
