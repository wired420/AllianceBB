-- Public API: personal access tokens for apps, and sign-in throttling.

CREATE TABLE api_tokens (
    id           bigserial PRIMARY KEY,
    user_id      bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    name         text        NOT NULL,
    token_hash   text        NOT NULL UNIQUE,      -- sha256 of the token
    prefix       text        NOT NULL,             -- first characters, shown so users can tell tokens apart
    scope        text        NOT NULL DEFAULT 'read' CHECK (scope IN ('read', 'write')),
    last_used_at timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX api_tokens_user_idx ON api_tokens (user_id);

-- Failed sign-ins (web and API), counted per key such as "ip:1.2.3.4" or "login:alice".
CREATE TABLE auth_failures (
    key        text        NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX auth_failures_key_idx ON auth_failures (key, created_at);
