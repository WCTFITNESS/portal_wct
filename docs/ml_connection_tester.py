#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Testador local de conexao Mercado Livre (OAuth + API).

Requisitos: Python 3.8+ com tkinter (ja vem no instalador padrao do Windows).

Executar:
    python docs/ml_connection_tester.py

Ou duplo clique em: docs/run_ml_connection_tester.bat
"""

from __future__ import annotations

import json
import sys
import threading
import webbrowser
from datetime import datetime
from pathlib import Path
import tkinter as tk
from tkinter import END, BOTH, LEFT, X, Y, messagebox, scrolledtext, ttk

# Garante import do modulo ao lado deste arquivo
_DOCS_DIR = Path(__file__).resolve().parent
if str(_DOCS_DIR) not in sys.path:
    sys.path.insert(0, str(_DOCS_DIR))

from ml_oauth_lib import (  # noqa: E402
    API_BASE,
    build_auth_url,
    exchange_code,
    fetch_orders_sample,
    fetch_users_me,
    format_expires_hint,
    load_tokens_file,
    normalize_credential,
    normalize_oauth_code,
    oauth_error_hint,
    refresh_access_token,
    save_tokens_file,
)

TOKENS_FILE = _DOCS_DIR / "ml_tokens.json"


class MlConnectionTesterApp(ttk.Frame):
    def __init__(self, master: tk.Tk) -> None:
        super().__init__(master, padding=12)
        self.master = master
        self.pack(fill=BOTH, expand=True)

        self.var_app_id = tk.StringVar()
        self.var_client_secret = tk.StringVar()
        self.var_redirect_uri = tk.StringVar(value="https://localhost/callback")
        self.var_code = tk.StringVar()
        self.var_access_token = tk.StringVar()
        self.var_refresh_token = tk.StringVar()
        self.var_expires_in = tk.StringVar(value="21600")
        self.var_seller_id = tk.StringVar()
        self._last_auth_url = ""

        self._build_ui()
        self._load_from_file(silent=True)

    def _build_ui(self) -> None:
        self.master.title("Teste de conexao - Mercado Livre")
        self.master.minsize(720, 640)
        self.master.geometry("860x720")

        title = ttk.Label(self, text="Testador ML (nova conexao)", font=("Segoe UI", 14, "bold"))
        title.pack(anchor="w")
        ttk.Label(
            self,
            text="Preencha os dados do app, autorize no ML, troque o code por tokens e teste a API.",
            foreground="#555",
        ).pack(anchor="w", pady=(0, 10))

        app_frame = ttk.LabelFrame(self, text="1. Aplicativo (Developers)", padding=10)
        app_frame.pack(fill=X, pady=(0, 8))
        self._row(app_frame, "App ID", self.var_app_id, 0)
        self._row(app_frame, "Client Secret", self.var_client_secret, 1)
        self._row(app_frame, "Redirect URI", self.var_redirect_uri, 2)

        oauth_frame = ttk.LabelFrame(self, text="2. Autorizacao OAuth", padding=10)
        oauth_frame.pack(fill=X, pady=(0, 8))
        self._row(oauth_frame, "Code (da URL)", self.var_code, 0, width=50)

        oauth_btns = ttk.Frame(oauth_frame)
        oauth_btns.grid(row=1, column=0, columnspan=2, sticky="w", pady=(8, 0))
        ttk.Button(oauth_btns, text="Gerar link", command=self._on_build_url).pack(side=LEFT, padx=(0, 6))
        ttk.Button(oauth_btns, text="Abrir no navegador", command=self._on_open_browser).pack(side=LEFT, padx=(0, 6))
        ttk.Button(oauth_btns, text="Trocar code por tokens", command=self._on_exchange).pack(side=LEFT)

        token_frame = ttk.LabelFrame(self, text="3. Tokens", padding=10)
        token_frame.pack(fill=X, pady=(0, 8))
        self._row(token_frame, "Access Token", self.var_access_token, 0, width=50)
        self._row(token_frame, "Refresh Token", self.var_refresh_token, 1, width=50)
        self._row(token_frame, "Expira em (seg)", self.var_expires_in, 2, width=12)
        self._row(token_frame, "Seller ID", self.var_seller_id, 3, width=20)

        test_frame = ttk.LabelFrame(self, text="4. Testes da API", padding=10)
        test_frame.pack(fill=X, pady=(0, 8))
        ttk.Button(test_frame, text="Testar /users/me (conta)", command=self._on_test_me).pack(side=LEFT, padx=(0, 6))
        ttk.Button(test_frame, text="Refresh token", command=self._on_refresh).pack(side=LEFT, padx=(0, 6))
        ttk.Button(test_frame, text="Testar pedidos (paid)", command=self._on_test_orders).pack(side=LEFT, padx=(0, 6))
        ttk.Button(test_frame, text="Salvar JSON", command=self._save_to_file).pack(side=LEFT, padx=(0, 6))
        ttk.Button(test_frame, text="Carregar JSON", command=lambda: self._load_from_file(silent=False)).pack(side=LEFT)

        log_frame = ttk.LabelFrame(self, text="Log", padding=8)
        log_frame.pack(fill=BOTH, expand=True)
        self.log = scrolledtext.ScrolledText(log_frame, height=14, wrap="word", font=("Consolas", 9))
        self.log.pack(fill=BOTH, expand=True)

        self._log(f"API base: {API_BASE}")
        self._log(f"Arquivo local: {TOKENS_FILE}")

    def _row(
        self,
        parent: ttk.LabelFrame,
        label: str,
        variable: tk.StringVar,
        row: int,
        width: int = 40,
    ) -> None:
        ttk.Label(parent, text=label).grid(row=row, column=0, sticky="w", pady=4)
        entry = ttk.Entry(parent, textvariable=variable, width=width)
        entry.grid(row=row, column=1, sticky="ew", padx=(8, 0), pady=4)
        parent.columnconfigure(1, weight=1)

    def _log(self, msg: str) -> None:
        ts = datetime.now().strftime("%H:%M:%S")
        self.log.insert(END, f"[{ts}] {msg}\n")
        self.log.see(END)

    def _run_async(self, label: str, fn) -> None:
        def worker() -> None:
            self._log(f"--- {label} ---")
            try:
                fn()
            except Exception as exc:  # noqa: BLE001
                self._log(f"ERRO: {exc}")
                messagebox.showerror("Erro", str(exc))

        threading.Thread(target=worker, daemon=True).start()

    def _require_app(self) -> bool:
        if not self.var_app_id.get().strip():
            messagebox.showwarning("Campos", "Informe o App ID.")
            return False
        if not self.var_client_secret.get().strip():
            messagebox.showwarning("Campos", "Informe o Client Secret.")
            return False
        return True

    def _apply_token_response(self, body: dict) -> None:
        if body.get("access_token"):
            self.var_access_token.set(str(body["access_token"]))
        if body.get("refresh_token"):
            self.var_refresh_token.set(str(body["refresh_token"]))
        if body.get("expires_in") is not None:
            self.var_expires_in.set(str(body["expires_in"]))
        if body.get("user_id") is not None:
            self.var_seller_id.set(str(body["user_id"]))
        hint = format_expires_hint(body.get("expires_in"))
        if hint:
            self._log(f"Token expira aprox. em: {hint}")

    def _snapshot(self) -> dict:
        return {
            "saved_at": datetime.now().isoformat(timespec="seconds"),
            "app_id": normalize_credential(self.var_app_id.get()),
            "client_secret": normalize_credential(self.var_client_secret.get()),
            "redirect_uri": normalize_credential(self.var_redirect_uri.get()),
            "oauth_code": normalize_oauth_code(self.var_code.get()),
            "access_token": self.var_access_token.get().strip(),
            "refresh_token": self.var_refresh_token.get().strip(),
            "expires_in": self.var_expires_in.get().strip(),
            "user_id": self.var_seller_id.get().strip(),
        }

    def _load_snapshot(self, data: dict) -> None:
        self.var_app_id.set(str(data.get("app_id", "")))
        self.var_client_secret.set(str(data.get("client_secret", "")))
        self.var_redirect_uri.set(str(data.get("redirect_uri", self.var_redirect_uri.get())))
        self.var_code.set(str(data.get("oauth_code", data.get("code", ""))))
        self.var_access_token.set(str(data.get("access_token", "")))
        self.var_refresh_token.set(str(data.get("refresh_token", "")))
        if data.get("expires_in") is not None:
            self.var_expires_in.set(str(data["expires_in"]))
        uid = data.get("user_id", data.get("seller_id", ""))
        if uid:
            self.var_seller_id.set(str(uid))

    def _on_build_url(self) -> None:
        if not self.var_app_id.get().strip() or not self.var_redirect_uri.get().strip():
            messagebox.showwarning("Campos", "Informe App ID e Redirect URI.")
            return
        self._last_auth_url = build_auth_url(self.var_app_id.get(), self.var_redirect_uri.get())
        self._log("Link de autorizacao:")
        self._log(self._last_auth_url)
        messagebox.showinfo("Link gerado", "Link copiado para o log.\nUse 'Abrir no navegador'.")

    def _on_open_browser(self) -> None:
        if not self._last_auth_url:
            self._on_build_url()
        if self._last_auth_url:
            webbrowser.open(self._last_auth_url)
            self._log("Navegador aberto. Apos autorizar, cole o 'code' no campo.")

    def _on_exchange(self) -> None:
        if not self._require_app():
            return
        if not self.var_redirect_uri.get().strip():
            messagebox.showwarning("Campos", "Informe a Redirect URI.")
            return
        code = normalize_oauth_code(self.var_code.get())
        if not code:
            messagebox.showwarning("Campos", "Cole o code retornado pelo ML (TG-...) ou a URL completa.")
            return
        self.var_code.set(code)

        def work() -> None:
            app_id = normalize_credential(self.var_app_id.get())
            client_secret = normalize_credential(self.var_client_secret.get())
            redirect_uri = normalize_credential(self.var_redirect_uri.get())
            self._log(f"App ID: {app_id} | Secret: {len(client_secret)} chars | Redirect: {redirect_uri}")
            self._log(f"Code: {code[:12]}... ({len(code)} chars)")

            status, body, raw = exchange_code(app_id, client_secret, redirect_uri, code)
            self._log(f"HTTP {status}")
            if status < 200 or status >= 300:
                self._log(raw)
                hint = oauth_error_hint(body) if isinstance(body, dict) else "Veja o log."
                messagebox.showerror("Falha", f"Troca do code falhou (HTTP {status}).\n\n{hint}")
                return
            self._apply_token_response(body)
            self._save_to_file(silent=True)
            self._log("Tokens obtidos com sucesso.")
            self._log_json(body)
            messagebox.showinfo("OK", "Conexao inicial OK. Tokens preenchidos.")

        self._run_async("Trocar code", work)

    def _on_refresh(self) -> None:
        if not self._require_app():
            return
        if not self.var_refresh_token.get().strip():
            messagebox.showwarning("Campos", "Informe o Refresh Token.")
            return

        def work() -> None:
            status, body, raw = refresh_access_token(
                self.var_app_id.get(),
                self.var_client_secret.get(),
                self.var_refresh_token.get(),
            )
            self._log(f"HTTP {status}")
            if status < 200 or status >= 300:
                self._log(raw)
                hint = oauth_error_hint(body) if isinstance(body, dict) else "Veja o log."
                messagebox.showerror("Falha", f"Refresh falhou (HTTP {status}).\n\n{hint}")
                return
            self._apply_token_response(body)
            self._save_to_file(silent=True)
            self._log("Refresh OK.")
            self._log_json(body)
            messagebox.showinfo("OK", "Refresh token executado com sucesso.")

        self._run_async("Refresh token", work)

    def _on_test_me(self) -> None:
        if not self.var_access_token.get().strip():
            messagebox.showwarning("Campos", "Informe o Access Token.")
            return

        def work() -> None:
            status, body, raw = fetch_users_me(self.var_access_token.get())
            self._log(f"GET /users/me -> HTTP {status}")
            if status < 200 or status >= 300:
                self._log(raw)
                messagebox.showerror("Falha", f"Token invalido ou sem permissao (HTTP {status}).")
                return
            seller = body.get("id", "")
            nickname = body.get("nickname", "")
            self.var_seller_id.set(str(seller))
            self._log_json(body)
            messagebox.showinfo(
                "API OK",
                f"Conexao validada.\nSeller ID: {seller}\nConta: {nickname}",
            )

        self._run_async("Testar /users/me", work)

    def _on_test_orders(self) -> None:
        token = self.var_access_token.get().strip()
        seller = self.var_seller_id.get().strip()
        if not token:
            messagebox.showwarning("Campos", "Informe o Access Token.")
            return
        if not seller:
            messagebox.showwarning("Campos", "Informe o Seller ID (ou rode Testar /users/me antes).")
            return

        def work() -> None:
            status, body, raw = fetch_orders_sample(token, seller, limit=5)
            self._log(f"GET /orders/search -> HTTP {status}")
            if status < 200 or status >= 300:
                self._log(raw)
                messagebox.showerror("Falha", f"Busca de pedidos falhou (HTTP {status}).")
                return
            results = body.get("results", [])
            total = body.get("paging", {}).get("total", len(results) if isinstance(results, list) else "?")
            self._log(f"Pedidos paid encontrados (amostra): {len(results) if isinstance(results, list) else 0} | total paging: {total}")
            if isinstance(results, list):
                for i, order in enumerate(results[:5], start=1):
                    if isinstance(order, dict):
                        self._log(f"  {i}. id={order.get('id')} status={order.get('status')}")
            self._log_json(body)
            messagebox.showinfo("API OK", "Consulta de pedidos funcionou. Detalhes no log.")

        self._run_async("Testar pedidos", work)

    def _log_json(self, data: dict) -> None:
        self._log(json.dumps(data, indent=2, ensure_ascii=False))

    def _save_to_file(self, silent: bool = False) -> None:
        try:
            save_tokens_file(TOKENS_FILE, self._snapshot())
            self._log(f"Salvo em {TOKENS_FILE}")
            if not silent:
                messagebox.showinfo("Salvo", f"Dados gravados em:\n{TOKENS_FILE}")
        except OSError as exc:
            messagebox.showerror("Erro", str(exc))

    def _load_from_file(self, silent: bool) -> None:
        try:
            if not TOKENS_FILE.is_file():
                if not silent:
                    messagebox.showinfo("Arquivo", "Nenhum ml_tokens.json encontrado ainda.")
                return
            data = load_tokens_file(TOKENS_FILE)
            self._load_snapshot(data)
            self._log("Dados carregados de ml_tokens.json")
            if not silent:
                messagebox.showinfo("Carregado", "Campos preenchidos a partir do JSON.")
        except (OSError, json.JSONDecodeError) as exc:
            messagebox.showerror("Erro", str(exc))


def main() -> None:
    root = tk.Tk()
    style = ttk.Style()
    if "vista" in style.theme_names():
        style.theme_use("vista")
    MlConnectionTesterApp(root)
    root.mainloop()


if __name__ == "__main__":
    main()
