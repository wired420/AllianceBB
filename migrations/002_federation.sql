-- Sharing sections between AllianceBB sites.

-- Every site this one has heard of, as an author's home or a connection.
-- "handle" is the name shown after the @ in user@Handle; the first site with
-- a name keeps it and later ones with the same name get a number suffix.
CREATE TABLE remote_sites (
    id         bigserial PRIMARY KEY,
    base_url   text        NOT NULL UNIQUE,
    name       text        NOT NULL,
    handle     text        NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX remote_sites_handle_key ON remote_sites (lower(handle));

-- A key shared between this site and another.
--   role = 'subscriber': we issued the key; that site clones our sections.
--   role = 'owner':      we received the key; we clone that site's sections.
CREATE TABLE connections (
    id             bigserial PRIMARY KEY,
    remote_site_id bigint      NOT NULL REFERENCES remote_sites (id) ON DELETE CASCADE,
    role           text        NOT NULL CHECK (role IN ('subscriber', 'owner')),
    public_id      text        NOT NULL UNIQUE,
    secret         text        NOT NULL,
    webhook_url    text,
    status         text        NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'revoked', 'revoked_by_remote')),
    created_via    text        NOT NULL DEFAULT 'admin',
    last_seen_at   timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now()
);

-- Authors who live on other sites.
CREATE TABLE remote_users (
    id             bigserial PRIMARY KEY,
    remote_site_id bigint      NOT NULL REFERENCES remote_sites (id) ON DELETE CASCADE,
    username       text        NOT NULL,
    display_name   text,
    avatar_url     text,
    blocked_at     timestamptz,
    blocked_by     bigint REFERENCES users (id) ON DELETE SET NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    UNIQUE (remote_site_id, username)
);

-- Nodes, topics and posts get global IDs. A cloned node keeps the owner's ID.
ALTER TABLE nodes
    ADD COLUMN guid                 uuid    NOT NULL DEFAULT gen_random_uuid(),
    ADD COLUMN shareable            boolean NOT NULL DEFAULT false,
    ADD COLUMN origin_connection_id bigint  REFERENCES connections (id) ON DELETE CASCADE,
    ADD COLUMN origin_root_guid     uuid,
    ADD COLUMN sync_cursor          bigint  NOT NULL DEFAULT 0;
CREATE UNIQUE INDEX nodes_guid_key ON nodes (guid);

ALTER TABLE topics
    ADD COLUMN author_remote_id bigint REFERENCES remote_users (id) ON DELETE SET NULL;

ALTER TABLE posts
    ADD COLUMN author_remote_id bigint REFERENCES remote_users (id) ON DELETE SET NULL,
    -- local: written here in a local section; pending/confirmed/rejected: written here
    -- in a cloned section and sent to the owner; remote: received from another site.
    ADD COLUMN federation_state text NOT NULL DEFAULT 'local'
        CHECK (federation_state IN ('local', 'pending', 'confirmed', 'rejected', 'remote')),
    ADD COLUMN federation_error text;

-- On the owner: which sites receive which shared section.
CREATE TABLE subscriptions (
    id            bigserial PRIMARY KEY,
    connection_id bigint      NOT NULL REFERENCES connections (id) ON DELETE CASCADE,
    node_id       bigint      NOT NULL REFERENCES nodes (id) ON DELETE CASCADE,
    created_at    timestamptz NOT NULL DEFAULT now(),
    UNIQUE (connection_id, node_id)
);

-- On the owner: the ordered change log of each shared section.
CREATE TABLE fed_events (
    seq          bigserial PRIMARY KEY,
    root_node_id bigint      NOT NULL REFERENCES nodes (id) ON DELETE CASCADE,
    type         text        NOT NULL,
    payload      jsonb       NOT NULL,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX fed_events_root_idx ON fed_events (root_node_id, seq);

-- Posts hidden on this site only.
CREATE TABLE local_hides (
    post_id    bigint PRIMARY KEY REFERENCES posts (id) ON DELETE CASCADE,
    hidden_by  bigint REFERENCES users (id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

-- Signed request nonces seen recently, to reject replays.
CREATE TABLE fed_nonces (
    connection_id bigint      NOT NULL REFERENCES connections (id) ON DELETE CASCADE,
    nonce         text        NOT NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (connection_id, nonce)
);

-- Background work: webhooks, submissions to owners, catch-up syncs.
CREATE TABLE jobs (
    id         bigserial PRIMARY KEY,
    type       text        NOT NULL,
    dedupe_key text,
    payload    jsonb       NOT NULL,
    state      text        NOT NULL DEFAULT 'pending' CHECK (state IN ('pending', 'failed')),
    attempts   integer     NOT NULL DEFAULT 0,
    last_error text,
    run_at     timestamptz NOT NULL DEFAULT now(),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX jobs_due_idx ON jobs (run_at) WHERE state = 'pending';
CREATE UNIQUE INDEX jobs_dedupe_key ON jobs (type, dedupe_key) WHERE state = 'pending' AND dedupe_key IS NOT NULL;
