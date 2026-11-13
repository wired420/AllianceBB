# AllianceBB API (v1)

Apps, bots, scripts and other websites can read a forum and post to it through this API. It is plain JSON over HTTPS under `/api/v1` on every AllianceBB site.

This is separate from the sharing protocol that AllianceBB sites use with each other (see [federation.md](federation.md)). Admins can turn the API off under **Admin › Settings**. When it is off, every `/api/v1` address returns 404.

## Quick start

```sh
SITE=https://forum.example.com

# 1. Get a token by signing in (or create one under Your account › Apps and tokens).
curl -X POST $SITE/api/v1/tokens -H 'content-type: application/json' \
     -d '{"login":"alice","password":"…","name":"My phone app"}'
# → {"data":{"token":"abbt_3f9c…","token_info":{…},"user":{…}}}

TOKEN=abbt_3f9c…

# 2. Read the forum list (works without a token too).
curl $SITE/api/v1/forums

# 3. Reply to topic 12.
curl -X POST $SITE/api/v1/topics/12/posts -H "authorization: Bearer $TOKEN" \
     -H 'content-type: application/json' -d '{"body":"Hello from my app"}'
```

## Basics

### Authentication

Send a token in the `Authorization` header: `Authorization: Bearer abbt_…`.

- **Without a token** you are a guest. You can read whatever guests can see, and you can't post.
- **With a token** you act as that user, with exactly their permissions. A moderator's token can moderate, and so on.
- **A bad or revoked token** gets `401`. The API never quietly treats it as a guest, so an app knows to sign in again.
- **Cookies are ignored.** A browser's logged-in session never counts, so pages on other sites can't use a visitor's session against the API. Because of this the API needs no CSRF token.

Each token has a **scope**:

| Scope | Allows |
| --- | --- |
| `read` | GET requests as the user, for example to see what only members can see, or which actions are allowed |
| `write` | Everything the user can do: post, edit, delete and moderate |

Users see and revoke their tokens under **Your account › Apps and tokens**. Tokens don't expire. An app signs out by revoking its own token with `DELETE /api/v1/tokens/current`.

### Requests

- Send bodies as JSON with `Content-Type: application/json`. Form-encoded bodies (`application/x-www-form-urlencoded`) also work for `POST`.
- `PATCH` needs a JSON body.
- IDs are numbers. Each forum, topic and post also has a `guid`, which is the same on every site that shares it.

### Responses

Successful responses wrap the result in `data`. Lists also carry `meta` with paging details:

```json
{ "data": [ … ], "meta": { "page": 1, "per_page": 30, "total": 74, "pages": 3 } }
```

Errors use the HTTP status code and this shape:

```json
{ "error": { "code": "invalid", "message": "Topic titles are 1 to 150 characters." } }
```

The `message` is written for people, so an app can show it to the user as is.

| Status | `code` | Meaning |
| --- | --- | --- |
| 400 | `bad_request` | The body isn't valid JSON, or something is malformed |
| 401 | `unauthorized` | A token is needed, or the one sent isn't valid |
| 403 | `forbidden` | The user isn't allowed to do this, or the token is read-only |
| 404 | `not_found` | No such forum, topic, post or user, or the API is off |
| 405 | `method_not_allowed` | That address doesn't take this method |
| 422 | `invalid` | The input was refused, for example an empty post or a reply to a locked topic |
| 429 | `rate_limited` | Too many failed sign-ins; wait 15 minutes |
| 500 | `server_error` | Something broke on the site |

### Times, links and permissions

- Times are ISO 8601 strings with a time zone, for example `2026-11-13T21:19:11+00:00`.
- Forums, topics and posts include a `url` to the same thing on the website.
- They also include a `can` object that says what the current user may do, such as `"can": {"reply": true, "moderate": false}`. Use it to show or hide buttons rather than guessing.

### Browsers (CORS)

The API sends `Access-Control-Allow-Origin: *`, so web apps on any domain can call it with `fetch()`. They authenticate only with a token, never cookies.

## Endpoints

| Method and path | Token | What it does |
| --- | --- | --- |
| `GET /api/v1/site` (or `/api/v1`) | none | Site name, version and how to get live updates |
| `POST /api/v1/tokens` | none | Sign in and create a token |
| `GET /api/v1/tokens` | any | List your tokens |
| `DELETE /api/v1/tokens/{id}` or `/tokens/current` | any | Revoke a token |
| `GET /api/v1/me` | any | The signed-in user |
| `GET /api/v1/users/{username}` | none | A user's public profile |
| `GET /api/v1/forums` | none | All categories and forums as a tree |
| `GET /api/v1/forums/{id}` | none | One category or forum, its sub-forums and parents |
| `GET /api/v1/forums/{id}/topics` | none | Topics in a forum, pinned first, then newest activity |
| `POST /api/v1/forums/{id}/topics` | write | Start a topic |
| `GET /api/v1/topics/{id}` | none | One topic |
| `PATCH /api/v1/topics/{id}` | write | Lock, pin or move a topic (moderators) |
| `GET /api/v1/topics/{id}/posts` | none | Posts in a topic, oldest first |
| `POST /api/v1/topics/{id}/posts` | write | Reply |
| `GET /api/v1/posts/{id}` | none | One post |
| `PATCH /api/v1/posts/{id}` | write | Edit a post |
| `DELETE /api/v1/posts/{id}` | write | Delete a post; deleting a topic's first post deletes the topic |
| `POST`/`DELETE /api/v1/posts/{id}/hide` | write | Hide or unhide a post on this site (moderators) |
| `POST`/`DELETE /api/v1/posts/{id}/block-author` | write | Block or unblock a post's author from another site (moderators) |
| `GET /api/v1/live` | none | Poll for new posts and topics |

