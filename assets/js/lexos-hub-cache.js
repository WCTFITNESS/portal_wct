(function (global) {
  'use strict';

  var LS_ACCESS = 'wct_lexos_hub_access';
  var LS_REFRESH = 'wct_lexos_hub_refresh';
  var LS_SYNCED_AT = 'wct_lexos_hub_synced_at';

  function readCache() {
    return {
      access: String(localStorage.getItem(LS_ACCESS) || '').trim(),
      refresh: String(localStorage.getItem(LS_REFRESH) || '').trim(),
      synced_at: parseInt(localStorage.getItem(LS_SYNCED_AT) || '0', 10) || 0,
    };
  }

  function writeCache(access, refresh) {
    if (access) localStorage.setItem(LS_ACCESS, access);
    if (refresh) localStorage.setItem(LS_REFRESH, refresh);
    localStorage.setItem(LS_SYNCED_AT, String(Date.now()));
  }

  function formatSyncedAt(ts) {
    if (!ts) return 'nunca';
    try {
      return new Date(ts).toLocaleString('pt-BR');
    } catch (e) {
      return String(ts);
    }
  }

  function renderStatus(elementId) {
    var el = document.getElementById(elementId);
    if (!el) return;
    var c = readCache();
    if (!c.access && !c.refresh) {
      el.innerHTML = '<strong>Cache do navegador:</strong> vazio — use o favorito no Hub Lexos.';
      return;
    }
    el.innerHTML = '<strong>Cache do navegador:</strong> refresh '
      + (c.refresh ? 'ok' : 'ausente')
      + ', access ' + (c.access ? 'ok' : 'ausente')
      + ' (atualizado ' + formatSyncedAt(c.synced_at) + ')';
  }

  function syncToServer(syncUrl, options) {
    options = options || {};
    var c = readCache();
    if (!c.access && !c.refresh) {
      return Promise.resolve({ ok: false, message: 'Cache vazio no navegador.' });
    }
    return fetch(syncUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        lexos_hub_token: c.access,
        lexos_hub_refresh_token: c.refresh,
      }),
    })
      .then(function (res) { return res.json().then(function (body) { return { res: res, body: body }; }); })
      .then(function (pack) {
        if (!pack.res.ok || !pack.body.ok) {
          return { ok: false, message: (pack.body && pack.body.message) || ('HTTP ' + pack.res.status) };
        }
        if (!options.silent) {
          writeCache(c.access, c.refresh);
        }
        return { ok: true, message: pack.body.message || 'Sincronizado.' };
      })
      .catch(function (err) {
        return { ok: false, message: err && err.message ? err.message : 'Falha de rede' };
      });
  }

  global.WctLexosHubCache = {
    readCache: readCache,
    writeCache: writeCache,
    renderStatus: renderStatus,
    syncToServer: syncToServer,
    LS_ACCESS: LS_ACCESS,
    LS_REFRESH: LS_REFRESH,
    LS_SYNCED_AT: LS_SYNCED_AT,
  };
})(window);
