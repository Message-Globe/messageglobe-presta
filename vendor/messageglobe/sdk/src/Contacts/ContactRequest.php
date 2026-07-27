<?php

declare(strict_types=1);

namespace MessageGlobe\Contacts;

use MessageGlobe\Exception\ValidationException;

/**
 * Fluent builder describing the attributes of a contact to create.
 *
 * At least one of phone or email must be provided.
 *
 * <code>
 * $contact = (new ContactRequest())
 *     ->phone('393310000000')
 *     ->firstName('Jane')
 *     ->lastName('Doe');
 * </code>
 */
final class ContactRequest
{
    private ?string $phone = null;

    private ?string $email = null;

    private ?string $firstName = null;

    private ?string $lastName = null;

    /**
     * @throws ValidationException When the number is malformed.
     */
    public function phone(string $phone): self
    {
        $phone = trim($phone);
        if (preg_match('/^\+?[0-9]{6,20}$/', $phone) !== 1) {
            throw new ValidationException(sprintf('Invalid phone number "%s".', $phone));
        }

        $this->phone = ltrim($phone, '+');

        return $this;
    }

    /**
     * @throws ValidationException When the address is malformed.
     */
    public function email(string $email): self
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException(sprintf('Invalid email address "%s".', $email));
        }

        $this->email = $email;

        return $this;
    }

    public function firstName(string $firstName): self
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function lastName(string $lastName): self
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    /**
     * @throws ValidationException When neither phone nor email is set.
     */
    public function validate(): void
    {
        if ($this->phone === null && $this->email === null) {
            throw new ValidationException('A contact must have at least a phone or an email.');
        }
    }

    /**
     * Build the JSON request payload, omitting unset fields.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $payload = [];

        if ($this->phone !== null) {
            $payload['phone'] = $this->phone;
        }
        if ($this->email !== null) {
            $payload['email'] = $this->email;
        }
        if ($this->firstName !== null) {
            $payload['first_name'] = $this->firstName;
        }
        if ($this->lastName !== null) {
            $payload['last_name'] = $this->lastName;
        }

        return $payload;
    }
}
