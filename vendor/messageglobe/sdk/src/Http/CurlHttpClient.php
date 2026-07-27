<?php

declare(strict_types=1);

namespace MessageGlobe\Http;

use MessageGlobe\Exception\TransportException;

/**
 * Zero-dependency HTTP client backed by the cURL extension.
 */
final class CurlHttpClient implements HttpClientInterface
{
    private int $timeout;

    private int $connectTimeout;

    private ?string $userAgent;

    /**
     * @param int         $timeout        Maximum total request duration, in seconds.
     * @param int         $connectTimeout Maximum time to establish a connection, in seconds.
     * @param string|null $userAgent      User-Agent header, or null to omit it.
     *
     * @throws TransportException When the cURL extension is not available.
     */
    public function __construct(
        int $timeout = 30,
        int $connectTimeout = 10,
        ?string $userAgent = 'MessageGlobe-PHP-SDK'
    ) {
        if (!\extension_loaded('curl')) {
            throw new TransportException('The cURL PHP extension is required to use CurlHttpClient.');
        }

        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->userAgent = $userAgent;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('Failed to initialise a cURL handle.');
        }

        /** @var array<string, string> $responseHeaders */
        $responseHeaders = [];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_HTTPHEADER => $this->formatRequestHeaders($headers),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];

        if ($this->userAgent !== null) {
            $options[CURLOPT_USERAGENT] = $this->userAgent;
        }

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);

        $responseBody = curl_exec($handle);

        if ($responseBody === false) {
            $error = curl_error($handle);
            $errno = curl_errno($handle);
            curl_close($handle);

            throw new TransportException(sprintf('cURL request failed: %s (errno %d).', $error, $errno));
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new HttpResponse($statusCode, (string) $responseBody, $responseHeaders);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<int, string>
     */
    private function formatRequestHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        return $formatted;
    }
}
