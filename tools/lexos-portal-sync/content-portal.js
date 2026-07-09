/**
 * Ponte entre a página do Portal e a extensão (fetch Lexos com token do Hub).
 */
window.addEventListener('message', (event) => {
  if (event.source !== window || !event.data || typeof event.data.type !== 'string') {
    return;
  }

  const data = event.data;

  if (data.type === 'WCT_LEXOS_PING') {
    window.postMessage({ type: 'WCT_LEXOS_PONG', requestId: data.requestId, version: '1.1.0' }, '*');
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
          '*'
        );
      }
    );
  }
});

document.documentElement.setAttribute('data-wct-lexos-extension', '1');
