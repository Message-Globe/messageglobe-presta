<?php

declare(strict_types=1);

namespace MessageGlobe\Sms;

use MessageGlobe\Exception\MessageGlobeException;

/**
 * Result of a successful `sms/send` request.
 *
 * A request may target one or more recipients, so this wraps a collection of
 * {@see SmsResult} objects together with the human-readable API message.
 */
final class SmsResponse
{
    /** @var array<int, SmsResult> */
    private array $results;

    private string $message;

    /** @var array<mixed> */
    private array $raw;

    /**
     * @param array<int, SmsResult> $results
     * @param array<mixed>          $raw
     */
    public function __construct(array $results, string $message, array $raw = [])
    {
        $this->results = array_values($results);
        $this->message = $message;
        $this->raw = $raw;
    }

    /**
     * Build a response from a decoded successful API payload.
     *
     * @param array<mixed> $payload
     */
    public static function fromApiPayload(array $payload): self
    {
        $message = isset($payload['message']) ? (string) $payload['message'] : '';
        $data = $payload['data'] ?? [];

        $results = [];
        if (is_array($data) && $data !== []) {
            if (self::isList($data)) {
                foreach ($data as $item) {
                    if (is_array($item)) {
                        $results[] = SmsResult::fromArray($item);
                    }
                }
            } else {
                $results[] = SmsResult::fromArray($data);
            }
        }

        return new self($results, $message, $payload);
    }

    /**
     * The first (or only) message result.
     *
     * @throws MessageGlobeException When the response contains no results.
     */
    public function first(): SmsResult
    {
        if ($this->results === []) {
            throw new MessageGlobeException('The API response contained no message results.');
        }

        return $this->results[0];
    }

    /**
     * @return array<int, SmsResult>
     */
    public function all(): array
    {
        return $this->results;
    }

    /**
     * Convenience accessor for the first result's message id.
     */
    public function messageId(): ?string
    {
        return $this->results === [] ? null : $this->results[0]->messageId();
    }

    public function message(): string
    {
        return $this->message;
    }

    public function count(): int
    {
        return count($this->results);
    }

    public function isEmpty(): bool
    {
        return $this->results === [];
    }

    /**
     * The full decoded response body.
     *
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * @param array<mixed> $array
     */
    private static function isList(array $array): bool
    {
        // Polyfill for array_is_list() (PHP 8.1+) to keep 7.4 compatibility.
        $expected = 0;
        foreach ($array as $key => $_) {
            if ($key !== $expected++) {
                return false;
            }
        }

        return true;
    }
}
