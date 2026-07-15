const express = require('express');
const { authenticateToken } = require('../../middlewares/authMiddleware');

const { campaignsSummary } = require('../../controllers/campaignsSummaryController');
const { processCampaignAnalytics } = require('../../controllers/campaignAnalyticsController');
const { processCampaignAnalyticsAtivas } = require('../../controllers/campaignAnalyticsAtivasController');
const { orderSummary } = require('../../controllers/ordersControllers');
const { itensSummary } = require('../../controllers/itensController');
const { adsComplete } = require('../../controllers/adsController');

const router = express.Router();

router.get("/campaigns-summary", authenticateToken, campaignsSummary);
router.post("/campaigns-analytics", authenticateToken, processCampaignAnalytics);
router.post("/campaigns-analytics-ativas", authenticateToken, processCampaignAnalyticsAtivas);
router.get("/orders-by-period", authenticateToken, orderSummary);
router.get("/itens", authenticateToken, itensSummary);
router.get("/ads-all", authenticateToken, adsComplete);

module.exports = router;