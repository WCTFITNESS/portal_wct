# Conector Lexos Hub → Portal WCT (TI)

Sincroniza automaticamente o `access_token` do Hub para o portal — **sem favorito**.

## Instalação (1x, TI)

1. Chrome ou Edge → `chrome://extensions`
2. Ative **Modo do desenvolvedor**
3. **Carregar sem compactação** → pasta `tools/lexos-portal-sync`
4. Abra o **Portal no Render** (mesma URL de produção)
5. Menu → **Conectar Lexos Hub** → botão **Conectar automaticamente agora**
6. Faça login no Hub se pedir — em até ~30s o portal confirma **access ok**

A extensão re-sincroniza a cada 30s enquanto `app-hub.lexos.com.br` estiver aberto.

## Usuários finais

Não instalam nada. Usam só **Dashboard → Produtos**.

## Fallback manual

Favorito **Capturar Hub → Portal** na página Conectar Lexos Hub.
