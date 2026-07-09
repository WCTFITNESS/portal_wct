# Conector Lexos → Portal WCT

Extensão Chrome que sincroniza automaticamente o `access_token` do **app-hub.lexos.com.br** com o Portal (aba **Produtos** do Dashboard).

## Instalação (1x)

1. Chrome → `chrome://extensions`
2. Ative **Modo do desenvolvedor**
3. **Carregar sem compactação** → selecione esta pasta (`tools/lexos-portal-sync`)
4. Clique com o botão direito na extensão → **Opções**
5. Cole a URL do seu portal: `https://SEU-PORTAL/index.php?page=api-config`

## Uso

1. Faça login em [app-hub.lexos.com.br](https://app-hub.lexos.com.br)
2. A extensão envia o token ao portal automaticamente (a cada 5 min ou ao abrir o Hub)
3. Abra **Mercado Livre → Dashboard → Produtos** no portal

O OAuth do Tracking **não** serve para Produtos — só o Token Hub.
