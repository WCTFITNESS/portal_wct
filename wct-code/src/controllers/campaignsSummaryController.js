const { addDays, parseISO, format } = require('date-fns');

const { getAllCampaignName, campaignItens } = require('../services/meli');

/**
 * Retorna todas as campanhas ativas para participar 
 * Ex:
 * {
		"id": "P-MLB14319108",
		"type": "PRICE_MATCHING",
		"status": "started",
		"start_date": "2024-10-16T03:10:00.23286Z",
		"finish_date": "2025-01-07T02:50:00Z",
		"deadline_date": "2025-01-01T02:50:00Z",
		"name": "Saia na frente da concorrência e reduza suas tarifas de venda"
	},

 */

	const campaignsSummary = async (req, res, status) => {
		try {
			const data = await getAllCampaignName();
	
			let response = [];
			for (let r of data.results) {
	
				// Verificar se start_date e end_date são válidos antes de chamar parseISO
				if (!r.start_date || !r.finish_date) {
					console.error('Data inválida encontrada, skipping campaign:', r);
					continue; // Pula esta campanha se as datas não forem válidas
				}
	
				// Remove milissegundos extras (se necessário)
				let startDate = r.start_date;
				let finishDate = r.finish_date;
	
				// Truncando os milissegundos para 3 dígitos, se houver
				startDate = startDate.replace(/\.\d{3,}/, '');  // Remove milissegundos extras
				finishDate = finishDate.replace(/\.\d{3,}/, '');  // Remove milissegundos extras
	
				// Alterando a data de início para 7 dias depois da data original
				const newStartDate = addDays(parseISO(startDate), 7);
				const newEndDate = addDays(parseISO(finishDate), 7);
	
				const updatedData = {
					...r,
					start_date: format(newStartDate, "dd/MM/yyyy"), // Formato ISO
					end_date: format(newEndDate, "dd/MM/yyyy"),   // Nova data de término
				};
	
				const res = await campaignItens(r.id, r.type, status);
				
				if(!res) {
					console.log(`Dados não encontrados para ID ${r.id}, pulando...`);
					continue;
				}
	
				response.push({
					data: updatedData,
					total: res.paging.total,
				});
			}
			return response;
		} catch (error) {
			console.error('Error:', error);  // Verifique o erro completo
			return error.message;
		}
	};
	
module.exports = { campaignsSummary }