const express = require('express');
const bodyParser = require('body-parser');
const session = require('express-session');
const cors = require('cors');
const path = require('path');
const axios = require('axios');

require('dotenv').config();

const { getToken, getSellerId } = require('./token');
const { getBasePath } = require('./src/config/runtime');

const meliRoutes = require('./src/routes/api/meliRoutes');
const uploadRoutes = require('./src/routes/api/uploadRoutes');
const uploadFilesRoutes = require('./src/routes/api/uploadFilesRoutes');
const viewsRoutes = require('./src/routes/views');
const { gerarRelatorioFrete } = require('./src/services/freteService');

const app = express();
const BASE_PATH = (process.env.WCT_CODE_BASE_PATH || '/wct-code-app').replace(/\/$/, '');

app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'src', 'views'));
app.set('trust proxy', true);

app.use((req, res, next) => {
    const basePath = getBasePath(req) || BASE_PATH;
    res.locals.basePath = basePath;
    req.basePath = basePath;
    next();
});

app.use(`${BASE_PATH}`, express.static(path.join(__dirname, 'public')));
app.use(`${BASE_PATH}`, express.static(path.join(__dirname, 'src', 'public')));

const sessionPath = `${BASE_PATH}/` || '/';
app.use(session({
    secret: process.env.WCT_CODE_SESSION_SECRET || 'wct-code-portal-secret',
    resave: false,
    saveUninitialized: true,
    cookie: { path: sessionPath },
}));

app.use(cors());
app.use(bodyParser.urlencoded({ extended: true }));
app.use(bodyParser.json());

app.get('/healthz', (_req, res) => {
    res.json({ ok: true, service: 'wct-code' });
});

app.use(async (req, res, next) => {
    try {
        const tokenML = await getToken();
        const sellerId = await getSellerId();
        req.accessToken = tokenML;
        global.access_token = tokenML;
        global.seller_id = sellerId;
        process.env.MELI_SELLER_ID = sellerId;
        next();
    } catch (error) {
        console.error('Erro ao obter o token:', error.message);
        if (req.path.endsWith('.js') || req.path.startsWith('/api_meli') || req.accepts('json')) {
            return res.status(500).json({ error: 'Erro interno ao obter o token ML: ' + error.message });
        }

        return res.status(500).send(
            'Token ML indisponivel. Configure em Configuracao API do Portal WCT e reinicie o WCT Code.'
        );
    }
});

const router = express.Router();
router.use('/', viewsRoutes);
router.use('/api_meli', meliRoutes);
router.use('/upload', uploadRoutes);
router.use('/upload/files', uploadFilesRoutes);

router.get('/frete/download', async (req, res) => {
    const { from, to } = req.query;
    console.log(`Solicitando relatorio de ${from} ate ${to}`);

    try {
        const filePath = await gerarRelatorioFrete(from, to);
        res.json({ success: true, url: filePath });
    } catch (err) {
        console.error('Erro ao gerar relatorio:', err.message);
        res.status(500).send('Erro ao gerar relatorio: ' + err.message);
    }
});

router.get('/callback', async (req, res) => {
    const { code } = req.query;

    if (!code) {
        return res.status(400).send('Codigo nao fornecido');
    }

    try {
        const response = await axios.post('https://api.mercadolibre.com/oauth/token', {
            client_id: process.env.MELI_APP_ID || '',
            client_secret: process.env.MELI_CLIENT_SECRET || '',
            grant_type: 'authorization_code',
            code,
            redirect_uri: process.env.MELI_REDIRECT_URI || 'https://wctcode.com/callback',
        });

        res.json({
            message: 'Conectado com sucesso.',
            seller_id: response.data.user_id,
            access_token: response.data.access_token,
            refresh_token: response.data.refresh_token,
        });
    } catch (error) {
        console.error('Erro no OAuth:', error.response?.data || error.message);
        res.status(500).json(error.response?.data || 'Erro ao obter token do vendedor');
    }
});

app.use(BASE_PATH, router);

const PORT = process.env.WCT_CODE_PORT || 3001;
app.listen(PORT, '127.0.0.1', () => {
    console.log(`WCT Code rodando em http://127.0.0.1:${PORT}${BASE_PATH}`);
});
