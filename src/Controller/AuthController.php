<?php

declare(strict_types=1);

namespace AllianceBB\Controller;

use AllianceBB\Core\Context;
use AllianceBB\Http\HttpException;
use AllianceBB\Http\Response;
use AllianceBB\Service\Auth;
use AllianceBB\Service\Users;
use InvalidArgumentException;

final class AuthController extends Controller
{
    public function login(Context $ctx): Response
    {
        $error = null;
        if ($ctx->request->isPost()) {
            $user = $this->app->get(Users::class)->verify($ctx->request->input('login'), (string) ($ctx->request->post['password'] ?? ''));
            if ($user === null) {
                $error = 'That username or password is not right.';
            } elseif ($user['banned_at'] !== null) {
                $error = 'This account is banned.';
            } else {
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
        if ($ctx->request->isPost()) {
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
        return $this->render($ctx, 'account.twig', ['error' => $error]);
    }

    /** Only allow redirects back into this site. */
    private function safeNext(Context $ctx): string
    {
        $next = $ctx->request->input('next', '/');
        return preg_match('#^/(?![/\\\\])#', $next) ? $next : '/';
    }
}
