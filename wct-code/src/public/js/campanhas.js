const wctBase = (typeof window !== 'undefined' && window.WCT_CODE_BASE) || '';
const btnDownload = document.getElementById('btn_download');
const btnUpload = document.getElementById('btn_upload');
let selectedItems = []; // Array para armazenar os objetos selecionados

// Seleciona todas as linhas da tabela
const rows = document.querySelectorAll('tr');

// Referência ao checkbox "Selecionar Todos"
const selectAllCheckbox = document.getElementById('select-all');

document.querySelectorAll('.checkbox').forEach(checkbox => {
    checkbox.addEventListener('click', function (event) {
        // Evita que o clique no checkbox dispare o evento no <tr>
        event.stopPropagation();

        const row = checkbox.closest('tr'); // Encontra o <tr> mais próximo
        const id = row.getAttribute('data-id');
        const type = row.getAttribute('data-type');

        if (checkbox.checked) {
            // Adiciona ao array se estiver marcado
            if (!selectedItems.some(item => item.id === id)) {
                selectedItems.push({ id, type });
            }
        } else {
            // Remove do array se estiver desmarcado
            selectedItems = selectedItems.filter(item => item.id !== id);
        }

        console.log(selectedItems); // Exibe os itens selecionados no console
    });
});

// Lida com o clique no checkbox "Selecionar Todos"
selectAllCheckbox.addEventListener('change', function () {
    // Marca ou desmarca todas as linhas
    rows.forEach((row, index) => {
        // Ignora o cabeçalho
        if (index === 0) return;

        const checkbox = row.querySelector('.checkbox');
        if (this.checked) {
            row.classList.add('selected');
            checkbox.checked = true;

            // Adiciona os dados ao array selectedItems se ainda não estiverem
            const id = row.getAttribute('data-id');
            const type = row.getAttribute('data-type');

            if (!selectedItems.some(item => item.id === id)) {
                selectedItems.push({ id, type });
            }
        } else {
            row.classList.remove('selected');
            checkbox.checked = false;

            // Remove os dados do array selectedItems
            const id = row.getAttribute('data-id');
            selectedItems = selectedItems.filter(item => item.id !== id);
        }
    });

    console.log(selectedItems); // Exibe o array no console (para debug)
});

// Adiciona o evento de clique nas linhas
rows.forEach(row => {
    row.addEventListener('click', function (event) {
        // Verifica se o clique foi no checkbox
        const checkbox = row.querySelector('.checkbox');

        if (event.target.tagName !== 'INPUT') {
            // Alterna a classe 'selected' para a linha inteira
            row.classList.toggle('selected');

            // Se a linha estiver selecionada, marcar o checkbox
            checkbox.checked = row.classList.contains('selected');

            // Atualiza o array selectedItems com base na seleção
            const id = row.getAttribute('data-id');
            const type = row.getAttribute('data-type');
            if (checkbox.checked) {
                selectedItems.push({ id, type });
            } else {
                selectedItems = selectedItems.filter(item => item.id !== id);
            }
        }

        console.log(selectedItems); // Exibe o array no console (para debug)
    });
});

btnDownload.addEventListener('click', async function () {
    console.log(selectedItems.length);
    const dataId = btnDownload.getAttribute('data-id');

    if (selectedItems.length == 0) {
        alert('selecione ao menos 1 campanha')
    } else {
        await downloadCampaigns(selectedItems, dataId);
    }
})

