# AllianceBB

Self-hosted forum software where any category, forum or sub-forum can be shared live with other AllianceBB sites.

> **Status:** early prototype (milestone 1). The forum and site-to-site sharing work; a Docker test kit is next.

## Features

- Categories, forums and sub-forums, topics and replies with Markdown
- Accounts, moderators and admins, locking, pinning, moving and deleting topics
- Live updates: new posts and topics appear on open pages without reloading
- Admin panel for forums, settings, colors, themes, plugins and users
- Plugins that can hook into, or replace, almost any part of the forum
- Themes made of Twig templates, with colors editable in the admin panel
- Share any category, forum or sub-forum with other AllianceBB sites, with posts flowing both ways in real time
- Authors from other sites show as `user@SiteName`; moderators can hide remote posts or block remote users on their own site
- Sharing modes per site: public (any site can connect), private (invited sites only) or off

## Stack

PHP 8.3+, PostgreSQL 14+, Twig and league/commonmark. In production it runs on [FrankenPHP](https://frankenphp.dev) in worker mode, whose built-in Mercure hub delivers live updates. Without Mercure, pages poll for updates every few seconds.

## Try it locally

```sh
composer install
createdb alliancebb
export DATABASE_URL=postgres://user:password@127.0.0.1:5432/alliancebb
php -S localhost:8080 public/router.php
```

Open http://localhost:8080 and the installer creates the tables and your admin account. After an update, run `bin/console migrate`.

Sharing needs the background worker running alongside the web server:

```sh
php bin/console worker
```

To try two sites on one machine, run two copies on different ports with different databases, and set `ALLIANCE_ALLOW_PRIVATE=1` so they may call each other on local addresses.

## How sharing works

The site that owns a section is its hub. Every post in that section, written on any site, goes through the owner, which records it in the section's event log and sends a signed webhook to every site that cloned it. Those sites then fetch the new events with their key, and also check in every two minutes in case a webhook was missed. See [docs/federation.md](docs/federation.md) for the protocol.

## Plugins

A plugin is a folder in `plugins/` with a `plugin.json` and a PHP file that returns a function. `plugins/signatures` is a working example. Admins enable plugins, or upload them as a zip, under **Admin › Plugins**.

```php
return function (AllianceBB\App $app): void {
    // Put HTML after every post.
    $app->hooks()->addFilter('render.post.after', fn (string $html, array $post) => $html . '<p>Thanks for reading!</p>');

    // Add a page.
    $app->hooks()->addAction('routes', function (AllianceBB\Http\Router $router) use ($app) {
        $router->get('/hello', fn ($ctx) => AllianceBB\Http\Response::html('Hello'));
    });

    // Replace a core service outright.
    $app->container()->override(AllianceBB\Service\Markup::class, fn ($c, $previous) => new MyMarkup());
};
```

What a plugin can do:

| Kind | Examples |
| --- | --- |
| Render hooks: filters named `render.<spot>` that add HTML | `head`, `header.after`, `nav.before`, `footer`, `body.end`, `post.before`, `post.after`, `post.author`, `post.meta`, `post.actions`, `topic.head`, `forum.head`, `index.before`, `reply_form.after`, `account_form`, `admin.dashboard` |
| Actions | `app.booted`, `routes`, `request.start`, `user.registered`, `topic.created`, `post.created`, `post.edited`, `post.deleted`, `topic.locked`, `topic.moved`, `node.created`, `account.saved`, `admin.settings.saved`, `console` |
| Filters | `post.body`, `topic.title`, `markup.before`, `markup.after`, `permission`, `user.register.errors`, `admin.menu`, `view.template`, `view.data`, `response` |
| Templates | A `templates/` folder becomes the `@plugin-name` namespace; `$app->view()->addOverridePath()` overrides any theme template |
| Database | Numbered `.sql` files in `migrations/` run when the plugin is enabled |
| Services | `$app->container()->override()` replaces any core service |

Plugins run with full access to the forum, so only install ones you trust.

## Themes

A theme is a folder in `themes/` with a `theme.json`, a `templates/` folder and an `assets/` folder. Set `"parent": "default"` to inherit everything and override only the templates you change. Colors listed in `theme.json` appear as color pickers under **Admin › Appearance** and reach CSS as `--abb-<name>` variables.

## License

AllianceBB is free software under the GNU Affero General Public License v3.0 or later, with additional terms in `NOTICE` that require keeping the AllianceBB credit. Run it for any forum, paid or free. See `TRADEMARKS.md` for use of the name.
