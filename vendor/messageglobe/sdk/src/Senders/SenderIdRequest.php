<?php

declare(strict_types=1);

namespace MessageGlobe\Senders;

use MessageGlobe\Exception\ValidationException;

/**
 * Fluent builder describing a sender ID to create.
 *
 * All fields are required by the API. The sender ID itself must be 3-11
 * characters long.
 *
 * <code>
 * $request = (new SenderIdRequest())
 *     ->senderId('YourName')
 *     ->company('Your Company')
 *     ->taxCode('ABCDEF12G34H567I')
 *     ->vatCode('01234567890')
 *     ->address('Via Roma 1')
 *     ->city('Rome')
 *     ->province('RM')
 *     ->country('IT')
 *     ->emailAddress('you@example.com')
 *     ->phone('391234567890')
 *     ->pecAddress('you@pec.example.com');
 * </code>
 */
final class SenderIdRequest
{
    private const MIN_SENDER_LENGTH = 3;
    private const MAX_SENDER_LENGTH = 11;

    private ?string $senderId = null;

    private ?string $company = null;

    private ?string $taxCode = null;

    private ?string $vatCode = null;

    private ?string $address = null;

    private ?string $city = null;

    private ?string $province = null;

    private ?string $country = null;

    private ?string $emailAddress = null;

    private ?string $phone = null;

    private ?string $pecAddress = null;

    public function senderId(string $senderId): self
    {
        $this->senderId = $senderId;

        return $this;
    }

    public function company(string $company): self
    {
        $this->company = $company;

        return $this;
    }

    public function taxCode(string $taxCode): self
    {
        $this->taxCode = $taxCode;

        return $this;
    }

    public function vatCode(string $vatCode): self
    {
        $this->vatCode = $vatCode;

        return $this;
    }

    public function address(string $address): self
    {
        $this->address = $address;

        return $this;
    }

    public function city(string $city): self
    {
        $this->city = $city;

        return $this;
    }

    public function province(string $province): self
    {
        $this->province = $province;

        return $this;
    }

    public function country(string $country): self
    {
        $this->country = $country;

        return $this;
    }

    public function emailAddress(string $emailAddress): self
    {
        if (filter_var($emailAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException(sprintf('Invalid email address "%s".', $emailAddress));
        }

        $this->emailAddress = $emailAddress;

        return $this;
    }

    public function phone(string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function pecAddress(string $pecAddress): self
    {
        if (filter_var($pecAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException(sprintf('Invalid PEC address "%s".', $pecAddress));
        }

        $this->pecAddress = $pecAddress;

        return $this;
    }

    /**
     * @throws ValidationException When a required field is missing or invalid.
     */
    public function validate(): void
    {
        $required = [
            'sender_id' => $this->senderId,
            'company' => $this->company,
            'tax_code' => $this->taxCode,
            'vat_code' => $this->vatCode,
            'address' => $this->address,
            'city' => $this->city,
            'province' => $this->province,
            'country' => $this->country,
            'email_address' => $this->emailAddress,
            'phone' => $this->phone,
            'pec_address' => $this->pecAddress,
        ];

        foreach ($required as $field => $value) {
            if ($value === null || trim($value) === '') {
                throw new ValidationException(sprintf('The sender ID field "%s" is required.', $field));
            }
        }

        $length = mb_strlen((string) $this->senderId);
        if ($length < self::MIN_SENDER_LENGTH || $length > self::MAX_SENDER_LENGTH) {
            throw new ValidationException(sprintf(
                'A sender ID must be between %d and %d characters, got %d.',
                self::MIN_SENDER_LENGTH,
                self::MAX_SENDER_LENGTH,
                $length
            ));
        }
    }

    /**
     * Build the JSON request payload.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'sender_id' => (string) $this->senderId,
            'company' => (string) $this->company,
            'tax_code' => (string) $this->taxCode,
            'vat_code' => (string) $this->vatCode,
            'address' => (string) $this->address,
            'city' => (string) $this->city,
            'province' => (string) $this->province,
            'country' => (string) $this->country,
            'email_address' => (string) $this->emailAddress,
            'phone' => (string) $this->phone,
            'pec_address' => (string) $this->pecAddress,
        ];
    }
}
