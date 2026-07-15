const populateCampaignData = (promotions, id, campaignName, startDate, finishDate, campaignType, status) => {
    const populatedData = [];
    // Verifica se 'promotions' é um array
    if (Array.isArray(promotions)) {
        for (const promotionList of promotions) {
            // Garante que 'promotionList' é iterável
            if (Array.isArray(promotionList)) {
                for (const promotion of promotionList) {
                    promotion.title = campaignName;
                    promotion.type = campaignType;
                    promotion.status_campaign = status;
                    promotion.campaign_id = id;

                    if (campaignType === 'DEAL') {
                        promotion.start_date = startDate;
                        promotion.end_date = finishDate;
                    }
                    if (['DOD', 'LIGHTNING'].includes(campaignType)) {
                        const today = new Date();
                        const formattedDate = today.toISOString().split('T')[0] + 'T03:00:00Z';
                        promotion.start_date = formattedDate;
                        promotion.end_date = formattedDate;
                    }
                    if (['DEAL', 'SELLER_CAMPAIGN'].includes(campaignType)) {
                        promotion.meli_percentage = 0;
                        promotion.seller_percentage = 0;
                    }
                    if (campaignType === 'DOD') promotion.title = 'Oferta do Dia';
                    if (campaignType === 'LIGHTNING') promotion.title = 'Oferta Relâmpago';

                    populatedData.push(promotion);
                }
            } else {
                console.warn('promotionList não é um array:', promotionList);
            }
        }
    } else {
        console.warn('Promotions não é um array:', promotions);
    }

    return populatedData;
};

module.exports = { populateCampaignData };
