const { getProductInfo, getSku } = require('./meli');
const { formatarData } = require('../utils/utils');

const createResponse = async (data) => {
    const campaign = [];

    for (const item of data) {
        try {
            const ads = await getProductInfo(item.id);
            if (!ads) {
                continue;
            }

            const sku = await getSku(ads);
            let meli_moeda = 0;
            let vendedor_moeda = 0;
            let final_price = item.price;

            if (item.meli_percentage > 0) {
                const meliPercentageRounded = Math.ceil(item.meli_percentage);
                meli_moeda = parseFloat((item.price * (meliPercentageRounded / 100)).toFixed(2));
            }

            if (item.seller_percentage > 0) {
                vendedor_moeda = parseFloat((item.original_price * (item.seller_percentage / 100)).toFixed(2));
            }

            if (item.type === 'SELLER_CAMPAIGN') {
                final_price = (item.original_price - vendedor_moeda).toFixed(2).replace('.', ',');
            }

            if (item.type === 'VOLUME') {
                vendedor_moeda = item.original_price * (item.discount_percentage / 100);
            }

            campaign.push({
                sku,
                mlb: item.id,
                sku_2: '-',
                verdadeiro: 'verdadeiro',
                original_price: item.original_price,
                estoque: ads.available_quantity || 0,
                title: item.title,
                meli_moeda,
                meli_porcentagem: item.meli_percentage > 0 ? Math.ceil(item.meli_percentage) : 0,
                vendedor_moeda,
                vendedor_porcentagem: item.seller_percentage,
                desconto_vendedor_moeda_meliplus: '',
                desconto_vendedor_porcentagem_meliplus: '',
                desconto_moeda_meliplus: '',
                desconto_porcentagem_meliplus: '',
                price_pomocao: ['DOD', 'LIGHTNING'].includes(item.type) ? '' : final_price,
                start: item.start_date ? formatarData(item.start_date) : '',
                finish: item.end_date ? formatarData(item.end_date) : '',
                item_status: item.status,
                campanha_status: item.status_campaing,
                vendas: ads.sold_quantity,
                ads_status: ads.status,
                code: item.offer_id || '',
                id: item.id_campaign,
                type: item.type,
            });
        } catch (error) {
            console.warn(`Erro ao montar linha do item ${item?.id}:`, error.message);
        }
    }

    return campaign;
};

module.exports = { createResponse };
