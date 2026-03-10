<?php

declare(strict_types=1);

namespace Jobviz\Agent;

final class Redactor
{
    private const MAX_DEPTH = 20;
    private const MAX_INPUT_BYTES = 1_048_576; // 1 MB

    /**
     * @param string[] $keys Lowercase keys to redact
     */
    public function __construct(
        private readonly array $keys,
    ) {}

    /**
     * Recursively redact sensitive keys from data.
     */
    public function redact(mixed $data): mixed
    {
        if ($this->keys === []) {
            return $data;
        }

        if (\is_string($data) && \strlen($data) > self::MAX_INPUT_BYTES) {
            return ['_notice' => '[INPUT_TOO_LARGE]'];
        }

        if (\is_array($data)) {
            $encoded = json_encode($data);
            if ($encoded !== false && \strlen($encoded) > self::MAX_INPUT_BYTES) {
                return ['_notice' => '[INPUT_TOO_LARGE]'];
            }
        }

        return $this->walk($data, 0);
    }

    private function walk(mixed $data, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH || !\is_array($data)) {
            return $data;
        }

        $result = [];
        foreach ($data as $key => $value) {
            if (\is_string($key) && \in_array(strtolower($key), $this->keys, true)) {
                $result[$key] = '[REDACTED]';
            } else {
                $result[$key] = $this->walk($value, $depth + 1);
            }
        }

        return $result;
    }
}
