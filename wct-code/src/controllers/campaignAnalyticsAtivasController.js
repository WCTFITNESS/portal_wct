const { getCampaign, fetchPromotions, getProductInfo, getSku } = require('../services/meli');
const { generateExcelBuffer } = require('../utils/utils');
const { populateCampaignData } = require('../services/campaignUtils');
const { createResponse } = require('../services/campaignResponseService');

const header = ['SKU', 'MLB','SKU_2', 'VERDADEIRO', 'Preço de', 'Estoque', 'Tipo Campanha', 'Desconto ML R$',
    'Desconto ML %', 'Nosso desconto R$', 'Nosso desconto %',
    'Nosso desconto R$ Meli+', 'Nosso desconto % Meli+', 'ML Desconto R$ Meli+',
    'ML Desconto % Meli+', 'Preço final', 'Data Inicial', 'Data Final',
    'Status do Item', 'Status Campanha', 'Quantidade Vendida', "Anúncio Status", 'CODE', 'ID', 'TYPE'];


const processCampaignAnalyticsAtivas = async (req, res) => {
    try {
        const requestData = req.body;
          
        // Mapeia os dados recebidos no body
        const mappedData = requestData.map(item => ({
            id: item.id.replace('checkbox-', ''),
            type: item.type
        }));

        // Obtém todas as campanhas
        const campaignsSummary = await getCampaign();

        // Filtra campanhas com base nos IDs do request
        const matchingCampaigns = campaignsSummary.filter(campaign =>
            mappedData.some(data => data.id === campaign.id)
        );        
        const campaignResults = [];

        for (const campaign of matchingCampaigns) {
            const { id, name, type, start_date, finish_date, status } = campaign;

            // Busca promoções associadas a esta campanha
            // const promotions = await fetchPromotions(null, { id, type, status: 'candidate' });            
            const promotions = await fetchPromotions(searchAfter = null, id, type, 100, 'started', name, status, start_date, finish_date)

            // Popula os dados da campanha com informações adicionais
            // const adjustedCampaigns = populateCampaignData(promotions, id, name, start_date, finish_date, type, status);
            if (promotions && Array.isArray(promotions) && promotions.length > 0) {
                campaignResults.push(...promotions.filter(item => item != null));
            }
        }
        

        const response = await createResponse(campaignResults);
        const excelBuffer = await generateExcelBuffer(response, header);

        res.setHeader('Content-Disposition', 'attachment; filename=campanha.xlsx');
        res.setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        res.send(excelBuffer);
    } catch (error) {
        console.error('Erro ao processar analytics da campanha:', error);
        res.status(500).json({ error: 'Erro ao processar as campanhas' });
    }
};

module.exports = { processCampaignAnalyticsAtivas };
