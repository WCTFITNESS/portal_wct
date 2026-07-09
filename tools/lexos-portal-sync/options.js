const DEFAULT_PORTAL_URL = 'https://portal-wct.onrender.com/index.php?page=api-config';

document.addEventListener('DOMContentLoaded', () => {
  const input = document.getElementById('portalUrl');
  const status = document.getElementById('status');
  chrome.storage.sync.get(['portalUrl'], (result) => {
    input.value = result.portalUrl || DEFAULT_PORTAL_URL;
  });
  document.getElementById('save').addEventListener('click', () => {
    const url = input.value.trim();
    chrome.storage.sync.set({ portalUrl: url }, () => {
      status.textContent = 'Salvo. Abra app-hub.lexos.com.br logado para sincronizar.';
    });
  });
});
