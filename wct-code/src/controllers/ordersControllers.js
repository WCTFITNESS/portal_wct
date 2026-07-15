const { generateExcelBuffer, formatDateTime } = require('../utils/utils');
const { getAllProductsWithPeriod } = require('../services/meli');
const { summarizeItems } = require('../services/summarizeItems');

const orderSummary = async (req, res) => {
    try {
        if(!req.query.from || !req.query.to) {
            return res.json({ message: "Data inválida" })
        }

        const from = formatDateTime(req.query.from);
        const to = formatDateTime(req.query.to);

        const response = await getAllProductsWithPeriod(from, to);

        const result = summarizeItems(response);

        const header = ['MLB', 'SKU', 'Descrição', 'Qtd', 'Valor'];

        const excelBuffer = await generateExcelBuffer(result, header);

        res.setHeader('Content-Disposition', 'attachment; filename=vendas.xlsx');
        res.setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        res.send(excelBuffer);
    } catch (error) {
        console.error("Erro ao gerar relatório:", error);
        res.status(500).json({ error: error.message, stack: error.stack }); // Retorna o erro detalhado
    }
}

module.exports = { orderSummary }