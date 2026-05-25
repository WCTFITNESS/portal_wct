#!/usr/bin/env python3
"""
Mesclador de PDFs — Nota fiscal + Guia + Comprovante.

Uso:
  python pdf_mesclador_gui.py

Requisitos:
  pip install -r requirements.txt
"""

from __future__ import annotations

import sys
import threading
import tkinter as tk
from pathlib import Path
from tkinter import filedialog, messagebox, scrolledtext, ttk

from pdf_mesclador_core import processar_pasta


def main() -> None:
    root = tk.Tk()
    root.title("Mesclador de PDFs — Nota / Guia / Recibo")
    root.minsize(640, 480)
    root.geometry("720x520")

    pasta_var = tk.StringVar()

    frm = ttk.Frame(root, padding=12)
    frm.pack(fill=tk.BOTH, expand=True)

    ttk.Label(
        frm,
        text="Informe a pasta com os PDFs (notas, guias e recibos):",
        wraplength=680,
    ).pack(anchor=tk.W)

    row = ttk.Frame(frm)
    row.pack(fill=tk.X, pady=8)
    entry = ttk.Entry(row, textvariable=pasta_var)
    entry.pack(side=tk.LEFT, fill=tk.X, expand=True, padx=(0, 8))

    def escolher_pasta() -> None:
        d = filedialog.askdirectory(title="Selecionar pasta com PDFs")
        if d:
            pasta_var.set(d)

    ttk.Button(row, text="Procurar...", command=escolher_pasta).pack(side=tk.LEFT)

    regras = (
        "Fluxo:\n"
        "1) NOTA: le cada NF e grava no PDF novo\n"
        "2) GUIA: Nº Documento de Origem = numero da NF (1 pagina por guia)\n"
        "3) COMPROVANTE: codigo de barras (guia) = codigo de barras (comprovante), sem espacos\n"
        "4) Ordem: NOTA -> GUIA -> COMPROVANTE | Proxima nota repete"
    )
    ttk.Label(frm, text=regras, foreground="#334155", wraplength=680).pack(anchor=tk.W, pady=(4, 8))

    log_box = scrolledtext.ScrolledText(frm, height=16, state=tk.DISABLED, wrap=tk.WORD)
    log_box.pack(fill=tk.BOTH, expand=True, pady=8)

    def log(msg: str) -> None:
        log_box.configure(state=tk.NORMAL)
        log_box.insert(tk.END, msg + "\n")
        log_box.see(tk.END)
        log_box.configure(state=tk.DISABLED)

    btn_frame = ttk.Frame(frm)
    btn_frame.pack(fill=tk.X, pady=4)
    btn_processar = ttk.Button(btn_frame, text="Processar pasta")
    btn_processar.pack(side=tk.LEFT)

    def rodar() -> None:
        pasta = pasta_var.get().strip()
        if not pasta:
            messagebox.showwarning("Pasta", "Informe ou selecione uma pasta.")
            return
        path = Path(pasta)
        if not path.is_dir():
            messagebox.showerror("Pasta", f"Pasta invalida:\n{pasta}")
            return

        btn_processar.configure(state=tk.DISABLED)
        log_box.configure(state=tk.NORMAL)
        log_box.delete("1.0", tk.END)
        log_box.configure(state=tk.DISABLED)
        log("Iniciando...")

        def worker() -> None:
            try:
                gerados, avisos = processar_pasta(path, log=log)
                root.after(
                    0,
                    lambda: finalizar(gerados, avisos, None),
                )
            except Exception as exc:
                root.after(0, lambda: finalizar([], [], exc))

        threading.Thread(target=worker, daemon=True).start()

    def finalizar(gerados: list, avisos: list, erro: Exception | None) -> None:
        btn_processar.configure(state=tk.NORMAL)
        if erro is not None:
            log(f"ERRO: {erro}")
            messagebox.showerror("Erro", str(erro))
            return
        log(f"\nConcluido: {len(gerados)} arquivo(s) gerado(s).")
        if gerados:
            log(f"Pasta de saida: {gerados[0].parent}")
        messagebox.showinfo(
            "Concluido",
            f"{len(gerados)} PDF(s) mesclado(s).\n\nSaida:\n{gerados[0].parent if gerados else pasta_var.get()}",
        )

    btn_processar.configure(command=rodar)

    ttk.Label(
        frm,
        text="Dica: nomes com 'nfe', 'guia', 'comprovante' ajudam na classificacao. "
        "Ajuste regras em pdf_mesclador_core.py se seus PDFs forem diferentes.",
        font=("Segoe UI", 8),
        foreground="#64748b",
        wraplength=680,
    ).pack(anchor=tk.W, pady=(8, 0))

    root.mainloop()


if __name__ == "__main__":
    main()
