<?php

declare(strict_types=1);

namespace MessageGlobe\Http;

use MessageGlobe\Exception\TransportException;

/**
 * Minimal HTTP client abstraction used by the SMS REST transport.
 *
 * The SDK ships with a zero-dependency cURL implementation
 * ({@see CurlHttpClient}), but any client may be supplied as long as it
 * fulfils this contract — useful for testing or for reusing a host
 * application's HTTP stack.
 */
interface HttpClientInterface
{
    /**
     * Perform an HTTP request and return the response.
     *
     * @param string                $method  HTTP method, e.g. "GET" or "POST".
     * @param string                $url     Fully-qualified request URL.
     * @param array<string, string> $headers Header name => value pairs.
     * @param string|null           $body    Raw request body, or null for none.
     *
     * @throws TransportException When the request cannot be completed.
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}
