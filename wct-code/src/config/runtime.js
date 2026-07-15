const axios = require('axios');

let cached = null;
let cachedAt = 0;
const CACHE_MS = 60 * 1000;

function portalApiUrl() {
    if (process.env.WCT_CODE_PORTAL_API_URL) {
        return process.env.WCT_CODE_PORTAL_API_URL.replace(/\/$/, '');
    }

    const port = process.env.PORTAL_HTTP_PORT || process.env.PORT || '80';
    const base = process.env.PORTAL_BASE_URL || '/';
    const basePath = base === '/' ? '' : base.replace(/\/$/, '');

    return `http://127.0.0.1:${port}${basePath}/index.php`;
}

async function fetchPortalCredentials() {
    const now = Date.now();
    if (cached && now - cachedAt < CACHE_MS) {
        return cached;
    }

    const tokenFromEnv = process.env.MELI_ACCESS_TOKEN || '';
    const sellerFromEnv = process.env.MELI_SELLER_ID || '';

    if (tokenFromEnv !== '') {
        cached = {
            access_token: tokenFromEnv,
            seller_id: sellerFromEnv || '141958250',
        };
        cachedAt = now;
        return cached;
    }

    const secret = process.env.WCT_CODE_INTERNAL_SECRET || 'wct-internal';
    const url = `${portalApiUrl()}?wct_code_internal=token`;

    const { data } = await axios.get(url, {
        headers: { 'X-WCT-Internal': secret },
        timeout: 15000,
    });

    if (!data || !data.access_token) {
        throw new Error('Portal WCT nao retornou token ML.');
    }

    cached = {
        access_token: String(data.access_token),
        seller_id: String(data.seller_id || sellerFromEnv || '141958250'),
    };
    cachedAt = now;
    return cached;
}

function getBasePath(req) {
    const fromHeader = req.get('X-Forwarded-Prefix');
    if (fromHeader) {
        return String(fromHeader).replace(/\/$/, '');
    }

    const fromEnv = process.env.WCT_CODE_BASE_PATH || '';
    return fromEnv.replace(/\/$/, '');
}

module.exports = {
    fetchPortalCredentials,
    getBasePath,
    portalApiUrl,
};
