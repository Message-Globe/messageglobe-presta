<?php

declare(strict_types=1);

namespace MessageGlobe\Contacts;

/**
 * Immutable value object representing a contact list (group).
 *
 * The API documents the group payload loosely, so common fields are surfaced
 * through dedicated getters and the full object is always available via {@see raw()}.
 */
final class Group
{
    private ?string $uid;

    private ?string $name;

    private ?string $createdAt;

    private ?string $updatedAt;

    /** @var array<string, mixed> */
    private array $raw;

    /**
     * @param array<string, mixed> $raw
     */
    private function __construct(?string $uid, ?string $name, ?string $createdAt, ?string $updatedAt, array $raw)
    {
        $this->uid = $uid;
        $this->name = $name;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->raw = $raw;
    }

    /**
     * @param array<string, mixed> $data A group object from the API response.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['uid']) ? (string) $data['uid'] : null,
            isset($data['name']) ? (string) $data['name'] : null,
            isset($data['created_at']) ? (string) $data['created_at'] : null,
            isset($data['updated_at']) ? (string) $data['updated_at'] : null,
            $data
        );
    }

    public function uid(): ?string
    {
        return $this->uid;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function createdAt(): ?string
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * The full raw group object, for fields not surfaced by dedicated getters.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
