const axios = require('axios');
const { apiCall, dataUsaFormat } = require('../utils/utils')

const BASE_URL_ML = 'https://api.mercadolibre.com';
const resolveSellerId = () => String(process.env.MELI_SELLER_ID || global.seller_id || '141958250');
/**
 * Função assíncrona para obter o SKU de um item a partir de sua resposta detalhada.
 * 
 * A função busca o atributo `SELLER_SKU` no objeto `detailResponse` para identificar 
 * o SKU do produto. Caso o atributo não seja encontrado ou o valor não seja válido, 
 * ela tenta buscar o atributo `MODEL`. 
 * 
 * Se nenhum SKU válido for encontrado diretamente, a função tenta obter os dados 
 * de variações do item através de uma requisição à API, verificando o atributo 
 * `SELLER_SKU` de cada variação.
 * 
 * Regras de validação para o SKU:
 * - Deve conter apenas números (`/^\d+$/`).
 * - Deve ter exatamente 8 caracteres.
 * - Não pode começar com o número "5".
 * 
 * @param {Object} detailResponse - Objeto contendo os detalhes de um item, incluindo:
 *   - attributes (Array): Lista de atributos do item.
 *   - variations (Array): Lista de variações do item.
 * @returns {Promise<string|null>} Retorna o SKU válido como uma string ou `null` caso nenhum SKU válido seja encontrado.
 * 
 * @example
 * const detailResponse = {
 *   attributes: [
 *     { id: 'SELLER_SKU', value_name: '12345678' },
 *     { id: 'MODEL', value_name: '87654321' }
 *   ],
 *   variations: []
 * };
 * 
 * const sku = await getSku(detailResponse);
 * console.log(sku); // Saída: '12345678'
 * 
 * @throws {Error} Em caso de falha na requisição da API para buscar dados de variações.
 */

const getSku = async (detailResponse) => {

    let sku;
    sku = detailResponse.attributes.find(attr => attr.id === 'SELLER_SKU');
    

    if (!sku || !/^\d+$/.test(sku.value_name) || sku.value_name.length !== 8 || sku.value_name.startsWith("5")) {
        sku = detailResponse.attributes.find(attr => attr.id === 'MODEL');
    }

    let skuValue = (sku && /^\d+$/.test(sku.value_name) && sku.value_name.length === 8 && !sku.value_name.startsWith("5"))
        ? sku.value_name : null;

    if (!skuValue) {
        for (const variation of detailResponse.variations) {
            if (variation.item_relations && variation.item_relations.length > 0) {
                const variationId = variation.item_relations[0].id;

                try {
                    const responseVariation = await apiCall(`${BASE_URL_ML}/items?ids=${variationId}`);
                    const detailResponseVariation = responseVariation[0].body;

                    const skuVariation = detailResponseVariation.attributes.find(attr => attr.id === 'SELLER_SKU');

                    if (skuVariation && /^\d+$/.test(skuVariation.value_name) && skuVariation.value_name.length === 8 && !skuVariation.value_name.startsWith("5")) {
                        skuValue = skuVariation.value_name;
                        break;
                    }
                } catch (variationError) {
                    console.error(`Erro ao obter dados da variação para o item ${variationId}:`, variationError);
                }
            }
        }
    }

    return skuValue;
}

/**
 * Função assíncrona para recuperar todos os itens de uma pesquisa com paginação baseada em `scroll_id`.
 * 
 * A função realiza requisições consecutivas à API, utilizando o `scroll_id` para recuperar páginas subsequentes
 * de resultados, até que todos os itens sejam recuperados. Ela armazena todos os itens em um array e retorna
 * o resultado completo ao final. 
 * 
 * O uso de `scroll_id` é útil quando a quantidade de resultados é muito grande para ser retornada em uma única requisição.
 * 
 * @returns {Promise<Array>} Retorna um array contendo todos os itens recuperados.
 */
