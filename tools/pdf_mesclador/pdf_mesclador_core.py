"""
Mesclador PDF: le NOTA por NOTA (varias NFs no mesmo arquivo PDF),
extrai numero da NF, grava NOTA -> GUIA -> RECIBO no PDF unico.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from datetime import datetime
from enum import Enum
from pathlib import Path
from typing import Callable

import fitz  # PyMuPDF
from pypdf import PdfReader, PdfWriter


class DocTipo(str, Enum):
    NOTA = "nota"
    GUIA = "guia"
    RECIBO = "recibo"
    DESCONHECIDO = "desconhecido"


@dataclass
class NotaNoPdf:
    """Uma NF individual dentro de um arquivo PDF (1 ou mais paginas)."""
    arquivo: Path
    indice_no_arquivo: int
    pagina_ini: int
    pagina_fim: int
    numero_nota: str | None
    chave_nfe: str | None
    texto: str


@dataclass
class GuiaNoPdf:
    """Uma guia (boleto) = uma pagina no PDF de guias (3 vias na mesma pagina)."""
    arquivo: Path
    pagina: int
    indice_no_arquivo: int
    documento_origem: str | None
    codigo_barras: str | None = None
    numeros_origem: set[str] = field(default_factory=set)
    texto: str = ""


@dataclass
class ReciboNoPdf:
    arquivo: Path
    pagina: int
    indice_no_arquivo: int
    documento_origem: str | None
    codigo_barras: str | None = None
    numeros_origem: set[str] = field(default_factory=set)
    texto: str = ""


@dataclass
class PdfDoc:
    path: Path
    tipo: DocTipo
    texto: str
    numeros_origem: set[str] = field(default_factory=set)


_NOME_NOTA = re.compile(r"\b(nfe|nf-?e|nota\s*fiscal|danfe|nfs-?e)\b", re.I)
_NOME_GUIA = re.compile(
    r"\b(guia|darf|gps|fgts|grf|boleto|dam|gnre|pagamento|arrecadacao)\b",
    re.I,
)
_NOME_RECIBO = re.compile(r"\b(comprovante|recibo|transferencia|pix|ted)\b", re.I)

_TEXTO_NOTA = re.compile(
    r"\b(DANFE|NF-?E|NOTA\s*FISCAL|CHAVE\s*DE\s*ACESSO|NFS-?E)\b",
    re.I,
)
_TEXTO_GUIA = re.compile(
    r"\b(GUIA|DARF|GPS|FGTS|GRF|GNRE|BOLETO|CODIGO\s*DE\s*BARRAS|LINHA\s*DIGITAVEL|"
    r"NUMERO\s*DE\s*ORIGEM|DOCUMENTO\s*DE\s*ORIGEM)\b",
    re.I,
)
_TEXTO_RECIBO = re.compile(
    r"\b(COMPROVANTE|RECIBO|TRANSFERENCIA|PIX|TED|PAGAMENTO\s*EFETUADO)\b",
    re.I,
)

_CHAVE_NFE = re.compile(r"\b(\d{44})\b")
_NUM_ARQUIVO = re.compile(r"(\d{3,})")

_PATTERNS_NUMERO_NOTA = [
    re.compile(r"N[úu]MERO\s*/?\s*S[ée]RIE[^\d]{0,40}(\d{1,9})\s*/\s*(\d{1,9})", re.I),
    re.compile(r"N[ºoO°]\s*(?:\.\s*)?(\d{1,9})\s+S[ée]RIE", re.I),
    re.compile(r"NOTA\s*FISCAL[^\d]{0,100}?N[ºoO°]?\s*\.?\s*(\d{1,9})", re.I | re.S),
    re.compile(r"N[úu]MERO\s*(?:DA\s*)?NOTA\s*[:\s]*(\d{1,9})", re.I),
    re.compile(r"NF-?E[^\d]{0,40}(\d{1,9})", re.I),
    re.compile(r"\bN[ºoO°]\s*(\d{1,9})\b", re.I),
    re.compile(r"\bNF\s*[:\s]*(\d{1,9})\b", re.I),
]

# Campo principal no boleto WCT: "Nº Documento de Origem"
_PATTERNS_DOCUMENTO_ORIGEM = [
    re.compile(
        r"N[ºoO°o]\s*\.?\s*DOCUMENTO\s+DE\s+ORIGEM[^\d]{0,40}(\d{1,12})", re.I | re.S
    ),
    re.compile(
        r"N[úu]MERO\s+DO\s+DOCUMENTO\s+DE\s+ORIGEM[^\d]{0,40}(\d{1,12})", re.I | re.S
    ),
    re.compile(r"N[úu]MERO\s+DE\s+ORIGEM[^\d]{0,30}(\d{1,12})", re.I),
    re.compile(r"DOCUMENTO\s+DE\s+ORIGEM[^\d]{0,40}(\d{1,12})", re.I | re.S),
    re.compile(r"DOC\.?\s*ORIGEM[^\d]{0,30}(\d{1,12})", re.I),
]

_LABEL_ORIGEM = re.compile(r"DOCUMENTO\s+DE\s+ORIGEM", re.I)
_LABEL_CHAVE_NFE = re.compile(r"CHAVE\s*NF-?E", re.I)
_LABEL_CODIGO_BARRAS = re.compile(r"CODIGO\s*DE\s*BARRAS", re.I)
_LABEL_LINHA_DIGITAVEL = re.compile(r"LINHA\s*DIGITAVEL", re.I)
_LINHA_DIGITAVEL = re.compile(r"\d{11,}[\s.\-]?\d")
# Linha digitavel impressa com espacos (ex.: 85820000004 0 73790303261 2 ...)
_LINHA_DIGITAVEL_ESPACADA = re.compile(
    r"\d{4,12}(?:\s+\d){3,}\s+\d{4,14}",
)

_PATTERNS_CODIGO_BARRAS = [
    re.compile(r"CODIGO\s*DE\s*BARRAS\s*:?\s*([0-9\s.\-]{20,})", re.I),
    re.compile(r"COD\.?\s*BARRAS\s*:?\s*([0-9\s.\-]{20,})", re.I),
    re.compile(r"LINHA\s*DIGITAVEL\s*:?\s*([0-9\s.\-]{40,})", re.I),
]

_PATTERNS_NUMERO_ORIGEM = list(_PATTERNS_DOCUMENTO_ORIGEM) + [
    re.compile(r"N[úu]MERO\s+DE\s+ORIGEM\s*[:\s]*(\d{3,})", re.I),
    re.compile(r"N[úu]MERO\s+DO\s+DOCUMENTO\s+DE\s+ORIGEM\s*[:\s]*(\d{3,})", re.I),
    re.compile(r"DOCUMENTO\s+DE\s+ORIGEM\s*[:\s]*(\d{3,})", re.I),
    re.compile(r"DOC(?:UMENTO)?\.?\s*ORIGEM\s*[:\s]*(\d{3,})", re.I),
    re.compile(r"N[ºoO°]\s*\.\s*ORIGEM\s*[:\s]*(\d{3,})", re.I),
    re.compile(r"NUMERO\s+ORIGEM\s*[:\s]*(\d{3,})", re.I),
    re.compile(r"N[úu]MERO\s+ORIGEM\s*[:\s]*(\d{3,})", re.I),
    re.compile(r"IDENTIF(?:ICAÇÃO|ICACAO)?\s+(?:DO\s+)?DOCUMENTO\s+ORIGIN[AÁ]RIO\s*[:\s]*(\d{3,})", re.I),
    re.compile(r"REFER[êeE]NCIA\s*(?:DO\s+DOCUMENTO)?\s*[:\s]*(\d{3,})", re.I),
    re.compile(r"N[úu]MERO\s+DO\s+DOCUMENTO\s*[:\s]*(\d{3,})", re.I),
    re.compile(r"SEU\s+N[úu]MERO\s*[:\s]*(\d{3,})", re.I),
    re.compile(r"NOSSO\s+N[úu]MERO\s*[:\s]*(\d{3,})", re.I),
]


def _so_digitos(valor: str) -> str:
    return re.sub(r"\D", "", valor)


def _sem_espacos(valor: str) -> str:
    """Remove espacos da string numerica (como no PDF impresso)."""
    return re.sub(r"\s+", "", valor.strip())


def _codigo_comparavel(valor: str) -> str:
    """Digitos apenas, para comparar Chave NFe com codigo de barras."""
    return _so_digitos(_sem_espacos(valor))


def _codigos_barras_batem(codigo_a: str, codigo_b: str) -> bool:
    a = _codigo_comparavel(codigo_a)
    b = _codigo_comparavel(codigo_b)
    if not a or not b:
        return False
    if a == b:
        return True
    # Mesma linha digitavel com pequena diferenca (DV, OCR, 47 vs 48 digitos)
    if abs(len(a) - len(b)) <= 1 and (a.startswith(b) or b.startswith(a)):
        return True
    menor, maior = (a, b) if len(a) <= len(b) else (b, a)
    if len(menor) >= 40 and menor in maior:
        return True
    return False


def _normalizar_numero(valor: str) -> str:
    d = _so_digitos(valor)
    if not d:
        return valor.strip()
    return d.lstrip("0") or d


def _numeros_equivalentes(a: str, b: str) -> bool:
    da = _normalizar_numero(a)
    db = _normalizar_numero(b)
    if not da or not db:
        return False
    return da == db or da.endswith(db) or db.endswith(da)


def _numero_na_chave_nfe(chave: str) -> str | None:
    if len(chave) != 44:
        return None
    num = chave[25:34]
    norm = num.lstrip("0") or num
    return norm if norm else None


def _extrair_numero_nota(texto: str, chave: str | None) -> str | None:
    if chave:
        n = _numero_na_chave_nfe(chave)
        if n:
            return n

    for pat in _PATTERNS_NUMERO_NOTA:
        m = pat.search(texto)
        if m:
            grupos = [g for g in m.groups() if g]
            if len(grupos) >= 2:
                return _normalizar_numero(grupos[-1])
            return _normalizar_numero(grupos[0])

    return None


def _normalizar_texto_pdf(texto: str) -> str:
    t = texto.replace("\xa0", " ")
    for ch in ("º", "°", "ª"):
        t = t.replace(ch, "o")
    return t


def _extrair_documento_origem_linhas(texto: str) -> str | None:
    """Rotulo e numero em linhas separadas (comum em boleto com 3 vias)."""
    linhas = [ln.strip() for ln in texto.splitlines() if ln.strip()]
    for i, linha in enumerate(linhas):
        if not _LABEL_ORIGEM.search(linha):
            continue
        parte = linha.split("ORIGEM", 1)[-1] if "ORIGEM" in linha.upper() else linha
        nums = re.findall(r"\d{1,12}", parte)
        if nums:
            return _normalizar_numero(nums[0])
        for j in range(i + 1, min(i + 5, len(linhas))):
            nums = re.findall(r"\b(\d{1,12})\b", linhas[j])
            if nums:
                return _normalizar_numero(nums[0])
    return None


def _extrair_documento_origem_principal(texto: str) -> str | None:
    """Le o Nº Documento de Origem da pagina (campo que bate com numero da NF)."""
    texto = _normalizar_texto_pdf(texto)
    for pat in _PATTERNS_DOCUMENTO_ORIGEM:
        m = pat.search(texto)
        if m:
            return _normalizar_numero(m.group(1))
    por_linha = _extrair_documento_origem_linhas(texto)
    if por_linha:
        return por_linha
    m = _LABEL_ORIGEM.search(texto)
    if m:
        trecho = texto[m.end() : m.end() + 120]
        nums = re.findall(r"\d{1,12}", trecho)
        if nums:
            return _normalizar_numero(nums[0])
    return None


def _extrair_valor_apos_rotulo(
    texto: str,
    rotulo: re.Pattern[str],
    min_digitos: int = 20,
) -> str | None:
    """Rotulo e valor na mesma linha ou nas linhas seguintes."""
    texto_norm = _normalizar_texto_pdf(texto)
    linhas = [ln.strip() for ln in texto_norm.splitlines() if ln.strip()]
    for i, linha in enumerate(linhas):
        if not rotulo.search(linha):
            continue
        if ":" in linha:
            cod = _codigo_comparavel(linha.split(":", 1)[-1])
            if len(cod) >= min_digitos:
                return cod
        cod_linha = _codigo_comparavel(linha)
        if len(cod_linha) >= min_digitos:
            return cod_linha
        for j in range(i + 1, min(i + 6, len(linhas))):
            cod = _codigo_comparavel(linhas[j])
            if len(cod) >= min_digitos:
                return cod
    return None


def _chave_nfe_no_texto(texto: str) -> str | None:
    """Chave NF-e (44 digitos) — usada so para nao confundir com linha digitavel."""
    texto_norm = _normalizar_texto_pdf(texto)
    m = _LABEL_CHAVE_NFE.search(texto_norm)
    if m:
        for ch in _CHAVE_NFE.findall(texto_norm[m.end() : m.end() + 120]):
            return ch
    for ch in _CHAVE_NFE.findall(texto_norm):
        return ch
    return None


def _melhor_candidato_linha_digitavel(
    candidatos: list[str],
    chave_nfe: str | None,
) -> str | None:
    unicos = []
    vistos: set[str] = set()
    for cod in candidatos:
        if not cod or cod == chave_nfe or cod in vistos:
            continue
        vistos.add(cod)
        unicos.append(cod)
    if not unicos:
        return None
    for alvo in (48, 47, 46, 45, 44):
        for cod in unicos:
            if len(cod) == alvo and cod != chave_nfe:
                return cod
    return max(unicos, key=len)


def _extrair_linha_digitavel_parte_inferior(texto: str, chave_nfe: str | None) -> str | None:
    """GNRE/boleto: linha digitavel costuma estar no rodape (acima do codigo de barras grafico)."""
    linhas = [ln.strip() for ln in _normalizar_texto_pdf(texto).splitlines() if ln.strip()]
    candidatos: list[str] = []
    for linha in reversed(linhas[-30:]):
        if _LABEL_CHAVE_NFE.search(linha):
            continue
        if _CHAVE_NFE.fullmatch(_codigo_comparavel(linha)):
            continue
        cod = _codigo_comparavel(linha)
        if len(cod) < 44 or cod == chave_nfe:
            continue
        if _LINHA_DIGITAVEL_ESPACADA.search(linha) or len(re.findall(r"\s", linha)) >= 3:
            candidatos.append(cod)
    return _melhor_candidato_linha_digitavel(candidatos, chave_nfe)


def _extrair_codigo_barras_de_texto(texto: str, *, ignorar_chave_nfe: bool = False) -> str | None:
    """Digitos do codigo de barras / linha digitavel, sem espacos."""
    texto_norm = _normalizar_texto_pdf(texto)
    chave_nfe = _chave_nfe_no_texto(texto_norm) if ignorar_chave_nfe else None

    for pat in _PATTERNS_CODIGO_BARRAS:
        m = pat.search(texto_norm)
        if m:
            cod = _codigo_comparavel(m.group(1))
            if len(cod) >= 44 and cod != chave_nfe:
                return cod

    for rotulo in (_LABEL_CODIGO_BARRAS, _LABEL_LINHA_DIGITAVEL):
        por_rotulo = _extrair_valor_apos_rotulo(texto_norm, rotulo, min_digitos=44)
        if por_rotulo and por_rotulo != chave_nfe:
            return por_rotulo

    candidatos: list[tuple[int, str, int]] = []
    linhas = [ln.strip() for ln in texto_norm.splitlines() if ln.strip()]
    for idx, linha in enumerate(linhas):
        if ignorar_chave_nfe and _LABEL_CHAVE_NFE.search(linha):
            continue
        if _LINHA_DIGITAVEL_ESPACADA.search(linha) or (
            len(re.findall(r"\d", linha)) >= 40 and len(re.findall(r"\s", linha)) >= 3
        ):
            cod = _codigo_comparavel(linha)
            if len(cod) >= 44 and cod != chave_nfe:
                candidatos.append((len(cod), cod, idx))

    for m in _LINHA_DIGITAVEL_ESPACADA.finditer(texto_norm):
        cod = _codigo_comparavel(m.group(0))
        if len(cod) >= 44 and cod != chave_nfe:
            candidatos.append((len(cod), cod, 9999))

    if candidatos:
        return _melhor_candidato_linha_digitavel([c[1] for c in candidatos], chave_nfe)

    return None


def _extrair_codigo_barras_guia(texto: str) -> str | None:
    """
    Codigo de barras / linha digitavel na guia (GNRE, boleto).
    Nao usar Chave NFe — so a linha numerica acima do codigo de barras grafico.
    """
    texto_norm = _normalizar_texto_pdf(texto)
    chave_nfe = _chave_nfe_no_texto(texto_norm)

    por_rodape = _extrair_linha_digitavel_parte_inferior(texto_norm, chave_nfe)
    if por_rodape:
        return por_rodape

    candidatos: list[str] = []
    for m in _LINHA_DIGITAVEL_ESPACADA.finditer(texto_norm):
        cod = _codigo_comparavel(m.group(0))
        if len(cod) >= 44 and cod != chave_nfe:
            candidatos.append(cod)
    melhor = _melhor_candidato_linha_digitavel(candidatos, chave_nfe)
    if melhor:
        return melhor

    return _extrair_codigo_barras_de_texto(texto, ignorar_chave_nfe=True)


def _extrair_codigo_barras_recibo(texto: str) -> str | None:
    """Campo 'Codigo de barras:' no comprovante (nao confundir com Chave NFe de 44 digitos)."""
    texto_norm = _normalizar_texto_pdf(texto)
    chave_nfe = _chave_nfe_no_texto(texto_norm)

    for pat in _PATTERNS_CODIGO_BARRAS:
        m = pat.search(texto_norm)
        if m:
            cod = _codigo_comparavel(m.group(1))
            if len(cod) >= 44:
                if len(cod) == 44 and cod == chave_nfe:
                    break
                if len(cod) >= 47 or cod != chave_nfe:
                    return cod

    por_rotulo = _extrair_valor_apos_rotulo(texto_norm, _LABEL_CODIGO_BARRAS, min_digitos=44)
    if por_rotulo and (len(por_rotulo) >= 47 or por_rotulo != chave_nfe):
        return por_rotulo

    return _extrair_codigo_barras_de_texto(texto, ignorar_chave_nfe=True)


def _extrair_origem_guia(texto: str, nome_arquivo: str) -> tuple[str | None, set[str]]:
    """Somente campos de documento de origem (nao confunde com nosso numero do boleto)."""
    texto_norm = _normalizar_texto_pdf(texto)
    doc = _extrair_documento_origem_principal(texto_norm)
    nums: set[str] = set()
    if doc:
        nums.add(doc)
    for pat in _PATTERNS_DOCUMENTO_ORIGEM:
        for m in pat.finditer(texto_norm):
            nums.add(_normalizar_numero(m.group(1)))
    return doc, {x for x in nums if x}


def _extrair_numeros_origem(texto: str, nome_arquivo: str) -> set[str]:
    encontrados: set[str] = set()
    principal = _extrair_documento_origem_principal(texto)
    if principal:
        encontrados.add(principal)
    for pat in _PATTERNS_NUMERO_ORIGEM:
        for m in pat.finditer(texto):
            encontrados.add(_normalizar_numero(m.group(1)))
    for n in _NUM_ARQUIVO.findall(Path(nome_arquivo).stem):
        if len(n) >= 3:
            encontrados.add(_normalizar_numero(n))
    return {x for x in encontrados if x}


def _texto_da_pagina(page: fitz.Page) -> str:
    texto = page.get_text("text") or ""
    if len(texto.strip()) >= 30:
        return texto
    blocos = page.get_text("blocks") or []
    if blocos:
        ordenados = sorted(blocos, key=lambda b: (round(b[1], 1), round(b[0], 1)))
        partes = [str(b[4]).strip() for b in ordenados if len(b) > 4 and str(b[4]).strip()]
        if partes:
            return "\n".join(partes)
    palavras = page.get_text("words") or []
    if palavras:
        ordenadas = sorted(palavras, key=lambda w: (round(w[1], 1), w[0]))
        return "\n".join(w[4] for w in ordenadas if len(w) > 4 and w[4])
    return texto


def _ler_paginas(caminho: Path) -> list[tuple[int, str, list[str]]]:
    paginas: list[tuple[int, str, list[str]]] = []
    with fitz.open(caminho) as doc:
        for i in range(doc.page_count):
            texto = _texto_da_pagina(doc.load_page(i))
            chaves = list(dict.fromkeys(_CHAVE_NFE.findall(texto)))
            paginas.append((i, texto, chaves))
    return paginas


def _arquivo_parece_nota(caminho: Path, paginas: list[tuple[int, str, list[str]]]) -> bool:
    if _NOME_NOTA.search(caminho.name):
        return True
    texto = "\n".join(t for _, t, _ in paginas)
    if _TEXTO_NOTA.search(texto):
        return True
    if any(ch for _, _, ch in paginas for ch in ch):
        return True
    return False


def _pagina_e_guia(texto: str, nome_arquivo: str) -> bool:
    if _NOME_GUIA.search(nome_arquivo):
        return True
    texto_norm = _normalizar_texto_pdf(texto)
    if _LABEL_ORIGEM.search(texto_norm):
        return True
    if _TEXTO_GUIA.search(texto_norm):
        return True
    if _LINHA_DIGITAVEL.search(texto_norm) and re.search(
        r"GUIA|BOLETO|DARF|GNRE|ARRECAD|FEBRABAN", texto_norm, re.I
    ):
        return True
    return False


def _pagina_e_recibo(texto: str, nome_arquivo: str) -> bool:
    if _pagina_e_guia(texto, nome_arquivo):
        return False
    texto_norm = _normalizar_texto_pdf(texto)
    if _NOME_RECIBO.search(nome_arquivo):
        return True
    return bool(_TEXTO_RECIBO.search(texto_norm))


def _arquivo_parece_guia(caminho: Path, paginas: list[tuple[int, str, list[str]]]) -> bool:
    nome = caminho.name
    if _NOME_GUIA.search(nome):
        return True
    if any(_pagina_e_guia(t, nome) for _, t, _ in paginas):
        return True
    return len(paginas) >= 2 and not _arquivo_parece_nota(caminho, paginas)


def _arquivo_parece_recibo(caminho: Path, paginas: list[tuple[int, str, list[str]]]) -> bool:
    nome = caminho.name
    texto = "\n".join(t for _, t, _ in paginas[:5])
    if _NOME_RECIBO.search(nome) or _TEXTO_RECIBO.search(texto):
        return not _arquivo_parece_guia(caminho, paginas)
    return False


def _arquivo_parece_guia_ou_recibo(caminho: Path, paginas: list[tuple[int, str, list[str]]]) -> bool:
    return _arquivo_parece_guia(caminho, paginas) or _arquivo_parece_recibo(caminho, paginas)


def _criar_nota(
    arquivo: Path,
    indice: int,
    pagina_ini: int,
    pagina_fim: int,
    chave: str | None,
    textos: list[str],
) -> NotaNoPdf:
    texto = "\n".join(textos)
    numero = _extrair_numero_nota(texto, chave)
    return NotaNoPdf(
        arquivo=arquivo,
        indice_no_arquivo=indice,
        pagina_ini=pagina_ini,
        pagina_fim=pagina_fim,
        numero_nota=numero,
        chave_nfe=chave,
        texto=texto,
    )


def _chave_principal(chaves: list[str]) -> str | None:
    return chaves[0] if chaves else None


def _numero_da_pagina(texto: str, chave: str | None) -> str | None:
    return _extrair_numero_nota(texto, chave)


def extrair_notas_de_arquivo(caminho: Path) -> list[NotaNoPdf]:
    """
    Le pagina a pagina e separa cada NF.
    Nova nota quando: chave NF-e diferente OU numero da NF diferente na pagina.
    """
    paginas = _ler_paginas(caminho)
    if not paginas or not _arquivo_parece_nota(caminho, paginas):
        return []

    notas: list[NotaNoPdf] = []
    ini = 0
    chave_atual: str | None = None
    numero_atual: str | None = None
    textos: list[str] = []

    def fechar_bloco(fim: int) -> None:
        nonlocal ini, chave_atual, numero_atual, textos
        if fim < ini or not textos:
            textos = []
            chave_atual = None
            numero_atual = None
            return
        notas.append(
            _criar_nota(
                arquivo=caminho,
                indice=len(notas) + 1,
                pagina_ini=ini,
                pagina_fim=fim,
                chave=chave_atual,
                textos=textos,
            )
        )
        ini = fim + 1
        chave_atual = None
        numero_atual = None
        textos = []

    for idx, (pag_i, texto, chaves) in enumerate(paginas):
        chave_pag = _chave_principal(chaves)
        num_pag = _numero_da_pagina(texto, chave_pag)

        nova_nota = False
        if textos:
            if chave_pag and chave_atual and chave_pag != chave_atual:
                nova_nota = True
            elif (
                num_pag
                and numero_atual
                and not _numeros_equivalentes(num_pag, numero_atual)
            ):
                nova_nota = True
            elif chave_pag and not chave_atual:
                nova_nota = True
            elif num_pag and not numero_atual and len(textos) >= 1:
                # Pagina seguinte com numero novo sem chave ainda
                num_bloco = _extrair_numero_nota("\n".join(textos), chave_atual)
                if num_bloco and not _numeros_equivalentes(num_pag, num_bloco):
                    nova_nota = True

        if nova_nota:
            fechar_bloco(pag_i - 1)

        if not textos:
            ini = pag_i
            chave_atual = chave_pag
            numero_atual = num_pag
            textos = [texto]
        else:
            if chave_pag and not chave_atual:
                chave_atual = chave_pag
            if num_pag and not numero_atual:
                numero_atual = num_pag
            textos.append(texto)

        if idx == len(paginas) - 1 and textos:
            fechar_bloco(pag_i)

    if not notas:
        # Fallback: agrupa paginas pela chave (ou 1 bloco com todas as paginas)
        blocos_pag: list[tuple[str | None, int, int, list[str]]] = []
        ch_bloco: str | None = None
        ini_fb = 0
        textos_fb: list[str] = []

        def fechar_fb(fim: int) -> None:
            nonlocal ch_bloco, ini_fb, textos_fb
            if textos_fb:
                blocos_pag.append((ch_bloco, ini_fb, fim, list(textos_fb)))
            ini_fb = fim + 1
            ch_bloco = None
            textos_fb = []

        for idx, (pag_i, texto, chaves) in enumerate(paginas):
            ch = _chave_principal(chaves)
            if ch and ch_bloco and ch != ch_bloco:
                fechar_fb(pag_i - 1)
            if not textos_fb:
                ini_fb = pag_i
                ch_bloco = ch
            elif ch and not ch_bloco:
                ch_bloco = ch
            textos_fb.append(texto)
            if idx == len(paginas) - 1:
                fechar_fb(pag_i)

        if not blocos_pag:
            blocos_pag = [(None, 0, paginas[-1][0], [t for _, t, _ in paginas])]

        for i, (ch, p_ini, p_fim, txts) in enumerate(blocos_pag, start=1):
            notas.append(
                _criar_nota(caminho, i, p_ini, p_fim, ch, txts),
            )

    return notas


def extrair_guias_de_arquivo(caminho: Path) -> list[GuiaNoPdf]:
    """
    PDF de guias: uma guia por pagina (3 vias na mesma pagina).
    Le Nº Documento de Origem em cada pagina.
    """
    paginas = _ler_paginas(caminho)
    if not paginas:
        return []

    nome = caminho.name
    forcar_todas = bool(_NOME_GUIA.search(nome))

    guias: list[GuiaNoPdf] = []
    for pag_i, texto, _ in paginas:
        if not forcar_todas and not _pagina_e_guia(texto, nome):
            continue
        texto_norm = _normalizar_texto_pdf(texto)
        doc_orig, origens = _extrair_origem_guia(texto, nome)
        codigo = _extrair_codigo_barras_guia(texto_norm)
        guias.append(
            GuiaNoPdf(
                arquivo=caminho,
                pagina=pag_i,
                indice_no_arquivo=len(guias) + 1,
                documento_origem=doc_orig,
                codigo_barras=codigo,
                numeros_origem=origens,
                texto=texto_norm,
            )
        )
    return guias


def extrair_recibos_de_arquivo(caminho: Path) -> list[ReciboNoPdf]:
    paginas = _ler_paginas(caminho)
    if not paginas:
        return []

    nome = caminho.name
    recibos: list[ReciboNoPdf] = []
    for pag_i, texto, _ in paginas:
        if not _pagina_e_recibo(texto, nome):
            continue
        texto_norm = _normalizar_texto_pdf(texto)
        origens = _extrair_numeros_origem(texto, nome)
        doc_orig = _extrair_documento_origem_principal(texto_norm)
        codigo = _extrair_codigo_barras_recibo(texto_norm)
        recibos.append(
            ReciboNoPdf(
                arquivo=caminho,
                pagina=pag_i,
                indice_no_arquivo=len(recibos) + 1,
                documento_origem=doc_orig,
                codigo_barras=codigo,
                numeros_origem=origens,
                texto=texto_norm,
            )
        )
    return recibos


def extrair_anexos_de_arquivo(caminho: Path) -> tuple[list[GuiaNoPdf], list[ReciboNoPdf]]:
    """Le guias e recibos do mesmo arquivo (pagina a pagina, sem pular guias)."""
    guias = extrair_guias_de_arquivo(caminho)
    recibos = extrair_recibos_de_arquivo(caminho)
    return guias, recibos


def classificar_anexo(caminho: Path) -> PdfDoc:
    nome = caminho.name
    paginas = _ler_paginas(caminho)
    texto = "\n".join(t for _, t, _ in paginas)

    if _NOME_RECIBO.search(nome) or (_TEXTO_RECIBO.search(texto) and not _TEXTO_GUIA.search(texto)):
        tipo = DocTipo.RECIBO
    elif _NOME_GUIA.search(nome) or _TEXTO_GUIA.search(texto):
        tipo = DocTipo.GUIA
    else:
        tipo = DocTipo.DESCONHECIDO

    return PdfDoc(
        path=caminho,
        tipo=tipo,
        texto=texto,
        numeros_origem=_extrair_numeros_origem(texto, nome),
    )


def _origem_bate_numero(numero_nf: str, documento_origem: str | None, outros: set[str]) -> bool:
    if documento_origem and _numeros_equivalentes(numero_nf, documento_origem):
        return True
    return any(_numeros_equivalentes(numero_nf, o) for o in outros)


def _texto_contem_numero_nf(texto: str, numero_nf: str) -> bool:
    alvo = _so_digitos(numero_nf)
    if len(alvo) < 3:
        return False
    corpo = _so_digitos(texto)
    if alvo in corpo:
        return True
    return bool(re.search(r"(?<!\d)" + re.escape(alvo) + r"(?!\d)", texto))


def _buscar_guia(
    nota: NotaNoPdf,
    guias: list[GuiaNoPdf],
    usadas: set[tuple[Path, int]],
) -> GuiaNoPdf | None:
    if not nota.numero_nota:
        return None
    alvo = nota.numero_nota

    # 1) Match exato no Nº Documento de Origem
    for g in guias:
        chave = (g.arquivo, g.pagina)
        if chave in usadas:
            continue
        if g.documento_origem and _numeros_equivalentes(alvo, g.documento_origem):
            return g

    # 2) Outros numeros de origem da pagina
    for g in guias:
        chave = (g.arquivo, g.pagina)
        if chave in usadas:
            continue
        if _origem_bate_numero(alvo, g.documento_origem, g.numeros_origem):
            return g

    # 3) Numero da NF aparece no texto da pagina da guia
    for g in guias:
        chave = (g.arquivo, g.pagina)
        if chave in usadas:
            continue
        if _texto_contem_numero_nf(g.texto, alvo):
            return g

    return None


def _buscar_recibo(
    nota: NotaNoPdf,
    recibos: list[ReciboNoPdf],
    usados: set[tuple[Path, int]],
    guia: GuiaNoPdf | None,
) -> ReciboNoPdf | None:
    """
    Comprovante casa com a guia: codigo de barras (guia) == codigo de barras (recibo), sem espacos.
    """
    if guia and guia.codigo_barras:
        for r in recibos:
            chave_uso = (r.arquivo, r.pagina)
            if chave_uso in usados:
                continue
            if r.codigo_barras and _codigos_barras_batem(guia.codigo_barras, r.codigo_barras):
                return r

    # Legado: numero de origem / numero da NF (quando PDF nao traz codigo de barras)
    if nota.numero_nota:
        alvo = nota.numero_nota
        for r in recibos:
            chave_uso = (r.arquivo, r.pagina)
            if chave_uso in usados:
                continue
            if _origem_bate_numero(alvo, r.documento_origem, r.numeros_origem):
                return r
        if guia:
            for r in recibos:
                chave_uso = (r.arquivo, r.pagina)
                if chave_uso in usados:
                    continue
                if guia.documento_origem and r.documento_origem:
                    if _numeros_equivalentes(guia.documento_origem, r.documento_origem):
                        return r
                if guia.numeros_origem & r.numeros_origem:
                    return r
    return None


def adicionar_paginas(writer: PdfWriter, arquivo: Path, pagina_ini: int, pagina_fim: int) -> None:
    reader = PdfReader(str(arquivo))
    for p in range(pagina_ini, pagina_fim + 1):
        if 0 <= p < len(reader.pages):
            writer.add_page(reader.pages[p])


def adicionar_arquivo_inteiro(writer: PdfWriter, arquivo: Path) -> None:
    reader = PdfReader(str(arquivo))
    for page in reader.pages:
        writer.add_page(page)


def processar_pasta(
    pasta: Path,
    log: Callable[[str], None] | None = None,
) -> tuple[list[Path], list[str]]:
    log = log or (lambda _m: None)
    pasta = pasta.resolve()
    if not pasta.is_dir():
        raise FileNotFoundError(f"Pasta nao encontrada: {pasta}")

    pdfs = sorted(
        {p for p in pasta.iterdir() if p.suffix.lower() == ".pdf" and p.is_file()},
        key=lambda p: p.name.lower(),
    )
    if not pdfs:
        return [], ["Nenhum arquivo PDF na pasta informada."]

    log(f"=== PASSO 1: {len(pdfs)} arquivo(s) PDF na pasta ===")

    # Primeiro: identificar arquivos de NOTA e extrair cada NF
    arquivos_nota: list[Path] = []
    todas_notas: list[NotaNoPdf] = []
    avisos: list[str] = []

    log("")
    log("=== PASSO 2: Procurar arquivos de NOTA e ler nota por nota ===")

    for arquivo in pdfs:
        if "saida_mesclados" in arquivo.parts:
            continue

        paginas = _ler_paginas(arquivo)
        if not _arquivo_parece_nota(arquivo, paginas):
            continue

        if _arquivo_parece_guia_ou_recibo(arquivo, paginas) and not _NOME_NOTA.search(arquivo.name):
            # Boleto/recibo que menciona NF-e no texto — nao tratar como arquivo de nota
            continue

        arquivos_nota.append(arquivo)
        notas_no_arquivo = extrair_notas_de_arquivo(arquivo)

        log("")
        log(f"ARQUIVO DE NOTA: {arquivo.name}")

        if not notas_no_arquivo:
            log("  (nenhuma NF identificada neste PDF — verifique se tem texto/chave NF-e)")
            avisos.append(f"Arquivo parece nota mas sem NF lida: {arquivo.name}")
            continue

        log(f"  Total de nota(s) neste arquivo: {len(notas_no_arquivo)}")

        for n in notas_no_arquivo:
            log(f"  >> Nota #{n.indice_no_arquivo} do arquivo (paginas {n.pagina_ini + 1} a {n.pagina_fim + 1})")
            log(f"     Numero da NF lido: {n.numero_nota or 'NAO IDENTIFICADO'}")
            if n.chave_nfe:
                log(f"     Chave NF-e: ...{n.chave_nfe[-12:]}")
            todas_notas.append(n)

    # Demais PDFs = guias (pagina a pagina) e recibos
    arquivos_anexo = [p for p in pdfs if p not in arquivos_nota and "saida_mesclados" not in p.parts]

    todas_guias: list[GuiaNoPdf] = []
    todos_recibos: list[ReciboNoPdf] = []

    log("")
    log("=== PASSO 3: Ler PDF de GUIAS (uma guia por pagina, Nº Documento de Origem) ===")

    for arquivo in arquivos_anexo:
        guias_arquivo, recibos_arquivo = extrair_anexos_de_arquivo(arquivo)

        if guias_arquivo:
            log("")
            log(f"ARQUIVO DE GUIAS: {arquivo.name} ({len(guias_arquivo)} pagina(s))")
            for g in guias_arquivo:
                doc = g.documento_origem or "?"
                cb = g.codigo_barras or "?"
                if cb != "?" and len(cb) > 16:
                    cb_log = f"...{cb[-16:]} ({len(cb)} dig)"
                else:
                    cb_log = cb
                log(
                    f"  >> Guia pag {g.pagina + 1}: Nº Documento de Origem = {doc} | "
                    f"Codigo barras = {cb_log}"
                )
            todas_guias.extend(guias_arquivo)

        if recibos_arquivo:
            log("")
            log(f"ARQUIVO DE RECIBOS: {arquivo.name} ({len(recibos_arquivo)} pagina(s))")
            for r in recibos_arquivo:
                doc = r.documento_origem or "?"
                cb = r.codigo_barras or "?"
                if cb != "?" and len(cb) > 16:
                    cb_log = f"...{cb[-16:]} ({len(cb)} dig)"
                else:
                    cb_log = cb
                log(
                    f"  >> Recibo pag {r.pagina + 1}: Codigo de barras = {cb_log} | origem = {doc}"
                )
            todos_recibos.extend(recibos_arquivo)

        if not guias_arquivo and not recibos_arquivo:
            paginas = _ler_paginas(arquivo)
            if _arquivo_parece_guia(arquivo, paginas):
                avisos.append(f"PDF de guias sem pagina identificada: {arquivo.name}")
            else:
                avisos.append(f"Arquivo ignorado: {arquivo.name}")

    log("")
    log(
        f"RESUMO: {len(arquivos_nota)} arquivo(s) nota | {len(todas_notas)} NF(s) | "
        f"{len(todas_guias)} guia(s) em paginas | {len(todos_recibos)} recibo(s)"
    )

    if not todas_notas:
        avisos.append("Nenhuma nota fiscal encontrada nos PDFs.")
        return [], avisos

    guias_usadas: set[tuple[Path, int]] = set()
    recibos_usados: set[tuple[Path, int]] = set()
    writer = PdfWriter()

    saida_dir = pasta / "saida_mesclados"
    saida_dir.mkdir(exist_ok=True)
    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    destino = saida_dir / f"mesclar_{stamp}.pdf"

    log("")
    log("=== PASSO 4: Montar PDF unico (NOTA -> GUIA -> RECIBO) ===")

    for seq, nota in enumerate(todas_notas, start=1):
        log("")
        log(f"--- Processando NF {seq}/{len(todas_notas)} ---")
        log(f"1) Arquivo: {nota.arquivo.name}")
        log(f"2) Nota #{nota.indice_no_arquivo} (pag {nota.pagina_ini + 1}-{nota.pagina_fim + 1})")
        log(f"3) Numero NF: {nota.numero_nota or 'NAO IDENTIFICADO'}")

        log("4) Incluindo NOTA no PDF novo...")
        adicionar_paginas(writer, nota.arquivo, nota.pagina_ini, nota.pagina_fim)

        if not nota.numero_nota:
            avisos.append(
                f"Nota sem numero (so NOTA no PDF): {nota.arquivo.name} "
                f"#{nota.indice_no_arquivo} pag {nota.pagina_ini + 1}-{nota.pagina_fim + 1}"
            )
            continue

        guia = _buscar_guia(nota, todas_guias, guias_usadas)
        if guia:
            guias_usadas.add((guia.arquivo, guia.pagina))
            orig = guia.documento_origem or "?"
            log(
                f"5) GUIA encontrada: {guia.arquivo.name} pag {guia.pagina + 1} "
                f"(Nº Documento de Origem = {orig})"
            )
            log("   Copiando somente esta pagina da guia...")
            adicionar_paginas(writer, guia.arquivo, guia.pagina, guia.pagina)
        else:
            log(f"5) GUIA nao encontrada (Nº Documento de Origem = {nota.numero_nota})")
            if todas_guias:
                amostra = [
                    f"pag{g.pagina + 1}={g.documento_origem or '?'}"
                    for g in todas_guias[:8]
                ]
                log(f"   Guias no indice: {', '.join(amostra)}")
            else:
                log("   AVISO: nenhuma guia foi indexada — confira o PDF de guias na pasta")

        recibo = _buscar_recibo(nota, todos_recibos, recibos_usados, guia)
        if recibo:
            recibos_usados.add((recibo.arquivo, recibo.pagina))
            cb_guia = guia.codigo_barras if guia and guia.codigo_barras else "?"
            cb_rec = recibo.codigo_barras or "?"
            if len(cb_guia) > 20:
                cb_guia_log = f"...{cb_guia[-16:]} ({len(cb_guia)} dig)"
            else:
                cb_guia_log = cb_guia
            if len(cb_rec) > 20:
                cb_rec_log = f"...{cb_rec[-16:]} ({len(cb_rec)} dig)"
            else:
                cb_rec_log = cb_rec
            log(
                f"6) COMPROVANTE encontrado: {recibo.arquivo.name} pag {recibo.pagina + 1} "
                f"(codigo barras guia {cb_guia_log} = comprovante {cb_rec_log})"
            )
            log("   Copiando somente esta pagina do comprovante...")
            adicionar_paginas(writer, recibo.arquivo, recibo.pagina, recibo.pagina)
        else:
            cb_info = guia.codigo_barras if guia and guia.codigo_barras else "sem codigo de barras na guia"
            log(f"6) COMPROVANTE nao encontrado ({cb_info})")

    for g in todas_guias:
        if (g.arquivo, g.pagina) not in guias_usadas:
            avisos.append(
                f"Guia sem nota: {g.arquivo.name} pag {g.pagina + 1} "
                f"(origem {g.documento_origem or '?'})"
            )
    for r in todos_recibos:
        if (r.arquivo, r.pagina) not in recibos_usados:
            avisos.append(
                f"Comprovante sem par: {r.arquivo.name} pag {r.pagina + 1} "
                f"(codigo barras {r.codigo_barras or '?'})"
            )

    log("")
    log(f"7) Gravando PDF: {destino}")
    with destino.open("wb") as f:
        writer.write(f)
    log(f"CONCLUIDO: {destino.name} ({len(writer.pages)} pagina(s))")

    for aviso in avisos:
        log(f"AVISO: {aviso}")

    return [destino], avisos
