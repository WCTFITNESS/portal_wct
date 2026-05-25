#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Mercado Livre – OAuth (nova conexão)
====================================

Script auxiliar para quem vai configurar uma NOVA integração ML,
no mesmo fluxo usado pelo Portal WCT (Configurar API + refresh token).

Requisitos: Python 3.8+ (apenas biblioteca padrão).

Exemplos
--------

1) Gerar link para o vendedor autorizar:

    python mercado_livre_oauth.py url \\
        --app-id SEU_APP_ID \\
        --redirect-uri "https://seu-dominio.com.br/callback"

2) Trocar o "code" (da URL após autorizar) por tokens:

    python mercado_livre_oauth.py exchange \\
        --app-id SEU_APP_ID \\
        --client-secret SEU_CLIENT_SECRET \\
        --redirect-uri "https://seu-dominio.com.br/callback" \\
        --code "TG-xxxxxxxx"

3) Renovar access token (refresh):

    python mercado_livre_oauth.py refresh \\
        --app-id SEU_APP_ID \\
        --client-secret SEU_CLIENT_SECRET \\
        --refresh-token "TG-xxxxxxxx"

4) Testar se o access token funciona:

    python mercado_livre_oauth.py test --access-token "APP_USR-..."

Os tokens são salvos em ml_tokens.json (na pasta atual). NÃO envie esse arquivo
para repositório público — contém segredos.

Depois, copie access_token, refresh_token e expires_in para o portal:
  Configurar API > Token OAuth > Salvar Token
