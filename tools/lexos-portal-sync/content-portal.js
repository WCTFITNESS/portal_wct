/**
 * Ao abrir o Portal, grava automaticamente a URL de sync para a extensão.
 */
(function () {
  function buildSyncUrl() {
    try {
      const url = new URL(window.location.href);
      url.searchParams.set('page', 'lexos-hub-connect');
      url.searchParams.set('action', 'sync');
      return url.toString();
    } catch (_e) {
      return '';
    }
  }

  const syncUrl = buildSyncUrl();
  if (!syncUrl) {
    return;
  }

  chrome.storage.sync.set({
    portalSyncUrl: syncUrl,
    portalUrl: window.location.origin + window.location.pathname,
  });

  document.documentElement.setAttribute('data-wct-lexos-extension', '1');
})();

window.addEventListener('message', (event) => {
  if (event.source !== window || !event.data || typeof event.data.type !== 'string') {
    return;
  }

  const data = event.data;

  if (data.type === 'WCT_LEXOS_PING') {
    window.postMessage({ type: 'WCT_LEXOS_PONG', requestId: data.requestId, version: '1.2.0' }, '*');
    return;
  }

  if (data.type === 'WCT_LEXOS_FETCH') {
    chrome.runtime.sendMessage(
      {
        type: 'LEXOS_HUB_FETCH',
        url: data.url,
        method: data.method || 'POST',
        payload: data.payload || null,
      },
      (response) => {
        const err = chrome.runtime.lastError;
        window.postMessage(
          {
            type: 'WCT_LEXOS_FETCH_RESULT',
            requestId: data.requestId,
            ok: !!(response && response.ok),
            status: response ? response.status : 0,
            body: response ? response.body : null,
            error: err ? err.message : (response ? response.error : 'Sem resposta da extensão'),
          },
          '*',
        );
      },
    );
  }
});
