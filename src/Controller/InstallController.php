<?php

declare(strict_types=1);

namespace AllianceBB\Controller;

use AllianceBB\Core\Context;
use AllianceBB\Core\Migrator;
use AllianceBB\Http\Response;
use AllianceBB\Service\Auth;
use AllianceBB\Service\Nodes;
use AllianceBB\Service\Users;
use InvalidArgumentException;
use Throwable;

/** First-run setup: creates the tables, the site settings and the admin account. */
final class InstallController extends Controller
{
    public function install(Context $ctx): Response
    {
        if ($this->app->settings()->isInstalled()) {
            return Response::redirect('/');
        }
        $error = null;
        $dbError = null;
        try {
            $this->app->db()->value('SELECT 1');
        } catch (Throwable $e) {
            $dbError = 'Could not connect to the database. Check DATABASE_URL. (' . $e->getMessage() . ')';
        }

        if ($ctx->request->isPost() && $dbError === null) {
            $installToken = getenv('INSTALL_TOKEN') ?: '';
            try {
                if ($installToken !== '' && !hash_equals($installToken, $ctx->request->input('install_token'))) {
                    throw new InvalidArgumentException('The install token is not right.');
                }
                $siteName = $ctx->request->input('site_name');
                if ($siteName === '' || mb_strlen($siteName) > 60) {
                    throw new InvalidArgumentException('Site names are 1 to 60 characters.');
                }
                (new Migrator($this->app->db()))->migrate($this->app->rootDir . '/migrations');
                $db = $this->app->db();
                $db->transaction(function () use ($ctx, $siteName): void {
                    $user = $this->app->get(Users::class)->register(
                        $ctx->request->input('username'),
                        $ctx->request->input('email'),
                        (string) ($ctx->request->post['password'] ?? ''),
                        'admin',
                    );
                    $settings = $this->app->settings();
                    $settings->set('site.name', $siteName);
                    $nodes = $this->app->get(Nodes::class);
                    $category = $nodes->create('category', 'General');
                    $nodes->create('forum', 'Welcome', 'Say hello and introduce yourself.', (int) $category['id']);
                    $settings->set('installed', true);
                    $this->app->get(Auth::class)->login($ctx, $user);
                });
                $this->app->touchConfig();
                $ctx->flash('AllianceBB is installed. Welcome to your admin panel.');
                return Response::redirect('/admin');
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render($ctx, 'install.twig', [
            'error' => $error,
            'db_error' => $dbError,
            'needs_token' => (getenv('INSTALL_TOKEN') ?: '') !== '',
            'site_name' => $ctx->request->input('site_name'),
            'username' => $ctx->request->input('username'),
            'email' => $ctx->request->input('email'),
        ]);
    }
}
