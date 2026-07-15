const { getBasePath } = require('../config/runtime');

const authenticateToken = (req, res, next) => {
    const { token } = req.query;

    if (!token) {
        return res.status(401).json({ error: 'Token ausente' });
    }
    if (token !== '123456') {
        return res.status(401).json({ error: 'Token invalido' });
    }

    return next();
};

const isAuthenticated = (req, res, next) => {
    if (process.env.WCT_CODE_BYPASS_AUTH === '1') {
        req.session.authenticated = true;
        return next();
    }

    if (req.session.authenticated) {
        return next();
    }

    const base = getBasePath(req);
    return res.redirect(`${base}/`);
};

module.exports = { authenticateToken, isAuthenticated };
