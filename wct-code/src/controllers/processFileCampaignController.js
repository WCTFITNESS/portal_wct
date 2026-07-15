const axios = require('axios');
const fs = require('fs');
const xlsx = require('xlsx');
const path = require('path');

async function delay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

async function excelRead(filePath) {    
    try {
        const workbook = xlsx.readFile(filePath);
        const sheetNames = workbook.SheetNames;
        const firstSheetName = sheetNames[0];
        const worksheet = workbook.Sheets[firstSheetName];

        const data = xlsx.utils.sheet_to_json(worksheet);
        let erro = [];
        let right = [];

        for (const item of data) {
            try {
                const mlb = item.MLB;
                const type = item.TYPE;
                const code = item.CODE;
                const id = item.ID;
                // const aprovados = item.APROVADO;

                // if (!aprovados) continue;

                let dataType;
                if (type === 'MARKETPLACE_CAMPAIGN') {
                    dataType = { promotion_id: id, promotion_type: type };
                } else if (type === 'SMART' || type === 'PRICE_MATCHING') {
                    dataType = { promotion_id: id, promotion_type: type, offer_id: code };
                } else if (type === 'UNHEALTHY_STOCK') {
                    dataType = { promotion_id: id, offer_id: code, promotion_type: type };
                } else if (type === 'SELLER_CAMPAIGN') {
                    const price = item.PRICE;
                    dataType = { promotion_id: id, promotion_type: type, deal_price: price };
                }

                // Enviar para o ML
                const response = await axios.post(`https://api.mercadolibre.com/seller-promotions/items/${mlb}?app_version=v2`, dataType, {
                    headers: { Authorization: `Bearer ${access_token}` },
                });

                console.log(response)
                right.push(response.data);

            } catch (error) {
                erro.push(item);
            }

            await delay(1000);
        }

        if (erro.length > 0) {
            const erroWorksheet = xlsx.utils.json_to_sheet(erro);
            const erroWorkbook = xlsx.utils.book_new();
            xlsx.utils.book_append_sheet(erroWorkbook, erroWorksheet, 'Erros');
            xlsx.writeFile(erroWorkbook, path.join(path.dirname(filePath), 'erros.xlsx'));
        }

        if (right.length > 0) {
            const rightWorksheet = xlsx.utils.json_to_sheet(right);
            const rightWorkbook = xlsx.utils.book_new();
            xlsx.utils.book_append_sheet(rightWorkbook, rightWorksheet, 'Validados');
            xlsx.writeFile(rightWorkbook, path.join(path.dirname(filePath), 'validados.xlsx'));
        }

        console.log('Processamento concluído.');

    } catch (err) {
        console.error('Erro geral no processamento:', err);
    }
}



module.exports = { excelRead };