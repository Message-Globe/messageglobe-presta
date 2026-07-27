<?php

declare(strict_types=1);

namespace MessageGlobe\Http;

use MessageGlobe\ApiConfig;
use MessageGlobe\Exception\ApiException;
use MessageGlobe\Exception\TransportException;

/**
 * Internal JSON transport shared by the REST feature clients.
 *
 * It applies authentication headers, encodes/decodes JSON, and converts any
 * error response — a non-2xx status or a body with `status: "error"` — into an
 * {@see ApiException}. The decoded payload it returns is always a success.
 */
final class RestClient
{
    private ApiConfig $config;

    private HttpClientInterface $httpClient;

    public function __construct(ApiConfig $config, ?HttpClientInterface $httpClient = null)
    {
        $this->config = $config;
        $this->httpClient = $httpClient ?? new CurlHttpClient();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function post(string $path, array $payload = []): array
    {
        $response = $this->httpClient->request(
            'POST',
            $this->config->endpoint($path),
            $this->headers(true),
            $this->encodeBody($payload)
        );

        return $this->guard($response);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function patch(string $path, array $payload = []): array
    {
        $response = $this->httpClient->request(
            'PATCH',
            $this->config->endpoint($path),
            $this->headers(true),
            $this->encodeBody($payload)
        );

        return $this->guard($response);
    }

    /**
     * @return array<mixed>
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function get(string $path): array
    {
        $response = $this->httpClient->request('GET', $this->config->endpoint($path), $this->headers(false));

        return $this->guard($response);
    }

    /**
     * @return array<mixed>
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function delete(string $path): array
    {
        $response = $this->httpClient->request('DELETE', $this->config->endpoint($path), $this->headers(false));

        return $this->guard($response);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws TransportException When the payload cannot be JSON-encoded.
     */
    private function encodeBody(array $payload): string
    {
        // Encode an empty payload as a JSON object ("{}") rather than "[]",
        // so no-parameter POST/PATCH requests send a valid empty body.
        if ($payload === []) {
            return '{}';
        }

        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new TransportException('Failed to encode request payload: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(bool $withContentType): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->config->apiToken(),
            'Accept' => 'application/json',
        ];

        if ($withContentType) {
            $headers['Content-Type'] = 'application/json';
        }

        $language = $this->config->acceptLanguage();
        if ($language !== null) {
            $headers['Accept-Language'] = $language;
        }

        return $headers;
    }

    /**
     * @return array<mixed>
     *
     * @throws ApiException
     * @throws TransportException
     */
    private function guard(HttpResponse $response): array
    {
        $payload = $response->json();

        $isErrorStatus = isset($payload['status']) && $payload['status'] === 'error';

        if ($response->isSuccessful() && !$isErrorStatus) {
            return $payload;
        }

        $message = isset($payload['message']) ? (string) $payload['message'] : 'The API request failed.';
        $apiErrorCode = isset($payload['error']) ? (int) $payload['error'] : null;

        throw new ApiException(
            $message,
            $response->statusCode(),
            $apiErrorCode,
            $this->extractErrors($payload),
            $payload
        );
    }

    /**
     * Flatten a `data.errors` map into field => message strings.
     *
     * The API returns error reasons either as a string or as an array of
     * strings, depending on the endpoint; both are normalised here.
     *
     * @param array<mixed> $payload
     *
     * @return array<string, string>
     */
    private function extractErrors(array $payload): array
    {
        if (!isset($payload['data']['errors']) || !is_array($payload['data']['errors'])) {
            return [];
        }

        $errors = [];
        foreach ($payload['data']['errors'] as $field => $reason) {
            if (is_array($reason)) {
                $reason = implode(' ', array_map(static fn ($item): string => (string) $item, $reason));
            } elseif (!is_scalar($reason)) {
                $reason = (string) json_encode($reason);
            }

            $errors[(string) $field] = (string) $reason;
        }

        return $errors;
    }
}
