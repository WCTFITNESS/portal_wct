const DEFAULT_PORTAL_URL = 'https://portal-wct.onrender.com/index.php?page=api-config';

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

function syncToPortal(portalUrl) {
  const token = localStorage.getItem('access_token');
  if (!token || String(token).trim() === '') {
    return;
  }

  const refresh = pickRefreshToken();
  const last = sessionStorage.getItem('wct_lexos_last_sync') || '';
  const fingerprint = token.slice(0, 24) + '|' + refresh.slice(0, 16);
  if (last === fingerprint) {
    return;
  }

  let iframe = document.getElementById('wct-lexos-sync-frame');
  if (!iframe) {
    iframe = document.createElement('iframe');
    iframe.id = 'wct-lexos-sync-frame';
    iframe.name = 'wct-lexos-sync-frame';
    iframe.style.display = 'none';
    document.body.appendChild(iframe);
  }

  const form = document.createElement('form');
  form.method = 'POST';
  form.action = portalUrl;
  form.target = 'wct-lexos-sync-frame';

  const fields = {
    api_tab: 'lexos',
    form_type: 'lexos_hub_capture',
    lexos_hub_silent: '1',
    lexos_hub_token: String(token).trim(),
    lexos_hub_refresh_token: refresh,
  };

  Object.keys(fields).forEach((name) => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = fields[name];
    form.appendChild(input);
  });

  document.body.appendChild(form);
  form.submit();
  form.remove();
  sessionStorage.setItem('wct_lexos_last_sync', fingerprint);
}

chrome.storage.sync.get(['portalUrl'], (result) => {
  const portalUrl = (result.portalUrl || DEFAULT_PORTAL_URL).trim();
  if (!portalUrl) {
    return;
  }
  syncToPortal(portalUrl);
  setInterval(() => syncToPortal(portalUrl), 5 * 60 * 1000);
});
