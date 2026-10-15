<?php

declare(strict_types=1);

namespace Systopic\Relay\Publisher;

use RuntimeException;

/**
 * Centrifugo's HTTP API (`POST /api/<method>`, header `X-API-Key`), on the
 * hub's internal port — or its unix socket: `unix:///run/relay/api.sock`.
 *
 * Short timeouts on purpose: a hub that is down must not hold up a request.
 */
final class Centrifugo implements Publisher
{
    public function __construct(
        private readonly string $api,
        private readonly string $key,
        private readonly int $timeoutMs = 1000,
    ) {}

    public function broadcast(array $channels, array $data): void
    {
        if ($channels === []) {
            return;
        }
        $this->call('broadcast', ['channels' => $channels, 'data' => $data]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function call(string $method, array $params): array
    {
        $socket = null;
        $base = rtrim($this->api, '/');
        if (str_starts_with($base, 'unix://')) {
            $socket = substr($base, strlen('unix://'));
            $base = 'http://localhost';
        }
        $curl = curl_init("$base/api/$method");
        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-API-Key: ' . $this->key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => min(300, $this->timeoutMs),
            CURLOPT_TIMEOUT_MS     => $this->timeoutMs,
        ]);
        if ($socket !== null) {
            curl_setopt($curl, CURLOPT_UNIX_SOCKET_PATH, $socket);
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        if ($body === false) {
            throw new RuntimeException("hub $method: $error");
        }
        if ($status !== 200) {
            throw new RuntimeException("hub $method: HTTP $status " . substr((string) $body, 0, 200));
        }
        $answer = json_decode((string) $body, true);
        if (!is_array($answer) || isset($answer['error'])) {
            throw new RuntimeException("hub $method: " . substr((string) $body, 0, 200));
        }
        return $answer;
    }
}
