const { fetchAllItemsWithPagination, getProductInfo, getSku, fetchShippingCost } = require('../services/meli');
const { generateExcelBuffer } = require('../utils/utils');

function getStatusDescription(status) {
    let description;

    if (status === 'under_review') {
        description = 'pendente';
    } else if (status === 'active') {
        description = 'ativo';
    } else if (status === 'paused') {
        description = 'pausado';
    } else if (status === 'closed') {
        description = 'finalizado';
    } else {
        description = 'status desconhecido'; // Valor padrão para status desconhecido
    }

    return description;
}



const itensSummary = async (req, res) => {
    try {
        const responseItens = await fetchAllItemsWithPagination();

        const itens = [];

        for (item of responseItens) {
            const productInfo = await getProductInfo(item);
            const sku = await getSku(productInfo);
            const freight = await fetchShippingCost(productInfo.id);
            
            itens.push({
                mlb: productInfo.id,
                title: productInfo.title,
                price: productInfo.price,
                full: productInfo.shipping.logistic_type == 'fulfillment' ? 'SIM' : 'NÃO',
                active: getStatusDescription(productInfo.status),
                sku,
                shipping: productInfo.shipping.free_shipping == false ? 'Pago' : 'Grátis',
                stock: productInfo.available_quantity,
                sold: productInfo.sold_quantity,
            });
        }

        const excelBuffer = await generateExcelBuffer(itens, header);
        res.setHeader('Content-Disposition', 'attachment; filename=anuncios.xlsx');
        res.setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        res.send(excelBuffer);

    } catch (error) {
        console.error('Erro ao processar anúncios:', error);
        res.status(500).json({ error: 'Erro ao processar os anúncios' });
    }

}

module.exports = { itensSummary };