// CSRF auto-attach — wraps window.fetch so every existing fetch() call in
// admin.js (and any future script) automatically sends the session's CSRF
// token on state-changing requests, without editing each call site.
(function () {
  var nativeFetch = window.fetch;
  if (!nativeFetch || typeof window.CSF_CSRF === 'undefined') return;

  window.fetch = function (input, init) {
    init = init || {};
    var method = (init.method || 'GET').toUpperCase();

    if (method !== 'GET' && method !== 'HEAD') {
      var headers = new Headers(init.headers || {});
      if (!headers.has('X-CSRF-Token')) {
        headers.set('X-CSRF-Token', window.CSF_CSRF);
      }
      init.headers = headers;
    }

    return nativeFetch.call(window, input, init);
  };
})();
