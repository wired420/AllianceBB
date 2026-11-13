<?php

declare(strict_types=1);

namespace AllianceBB\Controller;

use AllianceBB\Core\Context;
use AllianceBB\Http\HttpException;
use AllianceBB\Http\Response;
use AllianceBB\Live\Broadcaster;
use AllianceBB\Live\DatabaseBroadcaster;
use AllianceBB\View\Themes;

final class SystemController extends Controller
{
    /** Polling endpoint for live updates when no Mercure hub is configured. */
    public function livePoll(Context $ctx): Response
    {
        $live = $this->app->get(Broadcaster::class);
        if (!$live instanceof DatabaseBroadcaster) {
            return Response::json(['events' => [], 'after' => 0]);
        }
        $channels = array_values(array_filter(
            explode(',', $ctx->request->query('channels')),
            fn (string $c) => (bool) preg_match('#^(topic|node)/\d+$#', $c),
        ));
        $after = max(0, (int) $ctx->request->query('after', '0'));
        $events = $live->since(array_slice($channels, 0, 10), $after);
        $last = $events === [] ? $after : (int) end($events)['id'];
        return Response::json(['events' => $events, 'after' => max($last, $after)])
            ->withHeader('cache-control', 'no-store');
    }

    public function themeAsset(Context $ctx): Response
    {
        $file = $this->app->get(Themes::class)->assetPath($ctx->request->param('theme'), $ctx->request->param('path'));
        if ($file === null) {
            throw new HttpException(404);
        }
        $type = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=utf-8',
            'js' => 'text/javascript; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'woff2' => 'font/woff2',
            'ico' => 'image/x-icon',
            default => 'application/octet-stream',
        };
        $etag = '"' . md5($file . filemtime($file)) . '"';
        if ($ctx->request->header('if-none-match') === $etag) {
            return new Response('', 304, ['etag' => $etag]);
        }
        return new Response((string) file_get_contents($file), 200, [
            'content-type' => $type,
            'cache-control' => 'public, max-age=3600',
            'etag' => $etag,
        ]);
    }

    public function health(Context $ctx): Response
    {
        $this->app->db()->value('SELECT 1');
        return Response::json(['ok' => true, 'version' => \AllianceBB\App::VERSION]);
    }
}
