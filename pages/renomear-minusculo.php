<?php

declare(strict_types=1);
?>
<style>
    .rn-hint { color: #64748b; font-size: .85rem; margin: 6px 0 0; }
    .rn-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 12px; }
    .rn-actions button { width: auto; margin-top: 0; }
    .rn-drop { border: 2px dashed #cbd5e1; border-radius: 10px; padding: 28px 16px; text-align: center; color: #475569; background: #f8fafc; margin-top: 12px; }
    .rn-drop.over { border-color: #f5b700; background: #fffbeb; }
    .rn-pick { display: inline-flex; gap: 8px; flex-wrap: wrap; justify-content: center; margin-top: 10px; }
    .rn-pick label { display: inline-block; width: auto; margin: 0; padding: 10px 16px; border-radius: 6px; background: #111; color: #f5b700; font-weight: bold; cursor: pointer; text-transform: uppercase; font-size: .8rem; }
    .rn-pick input { display: none; }
    .rn-table-wrap { overflow: auto; max-height: 55vh; margin-top: 12px; }
    .rn-table th { position: sticky; top: 0; background: #f1f5f9; font-size: .75rem; text-transform: uppercase; text-align: left; }
    .rn-table td { font-size: .82rem; font-family: monospace; word-break: break-all; }
    .rn-same { color: #94a3b8; }
    .rn-dup { color: #b45309; }
</style>

<section class="card">
    <h1>Renomear arquivos para minúsculo</h1>
    <p class="rn-hint" style="margin-top:0">
        Escolha vários arquivos (ou uma pasta inteira). O portal deixa só o <strong>nome dos arquivos</strong> em minúsculo
        — o conteúdo não muda e as pastas mantêm o nome original — e entrega tudo num ZIP.
        Os arquivos são processados no seu computador, não são enviados para o servidor.
    </p>

    <div class="rn-drop" id="rn-drop">
        Arraste os arquivos para cá
        <div class="rn-pick">
            <label>Escolher arquivos<input type="file" id="rn-files" multiple></label>
            <label>Escolher pasta<input type="file" id="rn-folder" webkitdirectory directory multiple></label>
        </div>
    </div>

    <p class="rn-hint" id="rn-summary"></p>
    <div class="rn-actions">
        <button type="button" id="rn-download" disabled>Baixar ZIP renomeado</button>
        <button type="button" id="rn-clear" disabled>Limpar</button>
    </div>

    <div class="rn-table-wrap" id="rn-preview" hidden>
        <table class="rn-table">
            <thead><tr><th>#</th><th>Nome original</th><th>Novo nome</th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</section>

<script>
(function () {
    var MAX_PREVIEW = 500;
    var MAX_ZIP_BYTES = 4 * 1024 * 1024 * 1024 - 1;
    var entries = [];

    var drop = document.getElementById('rn-drop');
    var summary = document.getElementById('rn-summary');
    var btnDownload = document.getElementById('rn-download');
    var btnClear = document.getElementById('rn-clear');
    var preview = document.getElementById('rn-preview');
    var tbody = preview.querySelector('tbody');

    function splitPath(path) {
        var i = path.lastIndexOf('/');
        return i < 0 ? ['', path] : [path.slice(0, i + 1), path.slice(i + 1)];
    }

    function addFiles(fileList) {
        var used = {};
        entries.forEach(function (e) { used[e.newPath.toLowerCase()] = true; });
        Array.prototype.forEach.call(fileList, function (file) {
            var original = (file.webkitRelativePath || file.name).replace(/\\/g, '/');
            var parts = splitPath(original);
            var newName = parts[1].toLowerCase();
            var newPath = parts[0] + newName;
            var dup = false;
            if (used[newPath.toLowerCase()]) {
                var dot = newName.lastIndexOf('.');
                var base = dot > 0 ? newName.slice(0, dot) : newName;
                var ext = dot > 0 ? newName.slice(dot) : '';
                var n = 2;
                while (used[(parts[0] + base + '-' + n + ext).toLowerCase()]) { n++; }
                newPath = parts[0] + base + '-' + n + ext;
                dup = true;
            }
            used[newPath.toLowerCase()] = true;
            entries.push({ file: file, original: original, newPath: newPath, dup: dup });
        });
        render();
    }

    function render() {
        var total = entries.reduce(function (s, e) { return s + e.file.size; }, 0);
        var changed = entries.filter(function (e) { return e.original !== e.newPath; }).length;
        var dups = entries.filter(function (e) { return e.dup; }).length;
        summary.textContent = entries.length === 0 ? '' :
            entries.length + ' arquivo(s), ' + (total / 1048576).toFixed(1).replace('.', ',') + ' MB — '
            + changed + ' com nome alterado'
            + (dups > 0 ? ', ' + dups + ' ganharam número no final porque ficariam com o mesmo nome de outro' : '') + '.';
        btnDownload.disabled = entries.length === 0;
        btnClear.disabled = entries.length === 0;
        preview.hidden = entries.length === 0;
        tbody.innerHTML = '';
        entries.slice(0, MAX_PREVIEW).forEach(function (e, i) {
            var tr = document.createElement('tr');
            var cls = e.dup ? 'rn-dup' : (e.original === e.newPath ? 'rn-same' : '');
            [String(i + 1), e.original, e.newPath].forEach(function (text, col) {
                var td = document.createElement('td');
                td.textContent = text;
                if (col === 2 && cls) { td.className = cls; }
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
        if (entries.length > MAX_PREVIEW) {
            var tr = document.createElement('tr');
            var td = document.createElement('td');
            td.colSpan = 3;
            td.textContent = '... e mais ' + (entries.length - MAX_PREVIEW) + ' arquivo(s).';
            tr.appendChild(td);
            tbody.appendChild(tr);
        }
    }

    var CRC_TABLE = (function () {
        var t = new Uint32Array(256);
        for (var n = 0; n < 256; n++) {
            var c = n;
            for (var k = 0; k < 8; k++) { c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; }
            t[n] = c >>> 0;
        }
        return t;
    })();

    function crc32(bytes) {
        var c = 0xFFFFFFFF;
        for (var i = 0; i < bytes.length; i++) { c = CRC_TABLE[(c ^ bytes[i]) & 0xFF] ^ (c >>> 8); }
        return (c ^ 0xFFFFFFFF) >>> 0;
    }

    function dosDateTime(ms) {
        var d = new Date(ms || Date.now());
        var year = Math.max(1980, d.getFullYear());
        return {
            time: (d.getHours() << 11) | (d.getMinutes() << 5) | (d.getSeconds() >> 1),
            date: ((year - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate()
        };
    }

    // ZIP sem compressão (método "store"), nomes em UTF-8.
    async function buildZip(list, onProgress) {
        var enc = new TextEncoder();
        var parts = [];
        var central = [];
        var offset = 0;
        for (var i = 0; i < list.length; i++) {
            var e = list[i];
            var data = new Uint8Array(await e.file.arrayBuffer());
            var name = enc.encode(e.newPath);
            var crc = crc32(data);
            var dt = dosDateTime(e.file.lastModified);

            var lh = new DataView(new ArrayBuffer(30));
            lh.setUint32(0, 0x04034b50, true);
            lh.setUint16(4, 20, true);
            lh.setUint16(6, 0x0800, true);
            lh.setUint16(8, 0, true);
            lh.setUint16(10, dt.time, true);
            lh.setUint16(12, dt.date, true);
            lh.setUint32(14, crc, true);
            lh.setUint32(18, data.length, true);
            lh.setUint32(22, data.length, true);
            lh.setUint16(26, name.length, true);
            lh.setUint16(28, 0, true);
            parts.push(lh.buffer, name, data);

            var ch = new DataView(new ArrayBuffer(46));
            ch.setUint32(0, 0x02014b50, true);
            ch.setUint16(4, 20, true);
            ch.setUint16(6, 20, true);
            ch.setUint16(8, 0x0800, true);
            ch.setUint16(10, 0, true);
            ch.setUint16(12, dt.time, true);
            ch.setUint16(14, dt.date, true);
            ch.setUint32(16, crc, true);
            ch.setUint32(20, data.length, true);
            ch.setUint32(24, data.length, true);
            ch.setUint16(28, name.length, true);
            ch.setUint32(42, offset, true);
            central.push(ch.buffer, name);

            offset += 30 + name.length + data.length;
            if (onProgress) { onProgress(i + 1, list.length); }
        }
        var cdSize = central.reduce(function (s, p) { return s + p.byteLength; }, 0);
        var end = new DataView(new ArrayBuffer(22));
        end.setUint32(0, 0x06054b50, true);
        end.setUint16(8, list.length, true);
        end.setUint16(10, list.length, true);
        end.setUint32(12, cdSize, true);
        end.setUint32(16, offset, true);
        return new Blob(parts.concat(central, [end.buffer]), { type: 'application/zip' });
    }

    btnDownload.addEventListener('click', async function () {
        var total = entries.reduce(function (s, e) { return s + e.file.size; }, 0);
        if (entries.length > 65535 || total > MAX_ZIP_BYTES) {
            alert('Lote grande demais para um ZIP só (máximo 65.535 arquivos e 4 GB). Divida em partes.');
            return;
        }
        var label = btnDownload.textContent;
        btnDownload.disabled = true;
        try {
            var blob = await buildZip(entries, function (done, count) {
                btnDownload.textContent = 'Preparando ' + done + ' de ' + count + '...';
            });
            var a = document.createElement('a');
            var stamp = new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '');
            a.href = URL.createObjectURL(blob);
            a.download = 'arquivos-minusculo-' + stamp + '.zip';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(function () { URL.revokeObjectURL(a.href); }, 60000);
        } catch (err) {
            alert('Não foi possível gerar o ZIP: ' + (err && err.message ? err.message : err));
        } finally {
            btnDownload.textContent = label;
            btnDownload.disabled = entries.length === 0;
        }
    });

    btnClear.addEventListener('click', function () {
        entries = [];
        document.getElementById('rn-files').value = '';
        document.getElementById('rn-folder').value = '';
        render();
    });

    ['rn-files', 'rn-folder'].forEach(function (id) {
        document.getElementById(id).addEventListener('change', function (ev) {
            addFiles(ev.target.files);
            ev.target.value = '';
        });
    });

    drop.addEventListener('dragover', function (ev) { ev.preventDefault(); drop.classList.add('over'); });
    drop.addEventListener('dragleave', function () { drop.classList.remove('over'); });
    drop.addEventListener('drop', function (ev) {
        ev.preventDefault();
        drop.classList.remove('over');
        var files = Array.prototype.filter.call(ev.dataTransfer.files, function (f) { return f.size > 0 || f.type !== ''; });
        addFiles(files);
    });
})();
</script>
