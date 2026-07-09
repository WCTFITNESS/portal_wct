# Portal WCT — Conector Lexos Hub

Extensão Chrome que replica a **autenticação do plugin Faturamento** para o Portal WCT.

## Por que existe?

O plugin Faturamento funciona porque:

1. Roda no navegador com acesso ao `localStorage.access_token` de **app-hub.lexos.com.br**
2. Chama a API `app-hub-webapi.lexos.com.br` com `Authorization: Bearer …`

O Portal é um site PHP no Render — **não pode** ler o login do Hub sozinho (segurança do navegador). Esta extensão faz a ponte.

## Instalação (1x)

1. Chrome → `chrome://extensions`
2. Ativar **Modo desenvolvedor**
3. **Carregar sem compactação** → pasta `tools/lexos-portal-sync`
4. Opções da extensão → URL do portal:
   `https://portal-wct.onrender.com/index.php?page=api-config`

## Uso

1. Faça login em [app-hub.lexos.com.br](https://app-hub.lexos.com.br)
2. Abra o Dashboard do Portal → aba **Produtos**
3. A extensão carrega os dados **igual ao popup do Faturamento**

Também sincroniza o token com o servidor do Portal (fallback PHP).

## Compatibilidade com Faturamento

| | Plugin Faturamento | Esta extensão |
|---|-------------------|---------------|
| Token Hub | `chrome.storage.local.lexosToken` | **Mesma chave** |
| Origem do token | `localStorage.access_token` no Hub | **Igual** |
| API Produtos | `DataSourceCurvaAbc` + Bearer | **Igual** |
| OAuth Tracking | Não usa | Não usa |

Pode manter os dois instalados. Se já usa o Faturamento, abra o Hub uma vez — esta extensão captura o mesmo token.

## Arquivos

- `content-hub.js` — captura token no Hub (como `content.js` do Faturamento)
- `content-portal.js` — ponte de fetch na página do Portal
- `background.js` — proxy HTTP com Bearer (como `popup.js`)

O OAuth do Tracking **não** serve para Produtos — só o Token Hub.
