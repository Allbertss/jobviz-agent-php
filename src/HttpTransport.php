<?php

declare(strict_types=1);

namespace Jobviz\Agent;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Throwable;

final class HttpTransport
{
    private const MAX_ATTEMPTS = 4;
    private const RETRY_DELAYS = [1, 5, 15]; // seconds
    private const TIMEOUT = 10;
    private const MAX_PAYLOAD_BYTES = 5 * 1024 * 1024; // 5 MB
    private const MAX_CHUNK_SIZE = 500;

    private Client $client;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $apiKey,
        private readonly ?Closure $onError = null,
        private readonly ?string $agentVersion = null,
    ) {
        $this->client = new Client([
            'base_uri' => rtrim($this->endpoint, '/'),
            'timeout' => self::TIMEOUT,
        ]);
    }

    /**
     * Send a batch of events. Chunks large batches automatically.
     * Fire-and-forget: errors are reported via onError callback, never thrown.
     *
     * @param JobEvent[] $events
     */
    public function send(array $events): void
    {
        $chunks = array_chunk($events, self::MAX_CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            $this->sendChunk($chunk);
        }
    }

    /**
     * @param JobEvent[] $events
     */
    private function sendChunk(array $events): void
    {
        $payload = json_encode([
            'events' => array_map(fn(JobEvent $e) => $e->toArray(), $events),
        ]);

        if ($payload === false) {
            $this->reportError(new RuntimeException('Failed to encode events as JSON'), \count($events));

            return;
        }

        if (\strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            $this->reportError(
                new RuntimeException('Payload exceeds 5 MB limit (' . \strlen($payload) . ' bytes)'),
                \count($events),
            );

            return;
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($this->agentVersion !== null) {
            $headers['X-Jobviz-Agent-Version'] = $this->agentVersion;
        }

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->client->post('/api/v1/events', [
                    'headers' => $headers,
                    'body' => $payload,
                ]);

                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    return; // success
                }
            } catch (RequestException $e) {
                $response = $e->getResponse();
                $status = $response !== null ? $response->getStatusCode() : 0;

                // 4xx (non-429): permanent failure, don't retry
                if ($status >= 400 && $status < 500 && $status !== 429) {
                    $body = $response !== null ? (string) $response->getBody() : $e->getMessage();
                    $this->reportError(
                        new RuntimeException("Permanent HTTP error {$status}: {$body}"),
                        \count($events),
                    );

                    return;
                }

                // 429: respect Retry-After header
                if ($status === 429 && $response !== null) {
                    $retryAfter = $response->getHeaderLine('Retry-After');
                    $wait = $retryAfter !== '' ? min((int) $retryAfter, 60) : $this->retryDelay($attempt);
                    usleep((int) ($wait * 1_000_000));
                    continue;
                }

                // 5xx or network error: retryable
                if ($attempt < self::MAX_ATTEMPTS - 1) {
                    usleep((int) ($this->retryDelay($attempt) * 1_000_000));
                    continue;
                }

                $this->reportError($e, \count($events));

                return;
            } catch (ConnectException $e) {
                if ($attempt < self::MAX_ATTEMPTS - 1) {
                    usleep((int) ($this->retryDelay($attempt) * 1_000_000));
                    continue;
                }

                $this->reportError($e, \count($events));

                return;
            }
        }
    }

    private function retryDelay(int $attempt): float
    {
        return self::RETRY_DELAYS[$attempt] ?? self::RETRY_DELAYS[array_key_last(self::RETRY_DELAYS)];
    }

    private function reportError(Throwable $error, int $dropped): void
    {
        if ($this->onError !== null) {
            ($this->onError)($error, $dropped);
        }
    }
}
