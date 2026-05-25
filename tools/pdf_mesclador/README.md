# Mesclador de PDFs (Nota + Guia + Comprovante)

Ferramenta local para varrer uma pasta, identificar notas fiscais, guias de pagamento e comprovantes, e gerar PDFs mesclados na ordem correta.

## Python ou EXE?

| Opção | Quando usar |
|--------|-------------|
| **Python** | Desenvolvimento, ajustes nas regras, equipe com Python instalado |
| **EXE (PyInstaller)** | Usuarios finais sem Python — gere depois que as regras estiverem validadas |

Recomendacao: desenvolver e testar em **Python**; empacotar em `.exe` quando os PDFs reais estiverem classificando bem.

## Instalacao

```bash
cd tools/pdf_mesclador
pip install -r requirements.txt
```

## Uso (interface)

```bash
python pdf_mesclador_gui.py
```

1. Informe ou escolha a **pasta** com os PDFs.
2. Clique em **Processar pasta**.
3. Um **unico** PDF e gerado em `saida_mesclados/mesclar_AAAAMMDD_HHMMSS.pdf`.

## Regras de negocio

1. Abre cada **NOTA** e extrai o **numero da NF** (DANFE / chave NF-e).
2. Inclui essa nota no PDF unico.
3. Procura **GUIA** (boleto) cujo **Nº Documento de Origem** = numero da nota (1 pagina por guia).
4. Procura **COMPROVANTE** que casa com a guia:
   - Na **guia**, le o **codigo de barras** / linha digitavel (numeros acima do codigo de barras grafico; remove espacos).
   - No **comprovante**, le **Codigo de barras:** (remove espacos).
   - Se forem iguais, inclui o comprovante. (Nao usa Chave NFe para esse vinculo.)
5. Ordem no PDF: **NOTA → GUIA → COMPROVANTE**; depois a proxima nota.
6. Tudo no **mesmo** PDF de saida.

- Guia sem origem = numero da nota: nao entra.
- Comprovante sem par com o codigo de barras da guia: nao entra.
- O log mostra origem e codigo de barras lidos em cada pagina.

## Como funciona a identificacao

1. **Classificacao:** palavras-chave no nome do arquivo e no texto das primeiras paginas (DANFE, guia, comprovante, etc.).
2. **Vinculo:** chave NF-e (44 digitos), numero da nota, valores e CNPJ quando aparecem no texto.
3. **Mesclagem:** biblioteca `pypdf`.

Se seus PDFs forem escaneados (imagem sem texto), sera necessario acrescentar OCR (ex.: `pytesseract`) — avise para evoluir o script.

## Executavel Windows (EXE)

Ja gerado em:

**`tools/pdf_mesclador/dist/MescladorPDF.exe`**

Duplo clique para abrir (nao precisa instalar Python). O Windows pode pedir confirmacao na primeira execucao (SmartScreen).

Para regerar o EXE:

```bash
cd tools/pdf_mesclador
pip install pyinstaller
py -3 -m PyInstaller --onefile --windowed --name MescladorPDF --clean pdf_mesclador_gui.py
```
