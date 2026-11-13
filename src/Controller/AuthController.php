<?php

declare(strict_types=1);

namespace AllianceBB\Controller;

use AllianceBB\Core\Context;
use AllianceBB\Http\HttpException;
use AllianceBB\Http\Response;
use AllianceBB\Service\ApiTokens;
use AllianceBB\Service\Auth;
use AllianceBB\Service\Throttle;
use AllianceBB\Service\Users;
use InvalidArgumentException;

final class AuthController extends Controller
{
    public function login(Context $ctx): Response
    {
        $error = null;
        if ($ctx->request->isPost()) {
            $throttle = $this->app->get(Throttle::class);
            $keys = Throttle::keys($ctx->request->ip, $ctx->request->input('login'));
            try {
                $throttle->check($keys);
                $user = $this->app->get(Users::class)->verify($ctx->request->input('login'), (string) ($ctx->request->post['password'] ?? ''));
            } catch (HttpException $e) {
                $user = false;
                $error = $e->getMessage();
            }
            if ($user === false) {
                // throttled; $error is set
            } elseif ($user === null) {
                $throttle->fail($keys);
                $error = 'That username or password is not right.';
            } elseif ($user['banned_at'] !== null) {
                $error = 'This account is banned.';
            } else {
                $throttle->clear($keys);
                $this->app->get(Auth::class)->login($ctx, $user);
                $ctx->flash('Welcome back, ' . $user['username'] . '.');
                return Response::redirect($this->safeNext($ctx));
            }
        }
        return $this->render($ctx, 'login.twig', [
            'error' => $error,
            'login' => $ctx->request->input('login'),
            'next' => $this->safeNext($ctx),
        ]);
    }

    public function register(Context $ctx): Response
    {
        if (!$this->app->settings()->get('site.registration_open')) {
            throw new HttpException(403, 'Registration is closed on this site.');
        }
        $error = null;
        if ($ctx->request->isPost()) {
            try {
                $user = $this->app->get(Users::class)->register(
                    $ctx->request->input('username'),
                    $ctx->request->input('email'),
                    (string) ($ctx->request->post['password'] ?? ''),
                );
                $this->app->get(Auth::class)->login($ctx, $user);
                $ctx->flash('Welcome to ' . $this->app->settings()->get('site.name') . '!');
                return Response::redirect('/');
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }
        return $this->render($ctx, 'register.twig', [
            'error' => $error,
            'username' => $ctx->request->input('username'),
            'email' => $ctx->request->input('email'),
        ]);
    }

    public function logout(Context $ctx): Response
    {
        $this->app->get(Auth::class)->logout($ctx);
        return Response::redirect('/');
    }

    public function account(Context $ctx): Response
    {
        $this->requireLogin($ctx);
        $error = null;
        $tokens = $this->app->get(ApiTokens::class);
        $newToken = null;
        if ($ctx->request->isPost() && $ctx->request->input('action') === 'create_token') {
            try {
                $newToken = $tokens->create((int) $ctx->userId(), $ctx->request->input('token_name'), $ctx->request->input('token_scope'));
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        } elseif ($ctx->request->isPost() && $ctx->request->input('action') === 'revoke_token') {
            $tokens->revoke((int) $ctx->userId(), (int) $ctx->request->input('token_id'));
            $ctx->flash('Token revoked. Apps using it are signed out.');
            return Response::redirect($this->url('account') . '#tokens');
        } elseif ($ctx->request->isPost()) {
            try {
                $this->app->get(Users::class)->updateProfile(
                    (int) $ctx->userId(),
                    $ctx->request->input('display_name'),
                    $ctx->request->input('avatar_url'),
                );
                $this->app->hooks()->doAction('account.saved', $ctx);
                $ctx->flash('Profile saved.');
                return Response::redirect($this->url('account'));
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }
        return $this->render($ctx, 'account.twig', [
            'error' => $error,
            'api_enabled' => (bool) $this->app->settings()->get('api.enabled'),
            'tokens' => $tokens->forUser((int) $ctx->userId()),
            'new_token' => $newToken,
        ]);
    }

    /** Only allow redirects back into this site. */
    private function safeNext(Context $ctx): string
    {
        $next = $ctx->request->input('next', '/');
        return preg_match('#^/(?![/\\\\])#', $next) ? $next : '/';
    }
}