async function downloadCampaigns(selectedItems, dataId) {
    showTrigger('Baixando'); // Exibe o loading
    /*let data_id = dataId == null 
  ? (!selectedItems.length ? '' : selectedItems[0].id)
  : dataId;*/

   let data_id = dataId == null ? '' : dataId;

    try {
        const response = await fetch(`${wctBase}/api_meli/campaigns-analytics${data_id}?token=123456`, {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify(selectedItems),
        });

        console.log(response);

        if (!response.ok) {
            throw new Error(`Erro HTTP: ${response.status}`);
        }

        const blob = await response.blob(); // Recebe o conteúdo do arquivo como Blob
        const url = window.URL.createObjectURL(blob); // Cria uma URL para o Blob

        // Abre uma nova janela para o download
        const downloadWindow = window.open(); // Abre uma nova janela (pode ser uma aba ou popup)

        if (downloadWindow) {
            downloadWindow.location.href = url; // Define a URL do blob na nova janela
        } else {
            // Se o navegador bloquear o `window.open`, usamos o <a> como fallback
            const a = document.createElement('a'); // Cria um elemento <a>
            a.href = url;
            a.download = `campanha.xlsx`; // Define o nome do arquivo
            document.body.appendChild(a);
            a.click(); // Simula o clique para baixar
            document.body.removeChild(a); // Remove o <a> após o clique
        }

        window.URL.revokeObjectURL(url); // Libera o objeto URL após o uso
    } catch (error) {
        console.error("Erro na requisição:", error);
        alert("Houve um problema ao baixar o relatório.");
    } finally {
        hideTrigger(); // Oculta o loading em qualquer caso (sucesso ou erro)
    }
}

// script.js
document.addEventListener('DOMContentLoaded', () => {
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    const status = document.getElementById('status');

    // Adicionar eventos de arrastar e soltar
    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('dragover');
    });

    dropZone.addEventListener('dragleave', () => {
        dropZone.classList.remove('dragover');
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        const files = e.dataTransfer.files;
        handleFiles(files);
    });

    // Clique na área de upload para abrir o seletor de arquivos
    dropZone.addEventListener('click', () => {
        fileInput.click();
    });

    fileInput.addEventListener('change', (e) => {
        const files = e.target.files;
        handleFiles(files);
    });

    // Lida com os arquivos recebidos
    function handleFiles(files) {
        if (files.length === 0) {
            status.textContent = 'Nenhum arquivo selecionado.';
            return;
        }

        const file = files[0];
        if (isSpreadsheet(file)) {
            status.textContent = `Arquivo recebido: ${file.name}`;
            const uploadBtn = document.getElementById('upload_file');
            uploadBtn.style.display = 'flex';
            uploadBtn.addEventListener('click', function () {
                uploadFile(file);
            })
        } else {
            status.textContent = 'Por favor, envie uma planilha válida (.xls, .xlsx, .csv).';
        }
    }

    // Verifica se o arquivo é uma planilha
    function isSpreadsheet(file) {
        const validTypes = [
            'application/vnd.ms-excel', // .xls
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
            'text/csv' // .csv
        ];
        return validTypes.includes(file.type);
    }
});

btnUpload.addEventListener('click', function () {
    const dropZone = document.getElementsByClassName('drop-zone')[0];
      // Alterna o estilo de exibição
    if (dropZone.style.display === 'flex') {
        dropZone.style.display = 'none'; // Esconde a drop-zone
    } else {
        dropZone.style.display = 'flex'; // Mostra a drop-zone
    }
})


const uploadFile = (file) => {
    const formData = new FormData();
    formData.append('file', file);
    showTrigger('Processando');
    fetch(`${wctBase}/upload`, {
        method: 'POST',
        body: formData
    })
        .then(response => {
            if (!response.ok) {
                throw new Error('Erro ao fazer o upload do arquivo.');
            }
            return response.json();
        })
        .then(data => {
            hideTrigger();
            const status = document.getElementById('status');
            status.style.display = 'none'
            const uploadBtn = document.getElementById('upload_file');
            uploadBtn.style.display = 'none';
            const dropZone = document.getElementsByClassName('drop-zone')[0];
            dropZone.style.display = 'none'
            status.textContent = `Upload concluído: ${data.fileName}`;
            alert('Campanhas Processadas')
        })
        .catch(err => {
            hideTrigger();
            status.textContent = `Erro: ${err.message}`;
        });
}