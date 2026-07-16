const axios = require('axios');
const { parseISO, formatDate } = require('date-fns');
const xl = require('excel4node');
const fs = require('fs');

// Função para formatar a data no estilo dd/mm/yyyy
function formatDateFunc(date) {
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0'); // Os meses começam em 0
    const year = date.getFullYear();
    return `${day}-${month}-${year}`;
}

function formatDateTime(inputDate) {
    // Divide a data no formato dd/mm/yyyy
    const [day, month, year] = inputDate.split("/");
  
    // Retorna no formato yyyy-mm-dd
    return `${year}-${month}-${day}`;
  }

// Função para realizar chamadas à API com tratamento de erro
const apiCall = async (url) => {
    const token = global.access_token || process.env.MELI_ACCESS_TOKEN || '';
    if (!token) {
        console.warn(`Token ML ausente ao acessar ${url}`);
        return null;
    }

    try {
        const response = await axios.get(url, {
            headers: {
                Authorization: `Bearer ${token}`,
            },
        });

        return response.data;
    } catch (error) {
        const detail = error.response?.data
            ? JSON.stringify(error.response.data)
            : error.message;
        console.warn(`Erro ao acessar ${url}. Ignorando e seguindo...`, detail);
        return null;
    }
};


const formatarData = (data) => { return formatDate(parseISO(data), 'dd-MM-yyyy'); }

const dataUsaFormat = (inputDate) => { 
    // Divide a data no formato dd/mm/yyyy
    const [day, month, year] = inputDate.split("/");

    // Retorna no formato yyyy-mm-dd
    return `${year}-${month}-${day}`;
}

// Função para gerar o Excel em memória
const generateExcelBuffer = (campanha, header) => {
    const wb = new xl.Workbook();
    const ws = wb.addWorksheet('Vendas');
    const styleTitle = wb.createStyle({
        font: {
            color: '#000000',
            size: 12,
            name: 'Calibri',
            bold: true,
        },
    });

    const style = wb.createStyle({
        font: {
            color: '#000000',
            size: 11,
            name: 'Calibri',
        },
    });

    // Escrevendo o cabeçalho
    header.map((title, index) => {
        const columnPosition = index + 1;
        ws.cell(1, columnPosition)
            .string(title)
            .style(styleTitle);
    });

    let id = 1; // Linha inicial após o cabeçalho
    campanha.map(el => {
        id++;
        Object.entries(el).forEach(([key, value], index) => {
            const position = index + 1;
            const cellValue = value != null ? value.toString() : "";
            ws.cell(id, position)
                .string(cellValue)
                .style(style);
        });
    });

    return wb.writeToBuffer(); // Gera o arquivo Excel em memória e retorna o buffer
}

module.exports = { formatDateFunc, apiCall, formatarData, dataUsaFormat, generateExcelBuffer, formatDateTime }