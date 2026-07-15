const axios = require('axios');
const mysql = require('mysql2/promise');
const xlsx = require('xlsx');
const { getToken, getSellerId } = require('../../token');

async function loadSkuDataFromDB() {
    const connection = await mysql.createConnection({
        host: process.env.WCT_FRETE_DB_HOST || '192.169.82.86',
        user: process.env.WCT_FRETE_DB_USER || 'wctcodec_wct',
        password: process.env.WCT_FRETE_DB_PASS || 'Wct357159.!',
        database: process.env.WCT_FRETE_DB_NAME || 'wctcodec_skus',
    });

    const [rows] = await connection.execute(`
        SELECT sku, peso AS p, largura AS l, comprimento AS c, altura AS a, preco
        FROM tabela_skus
    `);

    await connection.end();

    const map = {};
    rows.forEach((row) => {
        if (row.sku) {
            map[row.sku.toString()] = {
                p: Number(row.p),
                l: Number(row.l),
                c: Number(row.c),
                a: Number(row.a),
                preco: parseFloat(row.preco.toString().replace('.', '').replace(',', '.')),
            };
        }
    });

    return map;
}

async function getOrders(dateFrom, dateTo) {
    const limit = 50;
    let offset = 0;
    let all = [];
    let total = 0;
    const token = await getToken();
    const sellerId = await getSellerId();

    do {
        const url = `https://api.mercadolibre.com/orders/search?seller=${sellerId}&order.date_created.from=${dateFrom}T00:00:00.000-00:00&order.date_created.to=${dateTo}T23:59:59.000-00:00&shipping.logistic_type=me2&limit=${limit}&offset=${offset}`;
        try {
            const { data } = await axios.get(url, {
                headers: { Authorization: `Bearer ${token}` },
            });
            all.push(...data.results);
            total = data.paging.total;
            offset += limit;
        } catch (err) {
            console.error('Erro ao buscar pedidos:', err.response?.data || err.message);
            break;
        }
    } while (offset < total);

    return all;
}

async function getShipmentCosts(shipmentId) {
    try {
        const token = await getToken();
        const { data } = await axios.get(
            `https://api.mercadolibre.com/shipments/${shipmentId}/costs`,
            { headers: { Authorization: `Bearer ${token}` } }
        );
        return data;
    } catch (err) {
        console.error(`Erro shipment ${shipmentId}:`, err.response?.data || err.message);
        return null;
    }
}

function getSellerSKU(order) {
    const item = order.order_items?.[0]?.item;
    return item && typeof item.seller_sku === 'string' ? item.seller_sku : null;
}

function calcularFrete(preco, pesoEmGramas) {
    const tabelas = [
        { precoMin: 0, precoMax: 78.99, tabela: [39.9, 42.9, 44.9, 46.9, 49.9, 53.9, 56.9, 88.9, 131.9, 146.9, 171.9, 197.9, 203.9, 210.9, 224.9, 240.9, 251.9, 279.9, 319.9, 357.9, 379.9, 498.9] },
        { precoMin: 79, precoMax: 99.99, tabela: [11.97, 12.87, 13.47, 14.07, 14.97, 16.17, 17.07, 26.67, 39.57, 44.07, 51.57, 59.37, 61.17, 63.27, 67.47, 72.27, 75.57, 83.97, 95.97, 107.37, 113.97, 149.67] },
        { precoMin: 100, precoMax: 119.99, tabela: [13.97, 15.02, 15.72, 16.42, 17.47, 18.87, 19.92, 31.12, 46.17, 51.42, 60.17, 69.27, 71.37, 73.82, 78.72, 84.32, 88.17, 97.97, 111.97, 125.27, 132.97, 174.62] },
        { precoMin: 120, precoMax: 149.99, tabela: [15.96, 17.16, 17.96, 18.76, 19.96, 21.56, 22.76, 35.56, 52.76, 58.76, 68.76, 79.16, 81.56, 84.36, 89.96, 96.36, 100.76, 111.96, 127.96, 143.16, 151.96, 199.56] },
        { precoMin: 150, precoMax: 199.99, tabela: [17.96, 19.31, 20.21, 21.11, 22.46, 24.26, 25.61, 40.01, 59.36, 66.11, 77.36, 89.06, 91.76, 94.91, 101.21, 108.41, 113.36, 125.96, 143.96, 161.06, 170.96, 224.51] },
        { precoMin: 200, precoMax: Infinity, tabela: [19.95, 21.45, 22.45, 23.45, 24.95, 26.95, 28.45, 44.45, 65.95, 73.45, 85.95, 98.95, 101.95, 105.45, 112.45, 120.45, 125.95, 139.95, 159.95, 178.95, 189.95, 249.45] },
    ];
    const faixasPeso = [
        300, 500, 1000, 2000, 3000, 4000, 5000,
        9000, 13000, 17000, 23000, 30000, 40000, 50000,
        60000, 70000, 80000, 90000, 100000, 125000, 150000, Infinity,
    ];
    const t = tabelas.find((x) => preco >= x.precoMin && preco <= x.precoMax);
    if (!t) return null;
    const idx = faixasPeso.findIndex((lim) => pesoEmGramas <= lim);
    return t.tabela[idx] || null;
}

function getDetailedShippingAnalysis(costs) {
    let paidBySeller = 0;
    const sender = costs?.senders?.[0];
    if (sender?.cost) paidBySeller = sender.cost;
    if (
        sender?.discounts?.some((d) => d.type === 'mandatory')
        && paidBySeller === 0
    ) {
        const d = sender.discounts.find((discount) => discount.type === 'mandatory');
        if (d?.promoted_amount) paidBySeller = d.promoted_amount;
    }
    return paidBySeller;
}

async function gerarRelatorioFrete(dateFrom, dateTo) {
    const orders = await getOrders(dateFrom, dateTo);
    const skuMap = await loadSkuDataFromDB();
    const results = [];

    for (const order of orders) {
        const sku = getSellerSKU(order);
        const shipmentId = order.shipping?.id;
        if (!sku || !skuMap[sku] || !shipmentId) continue;

        const skuInfo = skuMap[sku];
        const pesoCubico = (skuInfo.l * skuInfo.c * skuInfo.a) / 6000;
        const pesoFinal = Math.max(skuInfo.p, pesoCubico);
        const valorEsperado = calcularFrete(skuInfo.preco, pesoFinal);

        const costs = await getShipmentCosts(shipmentId);
        if (!costs || valorEsperado === null) continue;

        const valorPago = getDetailedShippingAnalysis(costs);
        const diff = Number((valorPago - valorEsperado).toFixed(2));

        results.push({
            pedido: `#${order.id}`,
            sku,
            valor_esperado: valorEsperado,
            valor_pago: valorPago,
            diferenca: diff,
            status: diff > 0
                ? 'Cobrou a Mais'
                : diff < 0
                    ? 'ML/Cliente Pagou'
                    : 'Valor Correto',
            peso: skuInfo.p,
            altura: skuInfo.a,
            largura: skuInfo.l,
            profundidade: skuInfo.c,
        });
    }

    const filename = 'relatorio_frete.xlsx';
    const ws = xlsx.utils.json_to_sheet(results);
    const wb = xlsx.utils.book_new();
    xlsx.utils.book_append_sheet(wb, ws, 'ComparativoFrete');
    xlsx.writeFile(wb, `./public/relatorios/${filename}`);

    return `/relatorios/${filename}`;
}

module.exports = {
    gerarRelatorioFrete,
};
