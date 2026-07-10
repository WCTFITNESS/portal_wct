/**
 * Proxy de fetch para a WebAPI Lexos — mesmo papel do popup.js do plugin Faturamento.
 */
chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (!message) {
    return false;
  }

  if (message.type === 'LEXOS_HUB_ENSURE') {
    chrome.tabs.query({ url: 'https://app-hub.lexos.com.br/*' }, (tabs) => {
      if (tabs && tabs.length > 0) {
        chrome.tabs.update(tabs[0].id, { active: true });
      } else {
        chrome.tabs.create({ url: 'https://app-hub.lexos.com.br/' });
      }
    });
    return false;
  }

  if (message.type !== 'LEXOS_HUB_FETCH') {
    return false;
  }

  chrome.storage.local.get(['lexosToken'], async (result) => {
    const token = String(result.lexosToken || '').trim();
    if (token === '') {
      sendResponse({
        ok: false,
        status: 0,
        error: 'Token Hub ausente. Abra app-hub.lexos.com.br logado.',
      });
      return;
    }

    try {
      const response = await fetch(message.url, {
        method: message.method || 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          Authorization: 'Bearer ' + token,
        },
        body: message.payload ? JSON.stringify(message.payload) : undefined,
      });

      let body = null;
      const raw = await response.text();
      if (raw !== '') {
        try {
          body = JSON.parse(raw);
        } catch (_e) {
          body = raw;
        }
      }

      sendResponse({
        ok: response.ok,
        status: response.status,
        body,
        error: response.ok ? '' : 'HTTP ' + response.status,
      });
    } catch (error) {
      sendResponse({
        ok: false,
        status: 0,
        error: error && error.message ? error.message : 'Erro de rede',
      });
    }
  });

  return true;
});
