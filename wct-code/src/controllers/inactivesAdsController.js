const xlsx = require('xlsx');
const fs = require("fs");
const { getSku, fetchItemsWithoutAutoPagination, getProductInfo, getModeration, pendingItems,getMultiProductsInfo } = require('../services/meli');


async function getInactiveItems(page = 1, limit) {
    try {

        //Retorna todos os itens (MLBs) do anunciante
        // const resultAllItems = await fetchItemsWithoutAutoPagination();
        const { data: resultAllItems, total } = await pendingItems(page, limit);

        const respon = await getMultiProductsInfo(resultAllItems);   

        const inactiveItems = [];
        for (const itemId of respon) {            
            let item = itemId.body;
            
            // const itemResponse = await getProductInfo(itemId)
            // const item = itemResponse;
            const sku = await getSku(item);

            const moderation = await getModeration(item.id);

            let status;

            if (item.status == 'active' && moderation) {
                item.status == 'inactive'
            }

            // Filtrar anúncios inativos
            if (item.status != "active") {

                if (item.status === 'under_review') {
                    status = 'Em Revisão';
                } else if (item.status === 'paused') {
                    status = 'Pausado';
                }

                let motivo;

                if (item.sub_status[0] === 'waiting_for_patch') {
                    motivo = 'Inativo para revisar';
                } else if (item.sub_status[0] === 'out_of_stock') {
                    motivo = 'Sem estoque'
                } else if (item.sub_status[0] === 'forbidden') {
                    const moderation = await getModeration(itemId);
                    if (moderation === null) {
                        motivo = 'Usuário precisa trocar categoria';
                    } else {
                        motivo = moderation;
                    }
                } else {
                    motivo = 'Pausado pelo usuário'
                }

                let fulfillment = 'Não';
                if (item.shipping.logistic_type === 'fulfillment') {
                    fulfillment = 'Sim';
                }

                inactiveItems.push({
                    id: item.id,
                    sku: sku,
                    title: item.title,
                    permalink: item.permalink,
                    thumbnail: `//${item.thumbnail.split('//')[1]}`,
                    status: status,
                    statusDetail: motivo,
                    stock: item.available_quantity,
                    shipping: item.shipping.mode,
                    fulfillment: fulfillment,
                    sold_quantity: item.sold_quantity
                });

            }
        }

        return { inactiveItems, total };

    } catch (error) {
        console.error("Erro ao obter anúncios inativos:", error.response?.data || error.message);
        return { inactiveItems: [], total: 0 };
    }
}

const inactiveAds = async (req, res) => {
    const page = parseInt(req.query.page) || 1;  // Página padrão é 1
    const limit = 15; // Número de itens por página (você pode tornar isso dinâmico também)
    
    const { inactiveItems, total } = await getInactiveItems(page, limit);

    // Calcular o total de páginas
    const totalPages = Math.ceil(total / limit);

    return {
        data: inactiveItems,
        currentPage: page,
        totalPages: totalPages
    };
}

module.exports = { inactiveAds };