CREATE TABLE IF NOT EXISTS api_settings (
    id BIGSERIAL PRIMARY KEY,
    app_id VARCHAR(80) NOT NULL,
    client_secret VARCHAR(200) NOT NULL,
    redirect_uri VARCHAR(255) DEFAULT NULL,
    seller_id VARCHAR(80) NOT NULL,
    oauth_code VARCHAR(255) DEFAULT NULL,
    lexos_code TEXT DEFAULT NULL,
    lexos_token TEXT DEFAULT NULL,
    lexos_refresh_token TEXT DEFAULT NULL,
    lexos_integration_key TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS oauth_tokens (
    id BIGSERIAL PRIMARY KEY,
    access_token TEXT NOT NULL,
    refresh_token TEXT NOT NULL,
    token_type VARCHAR(30) DEFAULT 'Bearer',
    scope TEXT DEFAULT NULL,
    expires_in INT NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS message_templates (
    id BIGSERIAL PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    body TEXT NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS message_logs (
    id BIGSERIAL PRIMARY KEY,
    order_id VARCHAR(80) NOT NULL,
    receiver_id VARCHAR(80) NOT NULL,
    message_body TEXT NOT NULL,
    status VARCHAR(20) NOT NULL,
    api_response TEXT DEFAULT NULL,
    sent_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL,
    CONSTRAINT uq_message_order UNIQUE (order_id)
);

CREATE TABLE IF NOT EXISTS manual_message_logs (
    id BIGSERIAL PRIMARY KEY,
    order_id VARCHAR(80) NOT NULL,
    sender_id VARCHAR(80) NOT NULL,
    message_body TEXT NOT NULL,
    status VARCHAR(20) NOT NULL,
    api_response TEXT DEFAULT NULL,
    sent_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS mercadopago_settings (
    id BIGSERIAL PRIMARY KEY,
    access_token TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS request_logs (
    id BIGSERIAL PRIMARY KEY,
    method VARCHAR(10) NOT NULL,
    path VARCHAR(255) NOT NULL,
    http_status INT DEFAULT NULL,
    request_payload TEXT DEFAULT NULL,
    response_body TEXT DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL
);
