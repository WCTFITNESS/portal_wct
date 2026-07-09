/**
 * Igual ao plugin Faturamento: lê access_token do Hub e sincroniza com o Portal automaticamente.
 */
function dumpStorage(store) {
  const out = {};
  try {
    for (let i = 0; i < store.length; i += 1) {
      const key = store.key(i);
      if (key) {
        out[key] = String(store.getItem(key) || '');
      }
    }
  } catch (_e) {
    /* ignore */
  }
  return out;
}

function pickRefreshToken() {
  const keys = ['refresh_token', 'refreshToken', 'hub_refresh_token'];
  for (const k of keys) {
    const v = localStorage.getItem(k);
    if (v && String(v).trim() !== '') {
      return String(v).trim();
    }
  }
  return '';
}

function pickAccessToken() {
  const direct = localStorage.getItem('access_token') || localStorage.getItem('accessToken') || '';
  if (String(direct).trim() !== '') {
    return String(direct).trim();
  }
  const storage = dumpStorage(localStorage);
  for (const value of Object.values(storage)) {
    const val = String(value || '').trim();
    if (val.startsWith('eyJ') && val.split('.').length === 3) {
      return val;
    }
  }
  return '';
}

async function syncToPortal(syncUrl, token, refresh) {
  if (!syncUrl || !token) {
    return;
  }

  const fingerprint = token.slice(0, 24) + '|' + refresh.slice(0, 16);
  const last = sessionStorage.getItem('wct_lexos_last_sync') || '';
  if (last === fingerprint) {
    return;
  }

  const payload = {
    lexos_hub_token: String(token).trim(),
    lexos_hub_refresh_token: refresh,
    lexos_hub_context: {
      local_storage: dumpStorage(localStorage),
      session_storage: dumpStorage(sessionStorage),
      cookies: String(document.cookie || ''),
      captured_at: Date.now(),
    },
  };

  try {
    const response = await fetch(syncUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload),
    });
    const body = await response.json().catch(() => ({}));
    if (response.ok && body.ok) {
      sessionStorage.setItem('wct_lexos_last_sync', fingerprint);
      sessionStorage.setItem('wct_lexos_last_sync_at', String(Date.now()));
    }
  } catch (_e) {
    /* rede / portal offline */
  }
}

function captureHubToken() {
  const token = pickAccessToken();
  if (!token) {
    return;
  }

  chrome.storage.local.set({ lexosToken: token });

  chrome.storage.sync.get(['portalSyncUrl', 'portalUrl'], (result) => {
    const syncUrl = String(
      result.portalSyncUrl
        || (String(result.portalUrl || '').includes('action=sync') ? result.portalUrl : '')
        || '',
    ).trim();
    if (!syncUrl) {
      return;
    }
    syncToPortal(syncUrl, token, pickRefreshToken());
  });
}

captureHubToken();
setInterval(captureHubToken, 30 * 1000);
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible') {
    captureHubToken();
  }
});
