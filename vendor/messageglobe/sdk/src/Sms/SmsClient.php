<?php

declare(strict_types=1);

namespace MessageGlobe\Sms;

use MessageGlobe\ApiConfig;
use MessageGlobe\Exception\ApiException;
use MessageGlobe\Exception\TransportException;
use MessageGlobe\Exception\ValidationException;
use MessageGlobe\Http\HttpClientInterface;
use MessageGlobe\Http\RestClient;

/**
 * Client for the MessageGlobe SMS REST API (v3).
 *
 * @see https://dashboard.messageglobe.com/developers
 */
final class SmsClient
{
    private RestClient $rest;

    /**
     * @param ApiConfig                $config     API token and endpoint configuration.
     * @param HttpClientInterface|null $httpClient Custom HTTP client; defaults to cURL.
     */
    public function __construct(ApiConfig $config, ?HttpClientInterface $httpClient = null)
    {
        $this->rest = new RestClient($config, $httpClient);
    }

    /**
     * Send an SMS to one or more recipients.
     *
     * @throws ValidationException When the message is incomplete or invalid.
     * @throws ApiException        When the API responds with an error.
     * @throws TransportException  When the request cannot be completed or decoded.
     */
    public function send(SmsMessage $message): SmsResponse
    {
        $message->validate();

        $payload = $this->rest->post('sms/send', $message->toArray());

        return SmsResponse::fromApiPayload($payload);
    }

    /**
     * Retrieve the current status of a previously sent message.
     *
     * @param string $messageId The unique message id returned when sending.
     *
     * @throws ValidationException When the message id is empty.
     * @throws ApiException        When the API responds with an error.
     * @throws TransportException  When the request cannot be completed or decoded.
     */
    public function status(string $messageId): SmsResult
    {
        if (trim($messageId) === '') {
            throw new ValidationException('A message id is required to query SMS status.');
        }

        $payload = $this->rest->get('sms/status/' . rawurlencode($messageId));
        $data = (isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : [];

        return SmsResult::fromArray($data);
    }
}
