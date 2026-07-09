# Manual: conectar uma conta ao Mercado Livre (OAuth)

Este guia explica, em linguagem simples, **como o Portal WCT se conecta ao Mercado Livre** e como seu amigo pode fazer **uma nova conexão** (outra conta ou outro aplicativo), no mesmo padrão.

Documentação oficial do Mercado Livre: [Developers – Autenticação e Autorização](https://developers.mercadolivre.com.br/pt_br/autenticacao-e-autorizacao)

---

## 1. O que é essa “conexão”?

Não é usuário e senha da conta ML. É um **aplicativo** criado no painel de desenvolvedor, que recebe permissão da conta vendedora e passa a usar a **API** com dois tipos de chave:

| Nome | Para que serve | Validade |
|------|----------------|----------|
| **Access token** | Chama a API (pedidos, mensagens, etc.) | Horas (ex.: 6 h) |
| **Refresh token** | Pede um access token novo sem o dono logar de novo | Meses (até revogar) |

No portal, isso fica em **Configurar API** (`index.php?page=api-config`):

- **Configuração API Mercado Livre** → dados do app (App ID, Client Secret, Seller ID, etc.)
- **Token OAuth** → access token, refresh token e tempo de expiração

No banco:

- Tabela `api_settings` → credenciais do app
- Tabela `oauth_tokens` → tokens ativos (sempre o último registro)

---

## 2. O que você precisa antes de começar

1. Conta **vendedora** no Mercado Livre (a que vai integrar).
2. Acesso ao [Mercado Livre Developers](https://developers.mercadolivre.com.br/) com essa conta.
3. Criar um **aplicativo** (app) no painel.
4. Definir uma **Redirect URI** (URL de retorno após o login).  
   - Exemplo local: `https://localhost/callback` ou uma URL que vocês controlem.  
   - **Tem que ser idêntica** à cadastrada no app (incluindo `http` vs `https` e barra no final).
5. Anotar:
   - **App ID** (também chamado Client ID)
   - **Client Secret** (segredo — não compartilhar)
   - **Redirect URI** cadastrada

---

## 3. Passo a passo (visão geral)

```
┌─────────────────┐     ┌──────────────────────┐     ┌─────────────────────┐
│ Criar app no    │ --> │ Dono da conta ML     │ --> │ Trocar "code" por   │
│ Developers      │     │ autoriza no navegador│     │ access + refresh    │
└─────────────────┘     └──────────────────────┘     └─────────────────────┘
                                                                  │
                                                                  v
                        ┌──────────────────────┐     ┌─────────────────────┐
                        │ Portal usa access    │ <-- │ Colar tokens na     │
                        │ token nas chamadas   │     │ tela Configurar API │
                        └──────────────────────┘     └─────────────────────┘
                                                                  │
                                                                  v
                        ┌──────────────────────┐
                        │ A cada X horas:      │
                        │ refresh_token renova │
                        │ o access token       │
                        └──────────────────────┘
```

---

## 4. Parte A – Cadastrar o app no portal (igual ao que já fazemos)

1. Abra o portal → menu **Configurar API**.
2. Bloco **Configuração API Mercado Livre**:
   - **App ID** → Client ID do Developers
   - **Client Secret** → segredo do app
   - **Redirect URI** → mesma URL cadastrada no ML (referência; o portal guarda mas o refresh não usa)
   - **Seller ID** → ID numérico da conta vendedora (ver passo 5)
   - **Code** → opcional; código temporário da autorização (só para referência)
3. Clique em **Salvar Configuração ML**.

---

## 5. Descobrir o Seller ID (ID da conta)

O **Seller ID** é o `user_id` da conta vendedora na API.

**No portal (depois de ter um access token válido):**

1. Preencha **Token OAuth** (passo 6) e salve.
2. Clique em **Verificar ID Correto do Token**.  
   O sistema chama `GET /users/me` e mostra o ID da conta ligada ao token.

**Manualmente (navegador ou Postman):**

```http
GET https://api.mercadolibre.com/users/me
Authorization: Bearer SEU_ACCESS_TOKEN
```

O campo `id` na resposta é o **Seller ID**.

---

## 6. Parte B – Obter o primeiro access token e refresh token

### 6.1 Gerar o link de autorização

Monte esta URL (Brasil usa `auth.mercadolivre.com.br`):

```
https://auth.mercadolivre.com.br/authorization?response_type=code&client_id=SEU_APP_ID&redirect_uri=SUA_REDIRECT_URI_ENCODADA
```

- Troque `SEU_APP_ID` pelo App ID.
- `SUA_REDIRECT_URI_ENCODADA` = mesma redirect URI, com caracteres especiais codificados (espaço → `%20`, etc.).

**Forma fácil:** use o script Python incluído neste projeto:

```bash
python docs/mercado_livre_oauth.py url --app-id SEU_APP_ID --redirect-uri "https://seu-dominio/callback"
```

### 6.2 Autorizar no navegador

1. Abra o link **logado na conta ML** que será integrada.
2. Aceite as permissões.
3. O navegador redireciona para a Redirect URI com um parâmetro na URL, por exemplo:  
   `https://seu-dominio/callback?code=TG-1234567890abcdef...`
4. Copie o valor de **`code`** (válido poucos minutos — use logo).

### 6.3 Trocar o `code` por tokens

Chamada à API (o portal faz o mesmo tipo de troca no refresh; a troca inicial hoje é **manual** ou via script):

```http
POST https://api.mercadolibre.com/oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code
&client_id=SEU_APP_ID
&client_secret=SEU_CLIENT_SECRET
&code=CODE_COPIADO_DA_URL
&redirect_uri=SUA_REDIRECT_URI
```

Resposta típica (JSON):

```json
{
  "access_token": "APP_USR-...",
  "token_type": "Bearer",
  "expires_in": 21600,
  "refresh_token": "TG-...",
  "scope": "...",
  "user_id": 123456789
}
```

**Com o script Python:**

```bash
python docs/mercado_livre_oauth.py exchange --app-id SEU_APP_ID --client-secret SEU_SECRET --redirect-uri "https://seu-dominio/callback" --code "TG-..."
```

O script grava `ml_tokens.json` e mostra o que colar no portal.

### 6.4 Colar no portal

1. **Configurar API** → bloco **Token OAuth**:
   - **Access Token** → valor de `access_token`
   - **Refresh Token** → valor de `refresh_token`
   - **Expira em (segundos)** → valor de `expires_in` (ex.: `21600` = 6 horas)
2. **Salvar Token**.
3. (Opcional) **Executar Refresh Agora** para testar.
4. **Verificar ID Correto do Token** e confira se o Seller ID bate.

---

## 7. Refresh token – como funciona no portal

O access token **expira**. Sem renovar, pedidos e mensagens param de funcionar.

### 7.1 Renovação automática “na hora”

Ao usar o sistema, `TokenService::getValidAccessToken()`:

1. Lê o o token no banco.
2. Se faltar **menos de 2 minutos** para expirar → chama refresh automaticamente.
3. Grava o novo access token, refresh token (se vier novo) e nova data de expiração.

### 7.2 Renovação manual na tela

**Configurar API** → **Executar Refresh Agora**  
Chama `POST https://api.mercadolibre.com/oauth/token` com:

```json
{
  "grant_type": "refresh_token",
  "client_id": "APP_ID",
  "client_secret": "CLIENT_SECRET",
  "refresh_token": "REFRESH_TOKEN_ATUAL"
}
```

(Igual ao `MercadoLivreClient::refreshToken` no PHP.)

### 7.3 Renovação agendada (Windows)

Arquivo: `cron/refresh_token.php`

Agende no **Agendador de Tarefas** a cada **5 horas**:

- Programa: `C:\xampp\php\php.exe`
- Argumentos: `C:\xampp\htdocs\ml-portal\cron\refresh_token.php`

Comando único (PowerShell como administrador):

```powershell
schtasks /Create /TN "MLPortal-RefreshToken-5h" /SC HOURLY /MO 5 /TR 'C:\xampp\php\php.exe C:\xampp\htdocs\ml-portal\cron\refresh_token.php' /F
```

### 7.3.1 Token Hub Lexos (aba Produtos) — a cada 1 hora

Arquivo: `cron/refresh_lexos_hub.php`

```powershell
schtasks /Create /TN "MLPortal-RefreshLexosHub-1h" /SC HOURLY /MO 1 /TR 'C:\xampp\php\php.exe C:\xampp\htdocs\ml-portal\cron\refresh_lexos_hub.php' /F
```

Ou execute `cron\agendar_tarefas_windows.bat` como administrador (cria as duas tarefas: ML + Lexos Hub).

### 7.4 Renovação com Python (nova conexão / teste)

```bash
python docs/mercado_livre_oauth.py refresh --app-id SEU_APP_ID --client-secret SEU_SECRET --refresh-token "TG-..."
```

---

## 8. Exemplo completo (fictício) – do zero ao portal

Use só como **modelo**. Troque todos os valores pelos reais do app/conta.

### Dados fictícios do app

| Campo | Exemplo |
|-------|---------|
| App ID | `1234567890123456` |
| Client Secret | `AbCdEfGhIjKlMnOpQrStUvWxYz` |
| Redirect URI | `https://minhaloja.com.br/ml/callback` |
| Seller ID (após autorizar) | `99887766` |

### Passo 1 – Link de autorização

**URL montada:**

```
https://auth.mercadolivre.com.br/authorization?response_type=code&client_id=1234567890123456&redirect_uri=https%3A%2F%2Fminhaloja.com.br%2Fml%2Fcallback
```

**Comando Python (gera o link automaticamente):**

```bash
cd c:\xampp\htdocs\ml-portal\docs
python mercado_livre_oauth.py url --app-id 1234567890123456 --redirect-uri "https://minhaloja.com.br/ml/callback"
```

### Passo 2 – URL de retorno (copiar o `code`)

Depois que o vendedor aceita, o navegador pode ir para:

```
https://minhaloja.com.br/ml/callback?code=TG-6789012345678901-041523-abc123def4567890-123456789
```

Copie **somente** a parte do `code` (sem `code=`):

```
TG-6789012345678901-041523-abc123def4567890-123456789
```

> O `code` expira em poucos minutos. Se der `invalid_grant`, gere o link de novo.

### Passo 3 – Trocar `code` por tokens

**curl (Windows PowerShell ou Git Bash):**

```bash
curl -X POST "https://api.mercadolibre.com/oauth/token" ^
  -H "Content-Type: application/x-www-form-urlencoded" ^
  -d "grant_type=authorization_code" ^
  -d "client_id=1234567890123456" ^
  -d "client_secret=AbCdEfGhIjKlMnOpQrStUvWxYz" ^
  -d "code=TG-6789012345678901-041523-abc123def4567890-123456789" ^
  -d "redirect_uri=https://minhaloja.com.br/ml/callback"
```

**Python (recomendado – já formata para o portal):**

```bash
python mercado_livre_oauth.py exchange ^
  --app-id 1234567890123456 ^
  --client-secret AbCdEfGhIjKlMnOpQrStUvWxYz ^
  --redirect-uri "https://minhaloja.com.br/ml/callback" ^
  --code "TG-6789012345678901-041523-abc123def4567890-123456789"
```

**Resposta de exemplo (`ml_tokens.json`):**

```json
{
  "saved_at": "2026-05-18 14:30:00",
  "access_token": "APP_USR-1234567890123456-051826-abc123def4567890123456789-123456789",
  "token_type": "Bearer",
  "expires_in": 21600,
  "refresh_token": "TG-6789012345678901-051826-xyz9876543210987654321-123456789",
  "scope": "offline_access read write",
  "user_id": 99887766,
  "app_id": "1234567890123456",
  "redirect_uri": "https://minhaloja.com.br/ml/callback",
  "flow": "authorization_code"
}
```

### Passo 4 – Preencher o portal

Acesse: `http://localhost/ml-portal/index.php?page=api-config`

| Campo na tela | Valor do exemplo |
|---------------|------------------|
| App ID | `1234567890123456` |
| Client Secret | `AbCdEfGhIjKlMnOpQrStUvWxYz` |
| Redirect URI | `https://minhaloja.com.br/ml/callback` |
| Seller ID | `99887766` |
| Code (opcional) | `TG-6789012345678901-041523-...` |
| Access Token | `APP_USR-1234567890...` |
| Refresh Token | `TG-6789012345678901-051826-...` |
| Expira em (segundos) | `21600` |

Clique **Salvar Configuração ML**, depois **Salvar Token**.

### Passo 5 – Testar

```bash
python mercado_livre_oauth.py test --access-token "APP_USR-1234567890123456-051826-abc123def4567890123456789-123456789"
```

No portal: **Verificar ID Correto do Token** → deve mostrar `99887766`.

### Passo 6 – Testar refresh

**Portal:** botão **Executar Refresh Agora**.

**Python:**

```bash
python mercado_livre_oauth.py refresh ^
  --app-id 1234567890123456 ^
  --client-secret AbCdEfGhIjKlMnOpQrStUvWxYz ^
  --refresh-token "TG-6789012345678901-051826-xyz9876543210987654321-123456789"
```

**Saída esperada do cron** (`php cron/refresh_token.php`):

```
2026-05-18 15:00:00 - Refresh token atualizado com sucesso.
```

---

## 9. Nova conexão para o seu amigo (checklist)

Use quando for **outra conta ML** ou **outro aplicativo**:

| # | Ação |
|---|------|
| 1 | Criar (ou usar) app no Developers |
| 2 | Cadastrar Redirect URI correta |
| 3 | Gerar link de autorização e logar na **conta dele** |
| 4 | Copiar `code` da URL de retorno |
| 5 | Trocar `code` por tokens (script Python ou Postman) |
| 6 | No portal dele: App ID, Secret, Seller ID, tokens |
| 7 | Testar: Verificar ID + uma chamada (ex. listar pedidos) |
| 8 | Agendar `refresh_token.php` no servidor dele |

**Importante:** cada conta/app tem seu próprio par access + refresh. Não reutilize tokens de outra loja.

---

## 10. Erros comuns

| Sintoma | Causa provável | O que fazer |
|---------|----------------|-------------|
| `invalid_grant` ao trocar code | Code expirado ou já usado | Gerar link de novo e repetir em minutos |
| `redirect_uri` mismatch | URI diferente da cadastrada | Igualar texto no app ML e na troca |
| HTTP 403 nas APIs | Seller ID errado ou token de outra conta | **Verificar ID Correto do Token** |
| Refresh falha | Refresh revogado ou app errado | Refazer autorização (passo 6) |
| Token expira e não renova | Cron não rodando | Agendar `refresh_token.php` |

Na tela **Últimos 10 requests** (final de Configurar API) dá para ver as chamadas a `/oauth/token` e o status HTTP.

---

## 11. Arquivos do projeto (referência técnica)

| Arquivo | Função |
|---------|--------|
| `pages/api-config.php` | Telas de configuração e botões |
| `app/Services/TokenService.php` | Salvar token e refresh |
| `app/Services/MercadoLivreClient.php` | Chamadas API + refresh |
| `app/Repositories/TokenRepository.php` | Banco `oauth_tokens` |
| `app/Repositories/SettingsRepository.php` | Banco `api_settings` |
| `cron/refresh_token.php` | Job agendado de refresh |
| `docs/mercado_livre_oauth.py` | Script Python (linha de comando) |
| `docs/ml_connection_tester.py` | Testador gráfico local (tkinter) |
| `docs/ml_oauth_lib.py` | Funções OAuth/API usadas pelo testador |

---

## 12. Testador gráfico (recomendado no PC local)

Para testar uma **nova conexão** sem usar o portal, use a janelinha em Python:

**Arquivo:** `docs/ml_connection_tester.py`

### Como abrir

```bash
cd c:\xampp\htdocs\ml-portal
python docs/ml_connection_tester.py
```

Ou dê **duplo clique** em `docs/run_ml_connection_tester.bat`.

Requisito: Python 3.8+ com **tkinter** (já vem no instalador oficial do Windows).

### Fluxo na tela

1. **Aplicativo** — preencha App ID, Client Secret e Redirect URI (iguais ao app no Developers).
2. **Autorização** — clique em **Gerar link** → **Abrir no navegador** → faça login na conta ML → copie o `code` da URL de retorno e cole no campo.
3. **Trocar code** — botão **Trocar code por tokens** preenche Access Token, Refresh Token, Seller ID e grava `docs/ml_tokens.json`.
4. **Testes** — **Testar /users/me** valida o token; **Testar pedidos (paid)** confirma leitura de pedidos; **Refresh token** renova o access token.
5. **Portal** — copie os valores para **Configurar API** no portal quando estiver tudo OK.

O log na parte inferior mostra HTTP status e JSON de resposta. Use **Salvar JSON** / **Carregar JSON** para retomar depois (arquivo local, não commitar).

---

## 13. Script Python (linha de comando)

Arquivo: **`docs/mercado_livre_oauth.py`**

Requisito: Python 3.8+ (só biblioteca padrão).

Comandos:

```bash
# 1) Mostrar URL para o dono da conta autorizar
python docs/mercado_livre_oauth.py url --app-id XXX --redirect-uri "https://..."

# 2) Trocar o code pelos tokens
python docs/mercado_livre_oauth.py exchange --app-id XXX --client-secret YYY --redirect-uri "https://..." --code "TG-..."

# 3) Renovar access token
python docs/mercado_livre_oauth.py refresh --app-id XXX --client-secret YYY --refresh-token "TG-..."

# 4) Testar token (users/me)
python docs/mercado_livre_oauth.py test --access-token "APP_USR-..."
```

Saída: arquivo `ml_tokens.json` em `docs/` (não commitar — contém segredos). O testador gráfico usa o mesmo arquivo.

---

*Manual alinhado ao Portal WCT (ml-portal). Em caso de mudança na API do Mercado Livre, confira sempre a documentação oficial.*