const fetchAllItemsWithPagination = async () => {
    let allItems = []; // Array para armazenar todos os itens recuperados
    let scrollId = null; // Inicializa o scroll_id como null, que será utilizado para a primeira requisição

    try {
        // Loop para realizar múltiplas requisições, buscando dados enquanto houver resultados
        while (true) {
            // Monta a URL com ou sem o scroll_id, dependendo se estamos na primeira requisição ou em requisições subsequentes
            const url = scrollId
                ? `${BASE_URL_ML}/users/${resolveSellerId()}/items/search?search_type=scan&scroll_id=${scrollId}`
                : `${BASE_URL_ML}/users/${resolveSellerId()}/items/search?search_type=scan`;

            // Realiza a chamada à API usando a função `apiCall`
            const response = await apiCall(url);

            // Adiciona os itens obtidos nesta requisição ao array `allItems`
            allItems.push(...response.results);

            // Atualiza o scroll_id para a próxima página, caso existam mais resultados
            scrollId = response.scroll_id;

            // Log para monitoramento do progresso de carregamento
            console.log(`Carregados ${allItems.length} itens...`);

            // Se não houver mais itens na resposta, finaliza o loop
            if (!response.results.length) {
                break;
            }
        }

        // Log final com o total de itens recuperados
        console.log(`Total de itens recuperados: ${allItems.length}`);

        // Retorna todos os itens recuperados
        return allItems;

    } catch (error) {
        // Em caso de erro, imprime a mensagem de erro no console e retorna um array vazio
        console.error("Erro ao buscar itens:", error.response || error.message);
        return [];
    }
}

/**
 * Função assíncrona para recuperar todos os itens de uma pesquisa com paginação baseada em `scroll_id`.
 * 
 * A função realiza requisições consecutivas à API, utilizando o `scroll_id` para recuperar páginas subsequentes
 * de resultados, até que todos os itens sejam recuperados. Ela armazena todos os itens em um array e retorna
 * o resultado completo ao final. 
 * 
 * O uso de `scroll_id` é útil quando a quantidade de resultados é muito grande para ser retornada em uma única requisição.
 * 
 * @returns {Promise<Array>} Retorna um array contendo todos os itens recuperados.
 */
const fetchAllItemsFulfillmentWithPagination = async () => {
    let allItems = []; // Array para armazenar todos os itens recuperados
    let scrollId = null; // Inicializa o scroll_id como null, que será utilizado para a primeira requisição

    try {
        // Loop para realizar múltiplas requisições, buscando dados enquanto houver resultados
        while (true) {
            // Monta a URL com ou sem o scroll_id, dependendo se estamos na primeira requisição ou em requisições subsequentes
            const url = scrollId
                ? `${BASE_URL_ML}/users/${resolveSellerId()}/items/search?search_type=scan&logistic_type=fulfillment&scroll_id=${scrollId}`
                : `${BASE_URL_ML}/users/${resolveSellerId()}/items/search?search_type=scan&logistic_type=fulfillment`;

            // Realiza a chamada à API usando a função `apiCall`
            const response = await apiCall(url);

            // Adiciona os itens obtidos nesta requisição ao array `allItems`
            allItems.push(...response.results);

            // Atualiza o scroll_id para a próxima página, caso existam mais resultados
            scrollId = response.scroll_id;

            // Log para monitoramento do progresso de carregamento
            console.log(`Carregados ${allItems.length} itens...`);

            // Se não houver mais itens na resposta, finaliza o loop
            if (!response.results.length) {
                break;
            }
        }

        // Log final com o total de itens recuperados
        console.log(`Total de itens recuperados: ${allItems.length}`);

        // Retorna todos os itens recuperados
        return allItems;

    } catch (error) {
        // Em caso de erro, imprime a mensagem de erro no console e retorna um array vazio
        console.error("Erro ao buscar itens:", error.response || error.message);
        return [];
    }
}


const fetchItemsWithoutAutoPagination = async (pageLimit = 50, scrollId = null) => {
    try {
        // Monta a URL com base no scroll_id e no limite de resultados por página
        const url = scrollId
            ? `${BASE_URL_ML}/users/${resolveSellerId()}/items/search?search_type=scan&limit=${pageLimit}&scroll_id=${scrollId}`
            : `${BASE_URL_ML}/users/${resolveSellerId()}/items/search?search_type=scan&limit=${pageLimit}`;

        // Realiza a chamada à API usando a função `apiCall`
        const response = await apiCall(url);

        // Log para monitorar o progresso
        console.log(`Itens carregados: ${response.results.length}`);

        // Retorna os resultados e o scroll_id para continuar a paginação
        return {
            items: response.results,
            nextScrollId: response.scroll_id,
        };
    } catch (error) {
        // Em caso de erro, imprime a mensagem de erro no console
        console.error("Erro ao buscar itens:", error.response || error.message);
        return {
            items: [],
            nextScrollId: null,
        };
    }
};


