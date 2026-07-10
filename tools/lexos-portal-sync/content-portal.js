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

  chrome.storage.sync.set({
    portalSyncUrl: syncUrl,
    portalUrl: window.location.origin + window.location.pathname,
  });

  document.documentElement.setAttribute('data-wct-lexos-extension', '1');

  function notifyTokenReady() {
    window.postMessage({ type: 'WCT_LEXOS_TOKEN_READY' }, '*');
  }

  function mirrorLexosTokenToPage() {
    chrome.storage.local.get(['lexosToken'], (result) => {
      const token = String(result.lexosToken || '').trim();
      if (token !== '') {
        const prev = localStorage.getItem('lexosToken') || '';
        localStorage.setItem('lexosToken', token);
        if (prev !== token) {
          notifyTokenReady();
        }
      }
    });
  }

  mirrorLexosTokenToPage();
  setInterval(mirrorLexosTokenToPage, 5 * 1000);

  try {
    chrome.storage.onChanged.addListener((changes, area) => {
      if (area === 'local' && changes.lexosToken) {
        mirrorLexosTokenToPage();
      }
    });
  } catch (_e) {
    /* ignore */
  }
})();

window.addEventListener('message', (event) => {
  if (event.source !== window || !event.data || typeof event.data.type !== 'string') {
    return;
  }

  const data = event.data;

  if (data.type === 'WCT_LEXOS_PING') {
    window.postMessage({ type: 'WCT_LEXOS_PONG', requestId: data.requestId, version: '1.2.1' }, '*');
    return;
  }

  if (data.type === 'WCT_LEXOS_GET_TOKEN') {
    chrome.storage.local.get(['lexosToken'], (result) => {
      const token = String(result.lexosToken || '').trim();
      if (token !== '') {
        localStorage.setItem('lexosToken', token);
      }
      window.postMessage({
        type: 'WCT_LEXOS_TOKEN_RESULT',
        requestId: data.requestId,
        token,
      }, '*');
    });
    return;
  }

  if (data.type === 'WCT_LEXOS_ENSURE_HUB') {
    chrome.runtime.sendMessage({ type: 'LEXOS_HUB_ENSURE' });
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
