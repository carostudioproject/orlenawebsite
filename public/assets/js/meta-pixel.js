// Meta Pixel for Orlena. The Pixel ID comes from the script tag (data-pixel-id), set from META_PIXEL_ID.
// Also sends Contact (WhatsApp links) and FindLocation (Google Maps links) when those links are clicked.
(function () {
  var tag = document.currentScript;
  var id = tag && tag.getAttribute('data-pixel-id');
  if (!id || window.fbq) return;

  !function (f, b, e, v, n, t, s) {
    if (f.fbq) return; n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); };
    if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = [];
    t = b.createElement(e); t.async = !0; t.src = v; s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s);
  }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');

  // No automatic configuration: the Pixel must not read form fields (WhatsApp number, name, address) or button texts.
  window.fbq('set', 'autoConfig', false, id);
  window.fbq('init', id);
  window.fbq('track', 'PageView');

  document.addEventListener('click', function (event) {
    // Pages reached in-app from an order page (payment, order tracking) are never tracked.
    if (/^\/(bayar|cek-pesanan|admin)(\/|$)/.test(location.pathname)) return;
    var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
    if (!link) return;
    var href = link.getAttribute('href') || '';
    if (/^https:\/\/(wa\.me|api\.whatsapp\.com)\//.test(href)) window.fbq('track', 'Contact');
    else if (/^https:\/\/(maps\.app\.goo\.gl|goo\.gl\/maps|(www\.)?google\.[a-z.]+\/maps|maps\.google\.)/.test(href)) window.fbq('track', 'FindLocation');
  }, true);
})();