"none" means the endpoint works without a token. With a token, you see what that user can see, and the `can` fields are filled in for them.

### Site

`GET /api/v1/site`

```json
{
  "data": {
    "name": "Alpha Forums",
    "description": "A forum that shares sections with other sites.",
    "url": "https://alpha.example.com",
    "software": "AllianceBB",
    "version": "0.1.0",
    "api_version": 1,
    "registration_open": true,
    "sharing": "private",
    "live": { "mode": "poll", "after": 14 }
  }
}
```

`live` describes how to get updates as they happen (see [Live updates](#live-updates)).

### Tokens

`POST /api/v1/tokens` signs in and returns a new token. This is the only time the token itself is shown, so store it.

| Field | Required | Notes |
| --- | --- | --- |
| `login` | yes | Username or email |
| `password` | yes | |
| `name` | no | Shown to the user in their token list, for example "Phone app". Default "App" |
| `scope` | no | `read` or `write`. Default `write` |

It returns `201` with `token`, `token_info` and `user`. A wrong password returns `401`. After 10 failed attempts for one account, or 30 from one address, within 15 minutes, requests get `429`. The same limit applies to the website's login form.

`GET /api/v1/tokens` lists your tokens without their secret values. `current: true` marks the one making the request.

```json
{ "data": [ { "id": 3, "name": "Phone app", "prefix": "abbt_3f9c1a2", "scope": "write", "current": true,
              "last_used_at": "2026-11-13T21:19:01+00:00", "created_at": "2026-11-13T21:19:01+00:00" } ] }
```

`DELETE /api/v1/tokens/{id}` revokes one of your tokens and returns `204`. Use `current` as the id to revoke the token making the request.

### Users

`GET /api/v1/me` returns the signed-in user, including the `email` and `token_scope` fields that only that user sees.

`GET /api/v1/users/{username}` returns a public profile:

```json
{ "data": { "id": 1, "username": "alice", "display_name": "Alice", "avatar_url": null,
            "role": "member", "post_count": 42, "created_at": "2026-11-13T20:48:26+00:00" } }
```

Only users of this site can be looked up. Authors from other sites appear on posts as `author.handle`, for example `bob@GamingHub`.

### Forums

`GET /api/v1/forums` returns the whole tree. Categories hold forums, and forums can hold sub-forums in `children`.

```json
{
  "data": [{
    "id": 1, "guid": "6f1c…", "type": "category", "parent_id": null,
    "title": "General", "description": "", "position": 0,
    "topic_count": 0, "post_count": 0, "last_post": null, "cloned_from": null,
    "can": { "create_topic": false },
    "url": "https://alpha.example.com/forum/1-general",
    "children": [{
      "id": 2, "type": "forum", "title": "Chat", "topic_count": 12, "post_count": 140,
      "last_post": { "post_id": 7, "topic_id": 1, "topic_title": "Hello", "username": "alice",
                     "created_at": "2026-11-13T21:01:52+00:00" },
      "cloned_from": null,
      "can": { "create_topic": true },
      "children": [], "…": "…"
    }]
  }]
}
```

`cloned_from` is set for sections copied from another site, for example `{"site": "Alpha Forums", "handle": "AlphaForums", "url": "https://alpha.example.com"}`. Posting in a cloned section works the same way as in a local one. The post shows at once with `"state": "pending"`, then turns `confirmed` once the home site accepts it, usually within seconds.

`GET /api/v1/forums/{id}` returns one node in the same shape, plus `parents`, which lists its ancestors from the top down.

`GET /api/v1/forums/{id}/topics?page=1` returns 30 topics per page, pinned topics first, then by latest activity.

`POST /api/v1/forums/{id}/topics` starts a topic.

| Field | Required | Notes |
| --- | --- | --- |
| `title` | yes | 1 to 150 characters |
| `body` | yes | Markdown, up to 60,000 characters |

It returns `201` with `data.topic` and `data.post`. You can only start topics in forums, not categories.

### Topics

`GET /api/v1/topics/{id}`

```json
{
  "data": {
    "id": 12, "guid": "01a1d6c7-…", "forum_id": 2, "title": "Posted from the API",
    "author": "alice", "post_count": 5, "locked": false, "pinned": false,
    "first_post_id": 80, "last_post_id": 91, "last_post_by": "bob@GamingHub",
    "last_post_at": "2026-11-13T21:19:11+00:00", "created_at": "2026-11-13T20:02:41+00:00",
    "can": { "reply": true, "moderate": false },
    "url": "https://alpha.example.com/topic/12-posted-from-the-api"
  }
}
```

`PATCH /api/v1/topics/{id}` is for moderators. Send any of these fields:

| Field | Notes |
| --- | --- |
| `locked` | `true` or `false` |
| `pinned` | `true` or `false` |
| `forum_id` | Move the topic to this forum |

It returns the updated topic. Topics in sections cloned from another site can only be moderated on their home site, so these get `403`.

`GET /api/v1/topics/{id}/posts` has two modes:

- `?page=N` returns 25 posts per page, oldest first. `meta` includes `page`, `per_page`, `total` and `pages`.
- `?after=<post id>&limit=50` returns posts newer than that id, oldest first, with up to 100 per request. `meta.more` is `true` when there may be more. This is the easy way for an app to catch up on a topic it has already loaded.

`POST /api/v1/topics/{id}/posts` replies with `{"body": "…"}` and returns `201` with the new post. A reply to a locked topic gets `403`, unless you're a moderator.

### Posts

`GET /api/v1/posts/{id}`

```json
{
  "data": {
    "id": 91, "guid": "01a1d6c7-5c76-…", "topic_id": 12,
    "author": { "username": "bob", "display_name": "Bob", "handle": "bob@GamingHub",
                "avatar_url": null, "role": null, "remote": true },
    "body": "A reply with **Markdown**",
    "body_html": "<p>A reply with <strong>Markdown</strong></p>\n",
    "first_post": false,
    "state": "remote",
    "error": null,
    "created_at": "2026-11-13T21:19:12+00:00",
    "edited_at": null,
    "can": { "edit": false, "delete": false, "hide": true, "block_author": true },
    "url": "https://alpha.example.com/post/91"
  }
}
```

- `body` is the Markdown source. `body_html` is safe HTML ready to display, and has already been through the site's plugins.
- `author.role` is `null` for authors from other sites.
- `state` describes where the post came from:

| `state` | Meaning |
| --- | --- |
| `local` | Written on this site, in one of its own sections |
| `pending` | Written here in a cloned section, and on its way to the home site |
| `confirmed` | The home site accepted it |
| `rejected` | The home site refused it; `error` says why |
| `remote` | Written on another site |

`PATCH /api/v1/posts/{id}` edits a post with `{"body": "…"}` and returns the updated post. Posts from other sites can't be edited here.

`DELETE /api/v1/posts/{id}` returns `204`. Deleting the first post of a topic deletes the whole topic.

### Moderation

These are for moderators of this site, and they only affect this site.

- `POST /api/v1/posts/{id}/hide` hides a post on this site and returns `204`. `DELETE` on the same path shows it again.
- `POST /api/v1/posts/{id}/block-author` hides everything the post's author has posted or will post on this site. It only works for authors from other sites. `DELETE` lifts the block.

## Live updates

`GET /api/v1/site` says how this site sends live updates.

**`"live": {"mode": "poll", "after": 14}`.** Poll this endpoint every few seconds:

```
GET /api/v1/live?channels=topic/12,node/2&after=14
```

```json
{ "data": [ { "id": 16, "channel": "topic/12", "type": "post.created", "data": { "post_id": 91 } } ],
  "meta": { "after": 16 } }
```

Send `meta.after` back as `after` on the next poll. You can watch up to 20 channels at once.

**`"live": {"mode": "mercure", "hub": "…", "prefix": "abb/"}`.** Subscribe to the Mercure hub with a standard `EventSource`. No token is needed:

```js
const url = new URL(site.live.hub, site.url);
url.searchParams.append('topic', site.live.prefix + 'topic/12');
new EventSource(url).onmessage = (e) => console.log(JSON.parse(e.data)); // {type, channel, data}
```

Channels and events:

| Channel | Events | `data` |
| --- | --- | --- |
| `topic/{id}` | `post.created`, `post.edited`, `post.deleted` | `{post_id}` |
| `node/{forum id}` | `topic.created`, `topic.bumped`, `topic.deleted` | `{topic_id}` |

Events only say what changed. Fetch the post or topic through the API to get its content as the current user sees it. This also respects hides, blocks and permissions.

## For plugin authors

- Every forum, topic, post and user passes through the `api.resource` filter before it is sent. Add fields there:

  ```php
  $app->hooks()->addFilter('api.resource', function (array $data, string $type, array $row, $ctx) {
      if ($type === 'post') {
          $data['signature'] = my_signature_for($row['author_user_id']);
      }
      return $data;
  });
  ```

  `$type` is `forum`, `topic`, `post` or `user`. `$row` is the database row the resource was built from.

- Add your own endpoints with the `routes` action, under `/api/v1/<plugin-name>/…`. They get token sign-in, JSON errors and CORS automatically:
  - `$ctx->user` is the token's user.
  - `$ctx->apiToken['scope']` is `read` or `write`. Check it before changing anything.
  - Throw `HttpException(403)` or `InvalidArgumentException('message')` to send an error.
- Permission checks go through the same `permission` filter as the website, so a plugin that restricts something on the site restricts it in the API too.
