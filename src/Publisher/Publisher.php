<?php

declare(strict_types=1);

namespace Systopic\Relay\Publisher;

/** How PHP hands a message to the hub. */
interface Publisher
{
    /**
     * The same message to several channels, in one call.
     *
     * @param list<string> $channels
     * @param array<string, mixed> $data
     */
    public function broadcast(array $channels, array $data): void;
}