const getModeration = async (mlb) => {
    try {
        const response = await apiCall(`${BASE_URL_ML}/moderations/infractions/${resolveSellerId()}?related_item_id=${mlb}`);
        const data = response.infractions[0].reason;
        return data;
    } catch (error) {
        console.error('Não foi encontrado moderação ', error.message);
        return null;
    }
}

// Retorna todas as campanhas
const getCampaign = async () => {
    const url = `${BASE_URL_ML}/seller-promotions/users/${resolveSellerId()}?app_version=v2`;
    const data = await apiCall(url);
    return data.results;
}

const getProductInfo = async (mlb) => {
    const url = `${BASE_URL_ML}/items/${mlb}`;
    const response = await apiCall(url);
    return response;
}

const getProductVisits = async (mlb) => {
    const url = `${BASE_URL_ML}/visits/items?ids=${mlb}`;
    const response = await apiCall(url);
    return response;
}

const getMultiProductsInfo = async (mlb) => {
    const url = `${BASE_URL_ML}/items?ids=${mlb}`;
    const response = await apiCall(url);
    return response;
}

const fetchShippingCost = async (mlb) => {
    let costFreight = 0;

    try {
        const response = await apiCall(`${BASE_URL_ML}/items/${mlb}/shipping_options?zip_code=89223400`);

        const shippingOptions = response;

        if (shippingOptions.options && shippingOptions.options.length > 0) {
            costFreight = shippingOptions.options[0].list_cost;
        }
    } catch (error) {
        if (error.response && error.response.data.error === 'invalid_area_for_shipment') {
            console.log(`Cobertura de frete não encontrada para o item ${mlb}. Definindo custo de frete como zero.`);
        } else {
            console.error(`Erro ao buscar custo de frete para o item ${mlb}:`, error.message);
        }
    }

    return costFreight;
}

const claims = async (start_date, end_date) => {

    const from = dataUsaFormat(start_date);
    const to = dataUsaFormat(end_date);

    const afterDate = `${from}T00:00:00.000Z`; // Essa data tem de ser 2 meses antes de hoje - 1 dia
    const beforeDate = `${to}T23:59:59.999Z`; // Essa é a data de hoje

    const limit = 100;
    let offset = 0;
    let total = 0;
    let allClaims = [];
    do {
        const response = await apiCall(`${BASE_URL_ML}/post-purchase/v1/claims/search?status=closed,opened&stage=claim,dispute,recontact,stale,none&sort=last_updated:desc&range=last_updated:after:${afterDate}:before:${beforeDate}&offset=${offset}&limit=${limit}`);
        const data = response.data;

        allClaims.push(data);

        total = response.paging.total;
        offset += limit;

    } while (offset < total);

    return allClaims;
}

const claimDetail = async (claim) => {
    const response = await apiCall(`${BASE_URL_ML}/post-purchase/v1/claims/${claim.id}/detail`);
    return response;
}

const claimReputation = async (claim) => {
    const response = await apiCall(`${BASE_URL_ML}/post-purchase/v1/claims/${claim.id}/affects-reputation`);
    return response;
}

// Função para buscar todos os produtos paginados
const getAllProductsWithPeriod = async (from, to) => {
    const limit = 50; // Máximo de itens por página (definido pela API)
    let offset = 0; // Inicializa o offset
    let allProducts = []; // Armazena os produtos retornados

    try {
        while (true) {
            const url = `${BASE_URL_ML}/orders/search?seller=${resolveSellerId()}` +
                `&order.date_created.from=${from}T00:00:00.000-00:00` +
                `&order.date_created.to=${to}T00:00:00.000-00:00` +
                `&order.status=paid` +
                `&limit=${limit}&offset=${offset}`;

            // Faz a chamada à API
            const response = await apiCall(url);

            // Adiciona os produtos retornados à lista
            allProducts = allProducts.concat(response.results);

            // Verifica se já buscou todos os itens
            const total = response.paging.total;
            offset += limit;

            if (offset >= total) break; // Sai do loop se já obteve todos os itens
        }

        return allProducts; // Retorna todos os produtos
    } catch (error) {
        console.error('Erro ao buscar produtos:', error);
        throw error;
    }
}

const getAllCampaignName = async () => {
    const response = await apiCall(`${BASE_URL_ML}/seller-promotions/users/${resolveSellerId()}?app_version=v2`);
    return response;
}

