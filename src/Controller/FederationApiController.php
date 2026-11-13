<?php

declare(strict_types=1);

namespace AllianceBB\Controller;

use AllianceBB\Core\Context;
use AllianceBB\Federation\ApiError;
use AllianceBB\Federation\Federation;
use AllianceBB\Http\Response;
use Throwable;

/** The site-to-site API under /api/alliance/v1. Every call except info and register is signed. */
final class FederationApiController extends Controller
{
    private function fed(): Federation
    {
        return $this->app->get(Federation::class);
    }

    /** @param callable(): array<string, mixed> $work */
    private function respond(callable $work, bool $requireEnabled = true): Response
    {
        try {
            if ($requireEnabled && !$this->fed()->enabled()) {
                throw new ApiError(503, 'federation_off', 'Sharing is turned off on this site.');
            }
            return Response::json($work());
        } catch (ApiError $e) {
            return Response::json(['error' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => 'invalid', 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('AllianceBB API: ' . $e::class . ': ' . $e->getMessage());
            return Response::json(['error' => 'server_error', 'message' => 'Something went wrong on this site.'], 500);
        }
    }

    public function info(Context $ctx): Response
    {
        return $this->respond(fn () => $this->fed()->info(), false);
    }

    public function register(Context $ctx): Response
    {
        return $this->respond(fn () => $this->fed()->register($ctx->request->json()));
    }

    public function subscribe(Context $ctx): Response
    {
        return $this->respond(function () use ($ctx) {
            $conn = $this->fed()->verify($ctx->request, 'subscriber');
            return $this->fed()->subscribe($conn, $ctx->request->json());
        });
    }

    public function unsubscribe(Context $ctx): Response
    {
        return $this->respond(function () use ($ctx) {
            $conn = $this->fed()->verify($ctx->request, 'subscriber');
            $this->fed()->unsubscribe($conn, $ctx->request->param('guid'));
            return ['status' => 'unsubscribed'];
        });
    }

    public function events(Context $ctx): Response
    {
        return $this->respond(function () use ($ctx) {
            $conn = $this->fed()->verify($ctx->request, 'subscriber');
            return $this->fed()->eventsSince(
                $conn,
                $ctx->request->param('guid'),
                max(0, (int) $ctx->request->query('after', '0')),
                (int) $ctx->request->query('limit', '200'),
            );
        });
    }

    public function topics(Context $ctx): Response
    {
        return $this->respond(function () use ($ctx) {
            $conn = $this->fed()->verify($ctx->request, 'subscriber');
            return $this->fed()->acceptTopic($conn, $ctx->request->param('guid'), $ctx->request->json());
        });
    }

    public function posts(Context $ctx): Response
    {
        return $this->respond(function () use ($ctx) {
            $conn = $this->fed()->verify($ctx->request, 'subscriber');
            return $this->fed()->acceptPost($conn, $ctx->request->param('guid'), $ctx->request->json());
        });
    }

    public function change(Context $ctx): Response
    {
        return $this->respond(function () use ($ctx) {
            $conn = $this->fed()->verify($ctx->request, 'subscriber');
            return $this->fed()->acceptChange(
                $conn,
                $ctx->request->param('guid'),
                $ctx->request->param('post'),
                $ctx->request->param('action'),
                $ctx->request->json(),
            );
        });
    }

    public function webhook(Context $ctx): Response
    {
        return $this->respond(function () use ($ctx) {
            $conn = $this->fed()->verify($ctx->request, 'owner');
            $this->fed()->receiveWebhook($conn, $ctx->request->json());
            return ['status' => 'ok'];
        });
    }
}
