(function (global) {
  'use strict';

  var LS_ACCESS = 'wct_lexos_hub_access';
  var LS_REFRESH = 'wct_lexos_hub_refresh';
  var LS_CONTEXT = 'wct_lexos_hub_context';
  var LS_SYNCED_AT = 'wct_lexos_hub_synced_at';

  function parseJson(raw, fallback) {
    if (!raw) return fallback;
    try {
      var parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : fallback;
    } catch (e) {
      return fallback;
    }
  }

  function readCache() {
    return {
      access: String(localStorage.getItem(LS_ACCESS) || '').trim(),
      refresh: String(localStorage.getItem(LS_REFRESH) || '').trim(),
      context: parseJson(localStorage.getItem(LS_CONTEXT), {}),
      synced_at: parseInt(localStorage.getItem(LS_SYNCED_AT) || '0', 10) || 0,
    };
  }

  function writeCache(access, refresh, context) {
    if (access) localStorage.setItem(LS_ACCESS, access);
    if (refresh) localStorage.setItem(LS_REFRESH, refresh);
    if (context && typeof context === 'object') {
      localStorage.setItem(LS_CONTEXT, JSON.stringify(context));
    }
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
    var ctxKeys = c.context && c.context.local_storage ? Object.keys(c.context.local_storage).length : 0;
    if (!c.access && !c.refresh && !ctxKeys) {
      el.innerHTML = '<strong>Cache do navegador:</strong> vazio — use o favorito no Hub Lexos.';
      return;
    }
    el.innerHTML = '<strong>Cache do navegador:</strong> refresh '
      + (c.refresh ? 'ok' : 'ausente')
      + ', access ' + (c.access ? 'ok' : 'ausente')
      + ', chaves Hub ' + ctxKeys
      + ' (atualizado ' + formatSyncedAt(c.synced_at) + ')';
  }

  function syncToServer(syncUrl, options) {
    options = options || {};
    var c = readCache();
    if (!c.access && !c.refresh && !(c.context && Object.keys(c.context).length)) {
      return Promise.resolve({ ok: false, message: 'Cache vazio no navegador.' });
    }
    return fetch(syncUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        lexos_hub_token: c.access,
        lexos_hub_refresh_token: c.refresh,
        lexos_hub_context: c.context,
      }),
    })
      .then(function (res) { return res.json().then(function (body) { return { res: res, body: body }; }); })
      .then(function (pack) {
        if (!pack.res.ok || !pack.body.ok) {
          return { ok: false, message: (pack.body && pack.body.message) || ('HTTP ' + pack.res.status) };
        }
        writeCache(c.access, c.refresh, c.context);
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
    LS_CONTEXT: LS_CONTEXT,
    LS_SYNCED_AT: LS_SYNCED_AT,
  };
})(window);
