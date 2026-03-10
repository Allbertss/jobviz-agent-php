<?php

declare(strict_types=1);

namespace Jobviz\Agent;

use Closure;
use InvalidArgumentException;
use Jobviz\Agent\Providers\QueueProviderInterface;

final class Config
{
    public const DEFAULT_ENDPOINT = 'https://app.jobviz.dev';
    public const DEFAULT_BATCH_SIZE = 100;
    public const DEFAULT_FLUSH_INTERVAL = 3.0;
    public const DEFAULT_MAX_BUFFER_SIZE = 10_000;

    private const DEFAULT_REDACT_KEYS = [
        'password',
        'secret',
        'token',
        'apikey',
        'api_key',
        'authorization',
        'creditcard',
        'credit_card',
        'ssn',
        'accesstoken',
        'access_token',
        'refreshtoken',
        'refresh_token',
    ];

    /** @var string[] */
    public readonly array $redactKeys;

    /**
     * @param string[]|bool $redactKeys true = defaults, array = merge with defaults, false = disabled
     */
    public function __construct(
        public readonly string $apiKey,
        public readonly QueueProviderInterface $provider,
        public readonly string $endpoint = self::DEFAULT_ENDPOINT,
        public readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
        public readonly float $flushInterval = self::DEFAULT_FLUSH_INTERVAL,
        public readonly int $maxBufferSize = self::DEFAULT_MAX_BUFFER_SIZE,
        public readonly ?string $env = null,
        public readonly bool $captureInput = true,
        public readonly bool $captureStackTraces = true,
        public readonly bool $debug = false,
        array|bool $redactKeys = true,
        public readonly ?Closure $onError = null,
    ) {
        if (\strlen($apiKey) < 10) {
            throw new InvalidArgumentException('API key must be at least 10 characters.');
        }

        if ($redactKeys === false) {
            $this->redactKeys = [];
        } elseif ($redactKeys === true) {
            $this->redactKeys = self::DEFAULT_REDACT_KEYS;
        } else {
            $this->redactKeys = array_values(array_unique(
                array_merge(self::DEFAULT_REDACT_KEYS, $redactKeys),
            ));
        }
    }
}
