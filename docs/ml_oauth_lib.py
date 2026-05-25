"""Funcoes OAuth/API Mercado Livre (compartilhadas CLI + GUI)."""

from __future__ import annotations

import json
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any

API_BASE = "https://api.mercadolibre.com"
AUTH_BASE_BR = "https://auth.mercadolivre.com.br"


def normalize_credential(value: Any) -> str:
    """Remove espacos, quebras e caracteres invisiveis de copia/cola."""
    if value is None:
        return ""
    text = str(value).strip()
    for ch in ("\ufeff", "\u200b", "\u200c", "\u200d", "\xa0"):
        text = text.replace(ch, "")
    return text.strip()


def normalize_oauth_code(value: Any) -> str:
    """
    Aceita so o code (TG-...) ou URL completa de retorno / query ?code=...
    """
    raw = normalize_credential(value)
    if not raw:
        return ""

    if "://" in raw or raw.startswith("?"):
        parsed = urllib.parse.urlparse(raw if "://" in raw else f"https://local.invalid/{raw.lstrip('?')}")
        if not parsed.query and "?" in raw:
            parsed = urllib.parse.urlparse(f"https://local.invalid/?{raw.split('?', 1)[1]}")
        params = urllib.parse.parse_qs(parsed.query)
        if params.get("code"):
            return urllib.parse.unquote(params["code"][0]).strip()

    if "code=" in raw.lower():
        fragment = raw.split("code=", 1)[1]
        return urllib.parse.unquote(fragment.split("&", 1)[0]).strip()

    return urllib.parse.unquote(raw.split("&", 1)[0]).strip()


def oauth_error_hint(body: dict[str, Any]) -> str:
    err = str(body.get("error", "")).strip()
    desc = str(body.get("error_description", "")).strip()

    if err == "invalid_client":
        return (
            "App ID ou Client Secret invalidos (ou de apps diferentes).\n\n"
            "Verifique no Mercado Livre Developers:\n"
            "• App ID = Client ID do aplicativo\n"
            "• Client Secret atual (se regenerou, copie o novo)\n"
            "• Mesmo par usado ao clicar em Gerar link / autorizar\n"
            "• Sem espacos extras ao colar"
        )
    if err == "invalid_grant":
        return (
            "Code invalido, expirado, ja usado ou Redirect URI diferente da autorizacao.\n\n"
            "• Gere um code novo (autorize de novo no navegador)\n"
            "• Redirect URI identica a do app (http/https, barra final)\n"
            "• Cole apenas o code (TG-...) ou a URL completa de retorno"
        )
    if desc:
        return desc
    return err or "Erro OAuth"


def post_form(url: str, data: dict[str, str]) -> tuple[int, dict[str, Any], str]:
    body = urllib.parse.urlencode(data).encode("utf-8")
    req = urllib.request.Request(
        url,
        data=body,
        method="POST",
        headers={"Content-Type": "application/x-www-form-urlencoded", "Accept": "application/json"},
    )
    return _request(req)


def post_json(url: str, payload: dict[str, Any]) -> tuple[int, dict[str, Any], str]:
    body = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        url,
        data=body,
        method="POST",
        headers={"Content-Type": "application/json", "Accept": "application/json"},
    )
    return _request(req)


def get_json(url: str, access_token: str) -> tuple[int, dict[str, Any], str]:
    req = urllib.request.Request(
        url,
        method="GET",
        headers={"Authorization": f"Bearer {access_token}", "Accept": "application/json"},
    )
    return _request(req)


def _request(req: urllib.request.Request) -> tuple[int, dict[str, Any], str]:
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
            parsed: dict[str, Any] = json.loads(raw) if raw else {}
            return resp.status, parsed, raw
    except urllib.error.HTTPError as e:
        raw = e.read().decode("utf-8", errors="replace")
        try:
            parsed = json.loads(raw) if raw else {}
        except json.JSONDecodeError:
            parsed = {"raw": raw}
        return e.code, parsed, raw
    except urllib.error.URLError as e:
        return 0, {"error": str(e.reason)}, str(e.reason)


def build_auth_url(app_id: str, redirect_uri: str) -> str:
    params = {
        "response_type": "code",
        "client_id": normalize_credential(app_id),
        "redirect_uri": normalize_credential(redirect_uri),
    }
    return f"{AUTH_BASE_BR}/authorization?{urllib.parse.urlencode(params)}"


def _token_payload(
    grant_type: str,
    app_id: str,
    client_secret: str,
    **extra: str,
) -> dict[str, str]:
    return {
        "grant_type": grant_type,
        "client_id": normalize_credential(app_id),
        "client_secret": normalize_credential(client_secret),
        **{k: normalize_credential(v) for k, v in extra.items()},
    }


def exchange_code(
    app_id: str, client_secret: str, redirect_uri: str, code: str
) -> tuple[int, dict[str, Any], str]:
    payload = _token_payload(
        "authorization_code",
        app_id,
        client_secret,
        code=normalize_oauth_code(code),
        redirect_uri=redirect_uri,
    )
    if not payload["client_id"] or not payload["client_secret"]:
        return 400, {"error": "missing_credentials", "error_description": "App ID e Client Secret sao obrigatorios."}, ""
    if not payload["code"]:
        return 400, {"error": "missing_code", "error_description": "Code OAuth nao informado ou invalido."}, ""

    # Documentacao ML: application/x-www-form-urlencoded
    status, body, raw = post_form(f"{API_BASE}/oauth/token", payload)
    if status == 400 and body.get("error") == "invalid_client":
        # Fallback: mesmo formato JSON usado no refresh do portal PHP
        status_json, body_json, raw_json = post_json(f"{API_BASE}/oauth/token", payload)
        if status_json >= 200 and status_json < 300:
            return status_json, body_json, raw_json
    return status, body, raw


def refresh_access_token(app_id: str, client_secret: str, refresh_token: str) -> tuple[int, dict[str, Any], str]:
    payload = _token_payload("refresh_token", app_id, client_secret, refresh_token=refresh_token)
    status, body, raw = post_form(f"{API_BASE}/oauth/token", payload)
    if status >= 200 and status < 300:
        return status, body, raw
    # Fallback JSON (portal WCT)
    return post_json(f"{API_BASE}/oauth/token", payload)


def fetch_users_me(access_token: str) -> tuple[int, dict[str, Any], str]:
    return get_json(f"{API_BASE}/users/me", access_token)


def fetch_orders_sample(access_token: str, seller_id: str, limit: int = 5) -> tuple[int, dict[str, Any], str]:
    q = urllib.parse.urlencode(
        {
            "seller": seller_id.strip(),
            "order.status": "paid",
            "sort": "date_desc",
            "limit": str(max(1, min(50, limit))),
        }
    )
    return get_json(f"{API_BASE}/orders/search?{q}", access_token)


def format_expires_hint(expires_in: Any) -> str:
    try:
        seconds = int(expires_in)
        exp = datetime.now() + timedelta(seconds=seconds)
        return exp.strftime("%d/%m/%Y %H:%M")
    except (TypeError, ValueError):
        return ""


def load_tokens_file(path: Path) -> dict[str, Any]:
    if not path.is_file():
        return {}
    return json.loads(path.read_text(encoding="utf-8"))


def save_tokens_file(path: Path, data: dict[str, Any]) -> None:
    path.write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding="utf-8")
