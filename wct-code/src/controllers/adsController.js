const { fetchAllItemsWithPagination, fetchAllItemsFulfillmentWithPagination, getSku, getProductInfo, fetchShippingCost, priceCampaigns, getProductVisits } = require('../services/meli');
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

function extrairDimensoes(produto) {
  const resultado = {
    weight: 0,
    height: 0,
    width: 0,
    length: 0
  };

  if (!produto || !Array.isArray(produto.attributes)) {
    return resultado;
  }

  for (const attr of produto.attributes) {
    if (
      attr.values &&
      attr.values.length > 0 &&
      attr.values[0].struct &&
      typeof attr.values[0].struct.number === 'number' &&
      typeof attr.values[0].struct.unit === 'string'
    ) {
      const valor = attr.values[0].struct.number;
      const unit = attr.values[0].struct.unit;

      if (attr.id === "WEIGHT" || attr.id === "PACKAGE_WEIGHT") {
        resultado.weight = unit === 'kg' ? valor * 1000 : valor;
      } else if (attr.id === "HEIGHT" || attr.id === "PACKAGE_HEIGHT") {
        resultado.height = unit === 'm' ? valor * 100 : valor;
      } else if (attr.id === "WIDTH" || attr.id === "PACKAGE_WIDTH") {
        resultado.width = unit === 'm' ? valor * 100 : valor;
      } else if (attr.id === "LENGTH" || attr.id === "PACKAGE_LENGTH") {
        resultado.length = unit === 'm' ? valor * 100 : valor;
      }
    }
  }

  return resultado;
}


const details = async (mlb) => {
    
    const responseAllItens = await fetchAllItemsWithPagination();
    // const responseAllItens = await fetchAllItemsFulfillmentWithPagination();
    

    const allAds = [];

    for (const mlb of responseAllItens) {
        try {
            const item = await getProductInfo(mlb);

            const sku = await getSku(item)

            const cost_freight = await fetchShippingCost(mlb);
            const productVisits = await getProductVisits(mlb);
            const lower_price = await priceCampaigns(mlb);
            
            const dimensions = extrairDimensoes(item);
            

            allAds.push({
                mlb: mlb,
                title: item.title,
                original_price: item.original_price,
                price: item.price,
                mode: item.shipping.mode,
                full: item.shipping.logistic_type == 'fulfillment' ? 'SIM' : 'NÃO',
                active: getStatusDescription(item.status),
                sku: sku,
                cost_freight: item.shipping.mode == 'me1' ? '0' : cost_freight,
                shipping: item.shipping.free_shipping == false ? 'Pago' : 'Grátis',
                stock: item.available_quantity,
                sold: item.sold_quantity,
                visits: productVisits[mlb],
                //conversion: productVisits[mlb] > 0 ? parseFloat((item.sold_quantity / productVisits[mlb] * 100).toFixed(2)) : 0
                weight: (dimensions.weight).toFixed(2),
                height: (dimensions.height).toFixed(2),
                width: (dimensions.width).toFixed(2),
                length: (dimensions.length).toFixed(2),
                type: item.listing_type_id == 'gold_pro' ? "Premium" : "Clássico"
            });

            console.log(allAds)

        } catch (error) {
            console.log(error.message)
        }
    }

    return allAds;
}


const adsComplete = async (req, res) => {
    try {
        const responseDetails = await details();

        const header = ['MLB', 'Título', 'Preço De', 'Preço Por', 'Modo', 'Full', 'Status', 'SKU', 'Custo Frete', 'Frete Grátis', 'Estoque', 'Vendas', 'Visitas', 'Peso', 'Altura', 'Largura', 'Comprimento', 'tipo'];

        const excelBuffer = await generateExcelBuffer(responseDetails, header);

        // Definir cabeçalhos da resposta para download
        res.setHeader('Content-Disposition', 'attachment; filename=relatorio_anúncios.xlsx');
        res.setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Enviar o buffer como resposta
        res.send(excelBuffer);

    } catch (error) {
        console.error("Erro ao gerar o arquivo Excel:", error);
        res.status(500).send("Erro ao gerar o relatório.");
    }
};



module.exports = { adsComplete }