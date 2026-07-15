const summarizeItems = (response) => {
    const summary = response.reduce((acc, item) => {
        const mlb = item.order_items[0].item.id;
        const price = item.payments[0].total_paid_amount;
        const title = item.order_items[0].item.title;
        const sku = item.order_items[0].item.seller_sku;

        // Verifica se o mlb já existe no acumulador
        if (!acc[mlb]) {
            acc[mlb] = {
                mlb,
                sku,
                title,
                quantity: 0,
                totalPrice: 0,
            };
        }

        // Atualiza os valores agregados
        acc[mlb].totalPrice += price;
        acc[mlb].quantity += 1;

        return acc;
    }, {});

    // Transforma o objeto em uma lista de resultados
    return Object.values(summary).map(({ sku, mlb, title, totalPrice, quantity }) => ({
        mlb,
        sku,
        title,
        quantity,
        totalPrice: totalPrice.toFixed(2).replace('.', ','),
    }));
};

module.exports = { summarizeItems }