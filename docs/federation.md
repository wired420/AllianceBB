# AllianceBB sharing protocol (v1)

Sites talk JSON over HTTPS under `/api/alliance/v1`. Only AllianceBB installs speak it.

## Roles and keys

- The **owner** of a section is the site where it was created. It is the hub for that section.
- A **subscriber** clones the section. It holds a key the owner issued: a public ID (`abb_…`) and a secret.
- The owner's admin issues keys under **Admin › Sharing**, or, in public mode, a site creates its own with `POST /register`.
- The owner's admin can copy a **connection code** (`abb1.…`) holding the owner's address, the section and the key. The subscriber's admin pastes it to clone.

## Signing

Every call except `GET /info` and `POST /register` carries:

| Header | Value |
| --- | --- |
| `X-Alliance-Key` | the connection's public ID |
| `X-Alliance-Timestamp` | Unix time; rejected if more than 300 s off |
| `X-Alliance-Nonce` | 32 hex characters; rejected if seen before |
| `X-Alliance-Signature` | base64url HMAC-SHA256 with the secret over `METHOD\npath?query\ntimestamp\nnonce\nsha256hex(body)` |

The secret never travels after the connection is made.

## Endpoints

On the owner, called by subscribers:

| Call | Purpose |
| --- | --- |
| `GET /info` | Name, address, mode and, in public mode, shareable sections |
| `POST /register` | Public mode only: `{name, base_url, webhook_url}` returns `{key, secret}` |
| `POST /subscribe` | `{node_guid, webhook_url}` returns the section's structure, its 50 most recent topics and the event cursor |
| `GET /nodes/{guid}/events?after=N` | Events after sequence N, oldest first |
| `POST /nodes/{guid}/topics` | A new topic written on the subscriber |
| `POST /nodes/{guid}/posts` | A new reply written on the subscriber |
| `POST /nodes/{guid}/posts/{post}/edit` or `/delete` | Only accepted from the site the author belongs to |
| `POST /nodes/{guid}/unsubscribe` | Stop receiving the section |

On the subscriber, called by the owner:

| Call | Purpose |
| --- | --- |
| `POST /webhook` | `{root_guid, latest_seq}`; the subscriber then fetches new events |

## Events

`topic.created`, `topic.snapshot`, `topic.updated`, `topic.deleted`, `post.created`, `post.edited`, `post.deleted` and `structure`. Topics, posts and forums keep the same global ID (UUIDv7, or UUIDv4 for forums) on every site, so receiving something twice does nothing.

## Rules

- A post's author belongs to the site that sent it to the owner; the owner ignores any other claim.
- Only the author's site may edit or delete a post. Other sites can only hide it or block its author, locally.
- The owner controls the section itself: its forums, locking, pinning and moving topics, and which sites are connected.
- A reply written on a subscriber shows at once as "sending". It turns normal when the owner accepts it, or shows the owner's reason if refused.
- Author names show as `user@Handle`. The handle is the site name without spaces; when two sites share a name, the first one seen keeps it and later ones get `2`, `3` and so on.
