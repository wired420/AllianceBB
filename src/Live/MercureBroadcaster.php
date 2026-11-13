<?php

declare(strict_types=1);

namespace AllianceBB\Live;

/**
 * Publishes to a Mercure hub (built into FrankenPHP). Browsers receive events
 * over server-sent events within a moment of the post being saved.
 */
final class MercureBroadcaster implements Broadcaster
{
    public function __construct(
        private readonly string $hubUrl,
        private readonly string $publicHubUrl,
        private readonly string $jwtSecret,
        private readonly string $topicPrefix,
    ) {
    }

    public function publish(string $channel, string $type, array $data): void
    {
        $topic = $this->topicPrefix . $channel;
        $body = http_build_query([
            'topic' => $topic,
            'data' => json_encode(['type' => $type, 'channel' => $channel, 'data' => $data], JSON_UNESCAPED_SLASHES),
        ]);
        $ch = curl_init($this->hubUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->jwt()],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function jwt(): string
    {
        $enc = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $header = $enc((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $enc((string) json_encode(['mercure' => ['publish' => ['*']]]));
        $sig = $enc(hash_hmac('sha256', "$header.$payload", $this->jwtSecret, true));
        return "$header.$payload.$sig";
    }

    public function clientConfig(): array
    {
        return ['mode' => 'mercure', 'hub' => $this->publicHubUrl, 'prefix' => $this->topicPrefix];
    }
}
