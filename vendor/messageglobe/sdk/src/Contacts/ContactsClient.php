<?php

declare(strict_types=1);

namespace MessageGlobe\Contacts;

use MessageGlobe\ApiConfig;
use MessageGlobe\Exception\ApiException;
use MessageGlobe\Exception\TransportException;
use MessageGlobe\Exception\ValidationException;
use MessageGlobe\Http\HttpClientInterface;
use MessageGlobe\Http\RestClient;

/**
 * Client for the MessageGlobe Contacts REST API (v3).
 *
 * Contacts live inside a list identified by its group id (the list uid).
 *
 * @see https://dashboard.messageglobe.com/developers
 */
final class ContactsClient
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
     * Create a contact inside the given list.
     *
     * @param string         $groupId The list uid the contact belongs to.
     * @param ContactRequest $contact The contact attributes.
     *
     * @throws ValidationException When the group id is empty or the contact is invalid.
     * @throws ApiException        When the API responds with an error.
     * @throws TransportException  When the request cannot be completed or decoded.
     */
    public function create(string $groupId, ContactRequest $contact): Contact
    {
        $groupId = trim($groupId);
        if ($groupId === '') {
            throw new ValidationException('A group id (list uid) is required to create a contact.');
        }

        $contact->validate();

        $payload = $this->rest->post(
            'contacts/' . rawurlencode($groupId) . '/store',
            $contact->toArray()
        );

        $data = (isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : [];

        return Contact::fromArray($data);
    }

    /**
     * Delete a contact from the given list.
     *
     * @param string $groupId The list uid the contact belongs to.
     * @param string $uid     The contact uid.
     *
     * @throws ValidationException When the group id or contact uid is empty.
     * @throws ApiException        When the API responds with an error (e.g. not found).
     * @throws TransportException  When the request cannot be completed or decoded.
     */
    public function delete(string $groupId, string $uid): void
    {
        $groupId = trim($groupId);
        $uid = trim($uid);

        if ($groupId === '') {
            throw new ValidationException('A group id (list uid) is required to delete a contact.');
        }
        if ($uid === '') {
            throw new ValidationException('A contact uid is required to delete a contact.');
        }

        $this->rest->delete(
            'contacts/' . rawurlencode($groupId) . '/delete/' . rawurlencode($uid)
        );
    }
}
