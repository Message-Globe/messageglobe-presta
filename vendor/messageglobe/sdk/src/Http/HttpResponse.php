<?php

declare(strict_types=1);

namespace MessageGlobe\Http;

use MessageGlobe\Exception\TransportException;

/**
 * Immutable value object describing an HTTP response.
 */
final class HttpResponse
{
    private int $statusCode;

    private string $body;

    /** @var array<string, string> Lower-cased header name => value. */
    private array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $statusCode, string $body, array $headers = [])
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->headers = $headers;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * True for any 2xx status code.
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Decode the response body as a JSON array.
     *
     * @return array<mixed>
     *
     * @throws TransportException When the body is not valid JSON.
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        $decoded = json_decode($this->body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new TransportException(
                sprintf('Failed to decode JSON response: %s', json_last_error_msg())
            );
        }

        return $decoded;
    }
}
