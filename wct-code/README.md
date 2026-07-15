# WCT Code (backup cPanel)

Aplicativo Node.js original do **wctcode.com**, copiado de:

`wct_code/wct_bkp/aplicacao_wctcode/aplicacao`

Este e o modulo de referencia no Portal WCT. O menu **Mercado Livre** tem uma reimplementacao PHP que ainda nao esta 100% igual ao backup.

## Rodar local

```powershell
cd ml-portal/wct-code
$env:WCT_CODE_PORT='3001'
$env:WCT_CODE_BASE_PATH='/wct-code-app'
$env:WCT_CODE_BYPASS_AUTH='1'
$env:PORTAL_HTTP_PORT='80'
$env:PORTAL_BASE_URL='/ml-portal'
npm start
```

Portal: `index.php?page=wct-code-dashboard` (e demais itens do menu WCT CODE).

Token ML vem da **Configuracao API** do portal.

## Arquivos de integracao (nao sobrescrever com backup)

- `app.js`, `token.js`
- `src/config/runtime.js`
- `src/middlewares/authMiddleware.js`
- `src/services/meli.js` (seller dinamico)
- `src/services/freteService.js` (token/DB por env)
- `src/routes/views.js` (redirects com basePath)
- `src/views/header.ejs`, `_base_head.ejs`

## Sincronizar logica do backup

Re-copiar controllers, rotas API, utils e views do backup e reaplicar os patches acima.
