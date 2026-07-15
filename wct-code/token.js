const { fetchPortalCredentials } = require('./src/config/runtime');

let accessToken = null;
let tokenExpiration = null;

async function fetchToken() {
    const creds = await fetchPortalCredentials();
    accessToken = creds.access_token;
    tokenExpiration = new Date(Date.now() + 5 * 60 * 1000);
}

async function getToken() {
    if (!accessToken || (tokenExpiration && new Date() >= tokenExpiration)) {
        await fetchToken();
    }

    if (!accessToken) {
        throw new Error('Token ML indisponivel. Configure em Configuracao API do portal.');
    }

    return accessToken;
}

async function getSellerId() {
    const creds = await fetchPortalCredentials();
    return creds.seller_id;
}

module.exports = { getToken, getSellerId };