const fetchAllPromotions = async (searchAfter = null, data) => {    
    let response = [];
    let url = `${BASE_URL_ML}/seller-promotions/promotions/${data.id}/items?promotion_type=${data.type}&app_version=v2&limit=100&status=${data.status}`;
    if (searchAfter) url += `&searchAfter=${searchAfter}`;

    const { results, paging } = await apiCall(url);
    
    // Adicionar os resultados atuais ao array de resposta
    response = response.concat(results);
    
    // Caso haja mais páginas, chamar recursivamente
    if (paging && paging.searchAfter) {
        const nextResults = await fetchPromotions(paging.searchAfter, data);
        response = response.concat(nextResults);
    }

    return response;
};

const fetchPromotions = async (searchAfter = null, promotion_code, promotion_type, limit, status, title, status_campaing, start_date, finish_date) => {
    let r = []
    try {
        let url = `${BASE_URL_ML}/seller-promotions/promotions/${promotion_code}/items?promotion_type=${promotion_type}&app_version=v2&limit=${limit}&status=${status}`;

        if (searchAfter) url += `&searchAfter=${searchAfter}`;

            const { results, paging } = await apiCall(url);
        
        // const { results = [], paging = {} } = response.data || {};

        if (Array.isArray(results)) {
            for (const result of results) {
                // Define valores comuns para todos os resultados
                result.title = title;
                result.type = promotion_type;
                result.status_campaing = status_campaing;
                result.id_campaign = promotion_code;

                // Configurações específicas para promoções do tipo 'DEAL'
                if (promotion_type === 'DEAL') {
                    result.start_date = start_date;
                    result.end_date = finish_date;
                }

                if (['DOD', 'LIGHTNING'].includes(promotion_type)) {
                    const today = new Date();
                    const formattedDate = today.toISOString().split('T')[0] + 'T03:00:00Z';

                    result.start_date = formattedDate;
                    result.end_date = formattedDate;
                }

                // Configurações específicas para promoções do tipo 'DEAL' ou 'SELLER_CAMPAIGN'
                if (['DEAL', 'SELLER_CAMPAIGN'].includes(promotion_type)) {
                    result.meli_percentage = 0;
                    result.seller_percentage = 0;
                }

                // Exceção específica para o título 'PROMO - PETINA OFICIAL'
                if (promotion_code == 'C-MLB1522238') {
                    result.seller_percentage = 5; // Este valor pode variar
                }


                if (promotion_type === 'DOD') {
                    result.title = 'Oferta do Dia'
                }

                if (promotion_type === 'LIGHTNING') {
                    result.title = 'Oferta Relâmpago'
                }

                // Adiciona o resultado processado à lista
                r.push(result);
            }

        } else {
            console.error('Resultados não são um array:', results);
            return []
        }

        if (paging && paging.searchAfter) {
            r = r.concat(await fetchPromotions(paging.searchAfter, promotion_code, promotion_type, limit, status, title, status_campaing, start_date, finish_date));
        }

        return r;
    } catch (error) {
        console.error('Erro ao buscar promoções:', error);
    }

}


const campaignDetails = async (id, type) => {
    let url = `${BASE_URL_ML}/seller-promotions/promotions/${id}?promotion_type=${type}&app_version=v2`
    const response = await apiCall(url);

    return response;
}

const campaignItens = async (id, type, status) => {
    let url = `${BASE_URL_ML}/seller-promotions/promotions/${id}/items?promotion_type=${type}&app_version=v2&limit=1&status=${status}`
    const response = await apiCall(url);

    return response;
}

const pendingItems = async (page = 1, limit) => {
    const offset = (page - 1) * limit; // Calcular o deslocamento baseado na página
    let url = `${BASE_URL_ML}/users/${resolveSellerId()}/items/search?status=pending&limit=${limit}&offset=${offset}`;
    const response = await apiCall(url);
    const data = response.results;
    const total = response.paging.total;
    
    return { data, total };
}

const priceCampaigns = async (mlb) => {
    try {
        const url = `${BASE_URL_ML}/items/${mlb}/prices`;

        const response = await apiCall(url);

        return Math.min(...response.prices.map(price => price.amount));
    } catch (error) {

    }
}

module.exports = { 
    getSku,
    fetchAllItemsWithPagination,
    getModeration,
    getCampaign,
    getProductInfo,
    fetchShippingCost,
    claims,
    claimDetail,
    claimReputation,
    getAllProductsWithPeriod,
    getAllCampaignName,
    fetchPromotions,
    campaignDetails,
    campaignItens,
    fetchItemsWithoutAutoPagination,
    pendingItems,
    getMultiProductsInfo,
    priceCampaigns,
    getProductVisits,
    fetchAllItemsFulfillmentWithPagination
};