"""

from __future__ import annotations

import argparse
import json
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any

API_BASE = "https://api.mercadolibre.com"
AUTH_BASE_BR = "https://auth.mercadolivre.com.br"
TOKENS_FILE = Path("ml_tokens.json")

_DOCS_DIR = Path(__file__).resolve().parent
if str(_DOCS_DIR) not in sys.path:
    sys.path.insert(0, str(_DOCS_DIR))

from ml_oauth_lib import (  # noqa: E402
    build_auth_url,
    exchange_code,
    oauth_error_hint,
    refresh_access_token,
)


def _post_form(url: str, data: dict[str, str]) -> tuple[int, dict[str, Any], str]:
    body = urllib.parse.urlencode(data).encode("utf-8")
    req = urllib.request.Request(
        url,
        data=body,
        method="POST",
        headers={"Content-Type": "application/x-www-form-urlencoded", "Accept": "application/json"},
    )
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
            return resp.status, json.loads(raw) if raw else {}, raw
    except urllib.error.HTTPError as e:
        raw = e.read().decode("utf-8", errors="replace")
        try:
            parsed = json.loads(raw) if raw else {}
        except json.JSONDecodeError:
            parsed = {"raw": raw}
        return e.code, parsed, raw


def _get_json(url: str, access_token: str) -> tuple[int, dict[str, Any], str]:
    req = urllib.request.Request(
        url,
        method="GET",
        headers={"Authorization": f"Bearer {access_token}", "Accept": "application/json"},
    )
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
            return resp.status, json.loads(raw) if raw else {}, raw
    except urllib.error.HTTPError as e:
        raw = e.read().decode("utf-8", errors="replace")
        try:
            parsed = json.loads(raw) if raw else {}
        except json.JSONDecodeError:
            parsed = {"raw": raw}
        return e.code, parsed, raw


def cmd_url(args: argparse.Namespace) -> int:
    link = build_auth_url(args.app_id, args.redirect_uri)
    if args.state:
        link += "&" + urllib.parse.urlencode({"state": args.state})
    print("\n=== PASSO 1: Autorização no navegador ===\n")
    print("Abra este link logado na conta Mercado Livre que será integrada:\n")
    print(link)
    print("\nApós aceitar, copie o parâmetro 'code' da URL de retorno.")
    print("Em seguida rode o comando 'exchange' com esse code.\n")
    return 0


def _save_tokens(payload: dict[str, Any], extra: dict[str, Any] | None = None) -> None:
    data: dict[str, Any] = {
        "saved_at": datetime.now().isoformat(timespec="seconds"),
        **payload,
    }
    if extra:
        data.update(extra)
    TOKENS_FILE.write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"\nTokens gravados em: {TOKENS_FILE.resolve()}\n")


def _print_portal_instructions(body: dict[str, Any]) -> None:
    access = body.get("access_token", "")
    refresh = body.get("refresh_token", "")
    expires_in = body.get("expires_in", "")
    user_id = body.get("user_id", "")

    print("=== Cole no Portal WCT (Configurar API) ===\n")
    print("Configuração API Mercado Livre:")
    print(f"  App ID         = (o mesmo usado neste script)")
    print(f"  Client Secret  = (o mesmo usado neste script)")
    print(f"  Seller ID      = {user_id}")
    print("\nToken OAuth:")
    print(f"  Access Token   = {access}")
    print(f"  Refresh Token  = {refresh}")
    print(f"  Expira em (s)  = {expires_in}")
    if expires_in:
        try:
            exp = datetime.now() + timedelta(seconds=int(expires_in))
            print(f"  (aprox. expira em {exp.strftime('%d/%m/%Y %H:%M')})")
        except (TypeError, ValueError):
            pass
    print()


def cmd_exchange(args: argparse.Namespace) -> int:
    print("\n=== PASSO 2: Trocar code por tokens ===\n")
    status, body, raw = exchange_code(
        args.app_id,
        args.client_secret,
        args.redirect_uri,
        args.code,
    )
    if status < 200 or status >= 300:
        print(f"ERRO HTTP {status}")
        print(raw)
        if isinstance(body, dict):
            print("\n" + oauth_error_hint(body))
        return 1

    _save_tokens(
        body,
        {"app_id": args.app_id, "redirect_uri": args.redirect_uri, "flow": "authorization_code"},
    )
    _print_portal_instructions(body)
    return 0


def cmd_refresh(args: argparse.Namespace) -> int:
    print("\n=== Refresh token ===\n")
    status, body, raw = refresh_access_token(
        args.app_id,
        args.client_secret,
        args.refresh_token,
    )
    if status < 200 or status >= 300:
        print(f"ERRO HTTP {status}")
        print(raw)
        if isinstance(body, dict):
            print("\n" + oauth_error_hint(body))
        return 1

    _save_tokens(body, {"app_id": args.app_id, "flow": "refresh_token"})
    _print_portal_instructions(body)
    print("No portal você também pode usar o botão 'Executar Refresh Agora'.")
    return 0


def cmd_test(args: argparse.Namespace) -> int:
    print("\n=== Teste GET /users/me ===\n")
    status, body, raw = _get_json(f"{API_BASE}/users/me", args.access_token)
    if status < 200 or status >= 300:
        print(f"ERRO HTTP {status}")
        print(raw)
        return 1
    print(json.dumps(body, indent=2, ensure_ascii=False))
    print(f"\nSeller ID sugerido para o portal: {body.get('id')}\n")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Auxiliar OAuth Mercado Livre (nova conexão – estilo Portal WCT)",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__,
    )
    sub = parser.add_subparsers(dest="command", required=True)

    p_url = sub.add_parser("url", help="Montar link de autorização")
    p_url.add_argument("--app-id", required=True, help="App ID / Client ID do Developers")
    p_url.add_argument("--redirect-uri", required=True, help="Redirect URI cadastrada no app ML")
    p_url.add_argument("--state", default="", help="Opcional: state na URL")
    p_url.set_defaults(func=cmd_url)

    p_ex = sub.add_parser("exchange", help="Trocar authorization code por tokens")
    p_ex.add_argument("--app-id", required=True)
    p_ex.add_argument("--client-secret", required=True)
    p_ex.add_argument("--redirect-uri", required=True)
    p_ex.add_argument("--code", required=True, help="Code copiado da URL após autorizar")
    p_ex.set_defaults(func=cmd_exchange)

    p_rf = sub.add_parser("refresh", help="Renovar access token")
    p_rf.add_argument("--app-id", required=True)
    p_rf.add_argument("--client-secret", required=True)
    p_rf.add_argument("--refresh-token", required=True)
    p_rf.set_defaults(func=cmd_refresh)

    p_ts = sub.add_parser("test", help="Testar access token (users/me)")
    p_ts.add_argument("--access-token", required=True)
    p_ts.set_defaults(func=cmd_test)

    args = parser.parse_args()
    return int(args.func(args))


if __name__ == "__main__":
    sys.exit(main())
