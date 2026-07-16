const express = require('express');
const router = express.Router();
const path = require('path');
const fs = require('fs-extra');

const { generateExcelBuffer } = require('../utils/utils');
const { getBasePath } = require('../config/runtime');

const { isAuthenticated } = require('../middlewares/authMiddleware');

function base(req) {
    return getBasePath(req) || req.basePath || '';
}
const { campaignsSummary } = require('../controllers/campaignsSummaryController');
const { inactiveAds } = require('../controllers/inactivesAdsController');

router.get("/", (req, res) => {
    res.render('home')
});

router.post("/login", (req, res) => {
    const { name, password } = req.body;

    if( name != 'WCT' || password != '123456' ) {
        return res.render('home', { error: true });
    } else {
        req.session.authenticated = true;
        res.redirect(`${base(req)}/dashboard`);
    }
    
});

router.get('/logout', (req, res) => {
    req.session.destroy();
    res.redirect(`${base(req)}/`);
});

router.get("/dashboard", isAuthenticated, (req, res) => {
    res.render('dashboard')
});

router.get("/campanhas", isAuthenticated, async (req, res) => {
    const response = await campaignsSummary(req, res, 'candidate'); 
    // Filtra as campanhas removendo as do tipo 'SELLER_CAMPAIGN' e 'DEAL'
    const filteredResults = response.filter(campaign => 
        campaign.data.type !== 'SELLER_CAMPAIGN' && campaign.data.type !== 'DEAL' && campaign.total !== 0
    );
    
    res.render('campanhas', {
        summary: filteredResults,
        itemStatus: 'candidate',
        exportEndpoint: '/api_meli/campaigns-analytics',
    })
});

router.get("/campanhas-pendentes", isAuthenticated, async (req, res) => {
    const response = await campaignsSummary(req, res, 'pending'); 
    // Filtra as campanhas removendo as do tipo 'SELLER_CAMPAIGN' e 'DEAL'
    const filteredResults = response.filter(campaign => 
        campaign.data.type !== 'SELLER_CAMPAIGN' && campaign.data.type !== 'DEAL' && campaign.total !== 0
    );
    
    res.render('campanhas', {
        summary: filteredResults,
        itemStatus: 'pending',
        exportEndpoint: '/api_meli/campaigns-analytics',
    })
});

router.get("/campanhas-ativas", isAuthenticated, async (req, res) => {
    const response = await campaignsSummary(req, res, 'started'); 
    // Filtra as campanhas removendo as do tipo 'SELLER_CAMPAIGN' e 'DEAL'
    const filteredResults = response.filter(campaign => 
        campaign.data.type !== 'SELLER_CAMPAIGN' && campaign.data.type !== 'DEAL' && campaign.total !== 0
    );
    
    res.render('campanhas_ativa', {
        summary: filteredResults,
        itemStatus: 'started',
        exportEndpoint: '/api_meli/campaigns-analytics-ativas',
    })
});


router.get("/inactive-ads", isAuthenticated, async (req, res) => {
    try {
        const response = await inactiveAds(req, res);
                
        res.render('inactives_ads', { data: response.data, currentPage: response.currentPage, totalPages: response.totalPages });
    } catch (error) {
        console.error("Erro na rota /inactive-ads:", error);
        res.status(500).send("Erro interno do servidor");
    }
});

router.get('/report-ads-inactives', async (req, res) => {
    const header = ['ID', 'SKU', 'Status','Motivo', 'Estoque', 'Vendas', 'Logística', 'Fulfillment', 'DataRegistro'];
    const response = await inactiveAds(req, res);

    await generateExcelBuffer(response.data ,header);
})

router.get('/anuncios', async (req, res) => {
    res.render('ads')
});

router.get('/images', async (req, res) => {
    //await adsComplete();
    res.render('images')
});

router.get('/images_download_zip', (req, res) => {
    const filePath = path.join(__dirname, '..', '..', 'uploads', 'comprimidas.zip');

    res.download(filePath, 'imagens.zip', (err) => {
        if (err) {
            console.log("Erro ao fazer o download:", err);
        } else {
            console.log("Download concluído. Aguardando para limpar pasta...");

            // Aguarda 2 segundos antes de tentar limpar a pasta
            setTimeout(async () => {
                const uploadsDir = path.join(__dirname, '..', '..', 'uploads');
                try {
                    await fs.emptyDir(uploadsDir);
                    console.log('Pasta uploads limpa com sucesso!');
                } catch (error) {
                    console.error('Erro ao limpar a pasta uploads:', error);
                }
            }, 5000); // Aguarda 2 segundos
        }
    });
});

router.get('/frete', isAuthenticated, (req, res) => {
    res.render('frete');
});






module.exports = router;