-- AllianceBB core schema: settings, accounts, forum structure, topics, posts, live events, plugins.

CREATE TABLE settings (
    key   text PRIMARY KEY,
    value jsonb NOT NULL
);

CREATE TABLE users (
    id            bigserial PRIMARY KEY,
    username      text        NOT NULL,
    email         text        NOT NULL,
    password_hash text        NOT NULL,
    display_name  text,
    avatar_url    text,
    role          text        NOT NULL DEFAULT 'member' CHECK (role IN ('admin', 'moderator', 'member')),
    post_count    integer     NOT NULL DEFAULT 0,
    banned_at     timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX users_username_key ON users (lower(username));
CREATE UNIQUE INDEX users_email_key ON users (lower(email));

CREATE TABLE sessions (
    id           text PRIMARY KEY,           -- sha256 of the cookie token
    user_id      bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    created_at   timestamptz NOT NULL DEFAULT now(),
    last_seen_at timestamptz NOT NULL DEFAULT now(),
    expires_at   timestamptz NOT NULL
);
CREATE INDEX sessions_user_idx ON sessions (user_id);
CREATE INDEX sessions_expires_idx ON sessions (expires_at);

-- Categories, forums and sub-forums live in one tree.
-- A category holds forums; a forum holds topics and may hold sub-forums.
CREATE TABLE nodes (
    id           bigserial PRIMARY KEY,
    parent_id    bigint REFERENCES nodes (id) ON DELETE CASCADE,
    type         text        NOT NULL CHECK (type IN ('category', 'forum')),
    title        text        NOT NULL,
    slug         text        NOT NULL,
    description  text        NOT NULL DEFAULT '',
    position     integer     NOT NULL DEFAULT 0,
    topic_count  integer     NOT NULL DEFAULT 0,
    post_count   integer     NOT NULL DEFAULT 0,
    last_post_id bigint,
    settings     jsonb       NOT NULL DEFAULT '{}'::jsonb,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX nodes_parent_idx ON nodes (parent_id, position);

CREATE TABLE topics (
    id             bigserial PRIMARY KEY,
    guid           uuid        NOT NULL UNIQUE,
    node_id        bigint      NOT NULL REFERENCES nodes (id) ON DELETE CASCADE,
    title          text        NOT NULL,
    slug           text        NOT NULL,
    author_user_id bigint REFERENCES users (id) ON DELETE SET NULL,
    post_count     integer     NOT NULL DEFAULT 0,
    first_post_id  bigint,
    last_post_id   bigint,
    last_post_at   timestamptz NOT NULL DEFAULT now(),
    locked         boolean     NOT NULL DEFAULT false,
    pinned         boolean     NOT NULL DEFAULT false,
    deleted_at     timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX topics_node_idx ON topics (node_id, pinned DESC, last_post_at DESC) WHERE deleted_at IS NULL;

CREATE TABLE posts (
    id             bigserial PRIMARY KEY,
    guid           uuid        NOT NULL UNIQUE,
    topic_id       bigint      NOT NULL REFERENCES topics (id) ON DELETE CASCADE,
    author_user_id bigint REFERENCES users (id) ON DELETE SET NULL,
    body           text        NOT NULL,
    body_html      text        NOT NULL,
    edited_at      timestamptz,
    deleted_at     timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX posts_topic_idx ON posts (topic_id, id);

ALTER TABLE nodes  ADD CONSTRAINT nodes_last_post_fk  FOREIGN KEY (last_post_id)  REFERENCES posts (id) ON DELETE SET NULL;
ALTER TABLE topics ADD CONSTRAINT topics_first_post_fk FOREIGN KEY (first_post_id) REFERENCES posts (id) ON DELETE SET NULL;
ALTER TABLE topics ADD CONSTRAINT topics_last_post_fk  FOREIGN KEY (last_post_id)  REFERENCES posts (id) ON DELETE SET NULL;

-- Live update log used when no Mercure hub is configured (browsers poll it).
CREATE TABLE live_events (
    id         bigserial PRIMARY KEY,
    channel    text        NOT NULL,
    type       text        NOT NULL,
    data       jsonb       NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX live_events_channel_idx ON live_events (channel, id);

CREATE TABLE plugins (
    name         text PRIMARY KEY,
    enabled      boolean     NOT NULL DEFAULT false,
    version      text        NOT NULL DEFAULT '',
    installed_at timestamptz NOT NULL DEFAULT now()
);
