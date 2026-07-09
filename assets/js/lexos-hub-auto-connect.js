(function (global) {
  'use strict';

  function pingExtension(timeoutMs) {
    return new Promise(function (resolve) {
      var requestId = 'ping-' + Date.now();
      var done = false;
      function onMessage(event) {
        if (event.source !== window || !event.data || event.data.type !== 'WCT_LEXOS_PONG') {
          return;
        }
        if (event.data.requestId !== requestId) {
          return;
        }
        done = true;
        window.removeEventListener('message', onMessage);
        resolve(true);
      }
      window.addEventListener('message', onMessage);
      window.postMessage({ type: 'WCT_LEXOS_PING', requestId: requestId }, '*');
      setTimeout(function () {
        if (!done) {
          window.removeEventListener('message', onMessage);
          resolve(false);
        }
      }, timeoutMs || 1200);
    });
  }

  function fetchStatus(statusUrl) {
    return fetch(statusUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (res) { return res.json(); });
  }

  function pollUntilConnected(statusUrl, options) {
    options = options || {};
    var maxAttempts = options.maxAttempts || 90;
    var intervalMs = options.intervalMs || 2000;
    var attempt = 0;

    return new Promise(function (resolve, reject) {
      function tick() {
        attempt += 1;
        fetchStatus(statusUrl).then(function (body) {
          if (body && body.ok && body.has_access) {
            resolve(body);
            return;
          }
          if (attempt >= maxAttempts) {
            reject(new Error((body && body.message) || 'Tempo esgotado aguardando sessão Hub.'));
            return;
          }
          setTimeout(tick, intervalMs);
        }).catch(function (err) {
          if (attempt >= maxAttempts) {
            reject(err);
            return;
          }
          setTimeout(tick, intervalMs);
        });
      }
      tick();
    });
  }

  function openHubAndWait(statusUrl, hubUrl) {
    window.open(hubUrl, 'wct_lexos_hub_login');
    return pollUntilConnected(statusUrl);
  }

  global.WctLexosHubAutoConnect = {
    pingExtension: pingExtension,
    fetchStatus: fetchStatus,
    pollUntilConnected: pollUntilConnected,
    openHubAndWait: openHubAndWait,
  };
})(window);
