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
 * Client for the MessageGlobe Lists (contact groups) REST API (v3).
 *
 * A group is a container of contacts, identified by its uid (the group_id used
 * by {@see ContactsClient}).
 *
 * @see https://dashboard.messageglobe.com/developers
 */
final class GroupsClient
{
    private RestClient $rest;

    public function __construct(ApiConfig $config, ?HttpClientInterface $httpClient = null)
    {
        $this->rest = new RestClient($config, $httpClient);
    }

    /**
     * Create a new group.
     *
     * @throws ValidationException When the name is empty.
     * @throws ApiException        When the API responds with an error.
     * @throws TransportException  When the request cannot be completed or decoded.
     */
    public function create(string $name): Group
    {
        if (trim($name) === '') {
            throw new ValidationException('A group name is required.');
        }

        return Group::fromArray($this->extractData($this->rest->post('contacts', ['name' => $name])));
    }

    /**
     * Retrieve a single group by its uid.
     *
     * @throws ValidationException When the group id is empty.
     * @throws ApiException        When the API responds with an error.
     * @throws TransportException  When the request cannot be completed or decoded.
     */
    public function show(string $groupId): Group
    {
        $groupId = $this->requireGroupId($groupId);

        return Group::fromArray($this->extractData($this->rest->post('contacts/' . rawurlencode($groupId) . '/show')));
    }

    /**
     * Rename an existing group.
     *
     * @throws ValidationException When the group id or name is empty.
     * @throws ApiException        When the API responds with an error.
     * @throws TransportException  When the request cannot be completed or decoded.
     */
    public function update(string $groupId, string $name): Group
    {
        $groupId = $this->requireGroupId($groupId);
        if (trim($name) === '') {
            throw new ValidationException('A group name is required.');
        }

        return Group::fromArray($this->extractData($this->rest->patch('contacts/' . rawurlencode($groupId), ['name' => $name])));
    }

    /**
     * Delete a group.
     *
     * @throws ValidationException When the group id is empty.
     * @throws ApiException        When the API responds with an error.
     * @throws TransportException  When the request cannot be completed or decoded.
     */
    public function delete(string $groupId): void
    {
        $groupId = $this->requireGroupId($groupId);

        $this->rest->delete('contacts/' . rawurlencode($groupId));
    }

    /**
     * List all groups.
     *
     * The API returns paginated data whose exact shape is not documented, so the
     * raw decoded `data` value is returned as-is (typically including pagination
     * metadata).
     *
     * @return array<mixed>
     *
     * @throws ApiException       When the API responds with an error.
     * @throws TransportException When the request cannot be completed or decoded.
     */
    public function all(): array
    {
        $payload = $this->rest->get('contacts');

        return (isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : [];
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
    private function requireGroupId(string $groupId): string
    {
        $groupId = trim($groupId);
        if ($groupId === '') {
            throw new ValidationException('A group id (list uid) is required.');
        }

        return $groupId;
    }
}
