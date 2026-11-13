# Writing AllianceBB plugins

A plugin can add to or change almost any part of AllianceBB. This page lists every hook, what it receives and when it runs.

## A plugin's files

```
plugins/my-plugin/
├── plugin.json          required: name, title, description, version
├── plugin.php           required: returns a function that receives the App
├── templates/           optional: Twig templates, available as @my-plugin/…
└── migrations/          optional: 001_name.sql, 002_name.sql … run when the plugin is enabled
```

```json
{
    "name": "my-plugin",
    "title": "My plugin",
    "description": "What it does, shown in Admin › Plugins.",
    "version": "1.0.0",
    "requires": ">=0.1.0",
    "main": "plugin.php"
}
```

`name` is lowercase letters, numbers and dashes, and must match the folder name.

```php
<?php
use AllianceBB\App;

return function (App $app, array $manifest): void {
    $app->hooks()->addFilter('render.post.after', fn (string $html, array $post) => $html . '<p>Thanks for reading!</p>');
};
```

Admins enable plugins, or upload them as a zip, under **Admin › Plugins**. The function runs once each time the app boots, which in worker mode is once per worker rather than once per request. Enabling, disabling or uploading a plugin restarts the workers.

`plugins/signatures` is a complete working example that uses a migration, a form hook, a save hook and a render hook.

## Actions and filters

- **Actions** let you run code when something happens. Register with `$app->hooks()->addAction($name, $callback, $priority)`.
- **Filters** pass a value through your callback, and you return it, changed or not. Register with `$app->hooks()->addFilter($name, $callback, $priority)`.
- Lower priority numbers run first. The default is 10.
- Arguments are passed in the order listed below. You can accept fewer than are passed.
- `$ctx` is the current request's `AllianceBB\Core\Context`: `$ctx->user`, `$ctx->request`, `$ctx->isModerator()` and so on. It is `null` when the change came from another site or from the background worker. For requests through the [public API](api.md), `$ctx->apiToken` holds the token's `id` and `scope`; otherwise it is `null`.
- Rows such as `$post` and `$topic` are arrays with the database columns (see `migrations/`).

## Render hooks (add HTML to pages)

Every `{{ hook('name', …) }}` in a template calls the filter `render.name`. It starts with an empty string; return that string with your HTML added. The last argument is always `$ctx`, which may be `null`.

Whatever you return is output as raw HTML, so escape anything that came from users with `htmlspecialchars()`.

```php
$app->hooks()->addFilter('render.post.after', function (string $html, array $post, $ctx) {
    return $html . '<div class="my-box">Post #' . (int) $post['id'] . '</div>';
});
```

| Filter | Where it appears | Arguments after `$html` |
| --- | --- | --- |
| `render.head` | Inside `<head>`, after the theme stylesheet; use it to add CSS or meta tags | `$ctx` |
| `render.nav.before` | Header menu, before the account links | `$ctx` |
| `render.nav.after` | Header menu, after the account links | `$ctx` |
| `render.header.after` | Under the site header, above every page; good for announcement bars | `$ctx` |
| `render.footer` | Top of the footer | `$ctx` |
| `render.body.end` | Just before `</body>`; use it to add scripts | `$ctx` |
| `render.index.before` | Top of the forum index | `$ctx` |
| `render.index.after` | Bottom of the forum index | `$ctx` |
| `render.forum_row.title_after` | After each forum's title in forum lists | `$forum`, `$ctx` |
| `render.forum.head` | Forum page, under the title and description | `$node`, `$ctx` |
| `render.topic_row.title_after` | After each topic's title in topic lists | `$topic`, `$ctx` |
| `render.topic.head` | Topic page, under the title | `$topic`, `$ctx` |
| `render.post.author` | Each post's author column, under the name | `$post`, `$ctx` |
| `render.post.meta` | Each post's date line | `$post`, `$ctx` |
| `render.post.before` | Above each post's text | `$post`, `$ctx` |
| `render.post.after` | Below each post's text | `$post`, `$ctx` |
| `render.post.actions` | Each post's action links (Edit, Delete, Hide…) | `$post`, `$ctx` |
| `render.reply_form.before` | Reply form, above the text box | `$topic`, `$ctx` |
| `render.reply_form.after` | Reply form, below the text box | `$topic`, `$ctx` |
| `render.topic_form.before` | New topic form, above the message box | `$node`, `$ctx` |
| `render.topic_form.after` | New topic form, below the message box | `$node`, `$ctx` |
| `render.login_form` | Login form, above the button | `$ctx` |
| `render.register_form` | Sign-up form, above the button | `$ctx` |
| `render.account_form` | Account page form, above the Save button | `$user`, `$ctx` |
| `render.admin.dashboard` | Admin dashboard, under the stats | `$ctx` |
| `render.admin.settings.form` | Admin › Settings form, above the Save button | `$settings`, `$ctx` |
| `render.admin.structure.form` | Admin › Forums, in the Add form | `$ctx` |

