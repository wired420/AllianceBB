<?php

declare(strict_types=1);

namespace AllianceBB\Controller;

use AllianceBB\App;
use AllianceBB\Core\Context;
use AllianceBB\Http\HttpException;
use AllianceBB\Http\Response;
use AllianceBB\Service\Permissions;

abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    /** @param array<string, mixed> $data */
    protected function render(Context $ctx, string $template, array $data = [], int $status = 200): Response
    {
        $data += ['ctx' => $ctx];
        return Response::html($this->app->view()->render($template, $data), $status);
    }

    protected function url(string $route, array $params = []): string
    {
        return $this->app->router()->url($route, $params);
    }

    protected function requireLogin(Context $ctx): void
    {
        if (!$ctx->isLoggedIn()) {
            throw new HttpException(403, 'Log in to do that.');
        }
    }

    protected function requireAdmin(Context $ctx): void
    {
        if (!$this->can($ctx, 'admin.access')) {
            throw new HttpException(403);
        }
    }

    protected function authorize(Context $ctx, string $ability, ?array $subject = null): void
    {
        if (!$this->can($ctx, $ability, $subject)) {
            throw new HttpException(403);
        }
    }

    protected function can(Context $ctx, string $ability, ?array $subject = null): bool
    {
        return $this->app->get(Permissions::class)->can($ctx, $ability, $subject);
    }

    /** @return int page number from ?page= */
    protected function page(Context $ctx): int
    {
        return max(1, (int) $ctx->request->query('page', '1'));
    }
}
