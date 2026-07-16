const { getCampaign, fetchPromotionsWithFallback } = require('../services/meli');
const { generateExcelBuffer } = require('../utils/utils');
const { createResponse } = require('../services/campaignResponseService');

const header = ['SKU', 'MLB', 'SKU_2', 'VERDADEIRO', 'Preço de', 'Estoque', 'Tipo Campanha', 'Desconto ML R$',
    'Desconto ML %', 'Nosso desconto R$', 'Nosso desconto %',
    'Nosso desconto R$ Meli+', 'Nosso desconto % Meli+', 'ML Desconto R$ Meli+',
    'ML Desconto % Meli+', 'Preço final', 'Data Inicial', 'Data Final',
    'Status do Item', 'Status Campanha', 'Quantidade Vendida', 'Anúncio Status', 'CODE', 'ID', 'TYPE'];

const normalizeSelection = (requestData) => {
    const source = Array.isArray(requestData)
        ? requestData
        : (Array.isArray(requestData?.items) ? requestData.items : []);

    const seen = new Set();
    const normalized = [];

    for (const item of source) {
        if (!item || typeof item !== 'object') {
            continue;
        }

        const id = String(item.id || '')
            .trim()
            .replace(/^checkbox-/, '');
        const type = String(item.type || '').trim();

        if (id === '' || type === '') {
            continue;
        }

        const key = `${id}|${type}`;
        if (seen.has(key)) {
            continue;
        }

        seen.add(key);
        normalized.push({ id, type });
    }

    return normalized;
};

const processCampaignAnalyticsAtivas = async (req, res) => {
    try {
        const payload = req.body;
        const mappedData = normalizeSelection(payload);
        const itemStatus = String(payload?.itemStatus || 'started').trim() || 'started';

        if (mappedData.length === 0) {
            return res.status(400).json({ error: 'Selecione ao menos uma campanha valida.' });
        }

        const allCampaigns = await getCampaign();
        const campaignById = new Map();

        if (Array.isArray(allCampaigns)) {
            for (const campaign of allCampaigns) {
                if (campaign?.id) {
                    campaignById.set(String(campaign.id), campaign);
                }
            }
        }

        const campaignResults = [];
        const fetchErrors = [];

        for (const selected of mappedData) {
            const campaign = campaignById.get(selected.id);
            const name = campaign?.name || selected.id;
            const status = campaign?.status || 'started';
            const start_date = campaign?.start_date || '';
            const finish_date = campaign?.finish_date || campaign?.end_date || '';

            try {
                const { promotions, tried } = await fetchPromotionsWithFallback(
                    selected.id,
                    selected.type,
                    100,
                    name,
                    status,
                    start_date,
                    finish_date,
                    itemStatus
                );

                if (Array.isArray(promotions) && promotions.length > 0) {
                    campaignResults.push(...promotions.filter((item) => item != null));
                } else {
                    fetchErrors.push(`${selected.id}: nenhum item (${tried.join(', ')})`);
                }
            } catch (error) {
                fetchErrors.push(`${selected.id}: ${error.message}`);
            }
        }

        if (campaignResults.length === 0) {
            return res.status(400).json({
                error: 'Relatorio vazio. Nenhum anuncio retornado para as campanhas selecionadas.',
                details: fetchErrors.slice(0, 5),
            });
        }

        const response = await createResponse(campaignResults);

        if (!Array.isArray(response) || response.length === 0) {
            return res.status(400).json({
                error: 'Relatorio vazio. Nao foi possivel montar as linhas dos anuncios.',
                details: fetchErrors.slice(0, 5),
            });
        }

        const excelBuffer = await generateExcelBuffer(response, header);

        res.setHeader('Content-Disposition', 'attachment; filename=campanha.xlsx');
        res.setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        res.send(excelBuffer);
    } catch (error) {
        console.error('Erro ao processar analytics da campanha:', error);
        res.status(500).json({ error: 'Erro ao processar as campanhas', details: error.message });
    }
};

module.exports = { processCampaignAnalyticsAtivas };