A theme can add its own `{{ hook('…') }}` calls anywhere, and plugins can use them the same way.

`$post` in render hooks also has these extra fields: `username`, `display_name`, `avatar_url`, `author_role`, `author_handle` (`user@Site` for remote authors), `is_remote`, `is_hidden`.

## Actions

| Action | When it runs | Arguments |
| --- | --- | --- |
| `app.booted` | After plugins load and routes are registered, once per worker | `$app` |
| `routes` | While routes are registered; add your own pages here | `$router`, `$app` |
| `request.start` | At the start of every web request, after the user is known | `$ctx` |
| `view.twig` | When the Twig environment is created; add Twig functions, filters or globals | `$twig` |
| `console` | `bin/console <command>` with a command the core doesn't know | `$command`, `$args`, `$app`, `$markHandled` (call it to say you handled it) |
| `user.registered` | A new account was created | `$user` |
| `user.banned` | An admin banned a user | `$userId` |
| `user.unbanned` | An admin lifted a ban | `$userId` |
| `account.saved` | A user saved their account page; read your own form fields from `$ctx->request` | `$ctx` |
| `admin.settings.saved` | An admin saved Admin › Settings | `$ctx` |
| `node.created` | A category, forum or sub-forum was created | `$node` |
| `node.updated` | A category, forum or sub-forum was renamed or moved | `$node` |
| `node.deleted` | A category, forum or sub-forum was deleted, with everything in it | `$node` (as it was) |
| `topic.created` | A topic was started, here or on another site | `$topic`, `$firstPost`, `$ctx` |
| `post.created` | A reply was posted, here or on another site | `$post`, `$topic`, `$ctx` |
| `post.edited` | A post's text changed on this site | `$post`, `$ctx` |
| `post.deleted` | A reply was deleted | `$post`, `$ctx` |
| `topic.deleted` | A topic was deleted, by deleting its first post | `$topic`, `$ctx` |
| `topic.locked` | A topic was locked or unlocked | `$topicId`, `$locked`, `$ctx` |
| `topic.pinned` | A topic was pinned or unpinned | `$topicId`, `$pinned`, `$ctx` |
| `topic.moved` | A topic moved to another forum | `$topicId`, `$fromNodeId`, `$toNodeId`, `$ctx` |
| `federation.event_emitted` | This site recorded a change in one of its shared sections for subscribers | `$rootNode`, `$type`, `$payload`, `$seq` |
| `federation.event_received` | This site is about to apply a change from a section it cloned | `$connection`, `$rootGuid`, `$type`, `$payload` |

## Filters

| Filter | What you change | Other arguments |
| --- | --- | --- |
| `post.body` | A post's Markdown before it is saved, for new posts and edits; throw `InvalidArgumentException` to refuse it with a message | `$ctx` |
| `topic.title` | A new topic's title before it is saved | `$ctx` |
| `markup.before` | Markdown just before it is turned into HTML | none |
| `markup.after` | The HTML made from a post's Markdown | `$markdown` |
| `user.register.errors` | The list of sign-up error messages; add one to refuse the sign-up | `$username`, `$email` |
| `permission` | Whether something is allowed (see below) | `$ability`, `$subject`, `$ctx` |
| `admin.menu` | The admin menu items: `['id' => …, 'title' => …, 'url' => …]` | `$ctx` |
| `view.template` | The name of the template about to be rendered; return another to swap it | `$data` |
| `view.data` | The data passed to a template | `$template` |
| `response` | The finished `Response` before it is sent; add headers, change the body | `$ctx` (may be `null`) |
| `federation.event_payload` | A change about to be sent to sites that clone your section | `$type`, `$rootNode` |
| `api.resource` | A forum, topic, post or user about to be returned by the [public API](api.md); add fields | `$type`, `$row`, `$ctx` |

