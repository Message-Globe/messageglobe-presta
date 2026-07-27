<?php

declare(strict_types=1);

namespace MessageGlobe\Senders;

use MessageGlobe\ApiConfig;
use MessageGlobe\Exception\ApiException;
use MessageGlobe\Exception\TransportException;
use MessageGlobe\Exception\ValidationException;
use MessageGlobe\Http\HttpClientInterface;
use MessageGlobe\Http\RestClient;

/**
 * Client for the MessageGlobe Sender ID REST API (v3).
 *
 * @see https://dashboard.messageglobe.com/developers
 */
final class SendersClient
{
    private RestClient $rest;

    public function __construct(ApiConfig $config, ?HttpClientInterface $httpClient = null)
    {
        $this->rest = new RestClient($config, $httpClient);
    }

    /**
     * List all sender IDs on the account.
     *
     * @return array<int, SenderId>
     *
     * @throws ApiException       When the API responds with an error.
     * @throws TransportException When the request cannot be completed or decoded.
     */
    public function all(): array
    {
        $payload = $this->rest->get('sms-sender');
        $data = (isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : [];

        $senders = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                $senders[] = SenderId::fromArray($item);
            }
        }

        return $senders;
    }

    /**
     * Retrieve a single sender ID by its name.
     *
     * @throws ValidationException When the name is empty.
     * @throws ApiException        When the sender ID is not found or another error occurs.
     * @throws TransportException  When the request cannot be completed or decoded.
     */
    public function show(string $senderIdName): SenderId
    {
        $senderIdName = $this->requireName($senderIdName);

        return SenderId::fromArray($this->extractData($this->rest->get('sms-sender/' . rawurlencode($senderIdName))));
    }

    /**
     * Create a new sender ID (submitted for approval).
     *
     * @throws ValidationException When a required field is missing or invalid.
     * @throws ApiException        When the API responds with an error.
     * @throws TransportException  When the request cannot be completed or decoded.
     */
    public function create(SenderIdRequest $request): SenderId
    {
        $request->validate();

        return SenderId::fromArray($this->extractData($this->rest->post('sms-sender', $request->toArray())));
    }

    /**
     * Delete a sender ID by its name.
     *
     * @throws ValidationException When the name is empty.
     * @throws ApiException        When the sender ID is not found or another error occurs.
     * @throws TransportException  When the request cannot be completed or decoded.
     */
    public function delete(string $senderIdName): void
    {
        $senderIdName = $this->requireName($senderIdName);

        $this->rest->delete('sms-sender/' . rawurlencode($senderIdName));
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function extractData(array $payload): array
    {
        return (isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : [];
    }

    /**
     * @throws ValidationException
     */
    private function requireName(string $senderIdName): string
    {
        $senderIdName = trim($senderIdName);
        if ($senderIdName === '') {
            throw new ValidationException('A sender ID name is required.');
        }

        return $senderIdName;
    }
}
