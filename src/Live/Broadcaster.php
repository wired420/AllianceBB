<?php

declare(strict_types=1);

namespace AllianceBB\Live;

/** Pushes events to open browser pages. Channels look like "topic/12" or "node/3". */
interface Broadcaster
{
    /** @param array<string, mixed> $data */
    public function publish(string $channel, string $type, array $data): void;

    /** Settings the browser needs to subscribe, rendered into each page. */
    public function clientConfig(): array;
}
