# Portal WCT (PHP + XAMPP)

Portal modular para integração com Mercado Livre e Mercado Pago (WCT).

## Recursos implementados

- Configuração de API em tela intuitiva.
- Persistência de token e refresh token em banco de dados.
- Rotina de refresh automático ao detectar token próximo do vencimento.
- Tela para criar/editar mensagem de envio.
- Rotina para buscar pedidos pagos e enviar mensagem automaticamente.
- Tela para execução manual da rotina e teste de envio.
- Tela para listar pedidos do Mercado Livre com filtros.
- Tela de repasse com upload de planilha e geração de XLSX com número do pedido.

## Instalação local

1. Copie o projeto para `htdocs/portal_wct` (nome da pasta deve coincidir com `base_url` em `config/config.php`).
2. Crie o banco e tabelas executando `database.sql` no phpMyAdmin.
3. Ajuste credenciais em `config/config.php` se necessário.
4. Acesse: `http://localhost/portal_wct/index.php`.

## Fluxo recomendado

1. Acesse **Configurar API** e salve `app_id`, `client_secret`, `seller_id`.
2. Na mesma tela, salve `access_token`, `refresh_token` e validade em segundos.
3. Acesse **Mensagem** e configure o texto de agradecimento.
4. Acesse **Envio Manual** para:
   - Rodar a rotina de pedidos pagos na hora.
   - Enviar mensagem de teste.

## Automação no Windows

No Agendador de Tarefas do Windows, execute periodicamente:

```bash
php C:\xampp\htdocs\portal_wct\cron\process_completed_orders.php
```

Para manter o token sempre renovado, agende também a cada 5 horas:

```bash
php C:\xampp\htdocs\ml-portal\cron\refresh_token.php
```

Para manter o Token Hub Lexos (aba Produtos do Dashboard), agende a cada 1 hora:

```bash
php C:\xampp\htdocs\ml-portal\cron\refresh_lexos_hub.php
```

**Atalho:** execute `cron\agendar_tarefas_windows.bat` como administrador — cria as duas tarefas de uma vez (`MLPortal-RefreshToken-5h` e `MLPortal-RefreshLexosHub-1h`).

## Observação importante da API

Os endpoints do Mercado Livre podem exigir ajustes de payload e permissões por conta e categoria de integração.  
Se necessário, adapte os métodos em `app/Services/MercadoLivreClient.php` e `app/Services/OrderService.php`.
