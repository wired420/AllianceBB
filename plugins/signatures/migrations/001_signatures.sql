CREATE TABLE IF NOT EXISTS plugin_signatures (
    user_id   bigint PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
    signature text NOT NULL
);
