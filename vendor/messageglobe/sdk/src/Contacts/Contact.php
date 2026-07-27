<?php

declare(strict_types=1);

namespace MessageGlobe\Contacts;

/**
 * Immutable value object representing a contact returned by the Contacts API.
 */
final class Contact
{
    private string $uid;

    private ?string $phone;

    private ?string $email;

    private ?string $firstName;

    private ?string $lastName;

    private ?string $status;

    private ?string $createdAt;

    /** @var array<string, mixed> */
    private array $raw;

    /**
     * @param array<string, mixed> $raw
     */
    private function __construct(
        string $uid,
        ?string $phone,
        ?string $email,
        ?string $firstName,
        ?string $lastName,
        ?string $status,
        ?string $createdAt,
        array $raw
    ) {
        $this->uid = $uid;
        $this->phone = $phone;
        $this->email = $email;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->status = $status;
        $this->createdAt = $createdAt;
        $this->raw = $raw;
    }

    /**
     * @param array<string, mixed> $data A contact object from the API response.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['uid']) ? (string) $data['uid'] : '',
            isset($data['phone']) ? (string) $data['phone'] : null,
            isset($data['email']) ? (string) $data['email'] : null,
            isset($data['first_name']) ? (string) $data['first_name'] : null,
            isset($data['last_name']) ? (string) $data['last_name'] : null,
            isset($data['status']) ? (string) $data['status'] : null,
            isset($data['created_at']) ? (string) $data['created_at'] : null,
            $data
        );
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function firstName(): ?string
    {
        return $this->firstName;
    }

    public function lastName(): ?string
    {
        return $this->lastName;
    }

    /**
     * Subscription status, e.g. "subscribe".
     */
    public function status(): ?string
    {
        return $this->status;
    }

    public function createdAt(): ?string
    {
        return $this->createdAt;
    }

    /**
     * The full raw contact object, for fields not surfaced by dedicated getters.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