### Permissions

Every permission check goes through the `permission` filter, so a plugin can allow or forbid anything. The `$subject` depends on the ability:

| Ability | Subject | Allowed by default |
| --- | --- | --- |
| `admin.access` | none | Admins |
| `topic.create` | the forum | Logged-in users, in forums (not categories) |
| `post.reply` | the topic | Logged-in users; moderators even when the topic is locked |
| `post.edit` | the post | The author and moderators, for posts written on this site |
| `post.delete` | the post | The author and moderators, for posts written on this site |
| `topic.moderate` | the topic | Moderators, for topics in this site's own forums |
| `post.hide` | the post | Moderators |
| `post.block` | the post | Moderators, for posts by users of other sites |

```php
// Only members with 10 or more posts may start topics.
$app->hooks()->addFilter('permission', function (bool $allowed, string $ability, ?array $subject, $ctx) {
    if ($ability === 'topic.create' && $ctx?->user && $ctx->user['post_count'] < 10) {
        return false;
    }
    return $allowed;
});
```

Plugins can check their own abilities with `$app->get(AllianceBB\Service\Permissions::class)->can($ctx, 'my-plugin.do-thing', $subject)` and grant them through the same filter.

## Beyond hooks

| To | Use |
| --- | --- |
| Add a page | `routes` action: `$router->get('/path', fn (Context $ctx) => Response::html('…'), 'my-plugin.page')` (also `post()` and `add(['GET','POST'], …)`) |
| Render your own template | `$app->view()->render('@my-plugin/page.twig', ['ctx' => $ctx, …])`, extending `layout.twig` to keep the site's look |
| Override any theme template | `$app->view()->addOverridePath(__DIR__ . '/overrides')` and put a file with the same name there, e.g. `partials/post.twig` |
| Replace a core service | `$app->container()->override(AllianceBB\Service\Markup::class, fn ($c, $previous) => new MyMarkup(…))` |
| Use a core service | `$app->get(AllianceBB\Service\Posts::class)`, `Nodes`, `Users`, `Settings`, `Permissions`, `Markup`, `AllianceBB\Federation\Federation`, `AllianceBB\Federation\Jobs` |
| Query the database | `$app->db()->all($sql, $params)`, `one()`, `value()`, `run()`, `transaction(fn ($db) => …)` |
| Store settings | `$app->settings()->get('my-plugin.key')` and `set('my-plugin.key', $value)`; prefix keys with your plugin name |
| Run background work | `$app->get(AllianceBB\Federation\Jobs::class)->handle('my-plugin.job', fn (array $payload) => …)` once at boot, then `->push('my-plugin.job', [...])` |
| Push a live update to open pages | `$app->get(AllianceBB\Live\Broadcaster::class)->publish('topic/12', 'post.edited', ['post_id' => 34])` |
| Add a console command | `console` action |
| Add an API endpoint | `routes` action with a path under `/api/v1/<plugin-name>/`; token sign-in, JSON errors and CORS come with it (see [api.md](api.md)) |

Services that can be replaced: `Database`, `Settings`, `Users`, `Auth`, `Permissions`, `Markup`, `Nodes`, `Posts`, `Broadcaster`, `Themes`, `View`, `Jobs`, `HttpClient`, `Federation` and `PluginManager`. Override them at the top level of your plugin's function, before anything uses them.

## Safety

- Plugins run as full PHP with access to everything, so only install plugins you trust.
- Escape user content in HTML you return: `htmlspecialchars($text)`.
- Forms you add are protected automatically: every POST must carry the `_csrf` field. Add `{{ csrf_field() }}` to forms in your templates.
- Prefix your database tables (`plugin_<name>_…`) and settings keys (`<name>.…`) to avoid clashes.
