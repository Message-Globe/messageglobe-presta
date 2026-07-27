<?php

declare(strict_types=1);

namespace MessageGlobe\Senders;

/**
 * Immutable value object representing a sender ID.
 *
 * A curated set of fields is exposed through getters; the complete object
 * (which varies between the list, show, and create endpoints) is always
 * available via {@see raw()}.
 */
final class SenderId
{
    private ?int $id;

    private ?string $uid;

    private ?string $senderId;

    private ?string $status;

    private ?string $price;

    private ?string $billingCycle;

    private ?string $validityDate;

    private ?string $company;

    private ?string $country;

    private ?string $emailAddress;

    private ?string $phone;

    private ?string $createdAt;

    private ?string $updatedAt;

    /** @var array<string, mixed> */
    private array $raw;

    /**
     * @param array<string, mixed> $raw
     */
    private function __construct(array $fields, array $raw)
    {
        $this->id = $fields['id'];
        $this->uid = $fields['uid'];
        $this->senderId = $fields['sender_id'];
        $this->status = $fields['status'];
        $this->price = $fields['price'];
        $this->billingCycle = $fields['billing_cycle'];
        $this->validityDate = $fields['validity_date'];
        $this->company = $fields['company'];
        $this->country = $fields['country'];
        $this->emailAddress = $fields['email_address'];
        $this->phone = $fields['phone'];
        $this->createdAt = $fields['created_at'];
        $this->updatedAt = $fields['updated_at'];
        $this->raw = $raw;
    }

    /**
     * @param array<string, mixed> $data A sender ID object from the API response.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            [
                'id' => isset($data['id']) ? (int) $data['id'] : null,
                'uid' => isset($data['uid']) ? (string) $data['uid'] : null,
                'sender_id' => isset($data['sender_id']) ? (string) $data['sender_id'] : null,
                'status' => isset($data['status']) ? (string) $data['status'] : null,
                'price' => isset($data['price']) ? (string) $data['price'] : null,
                'billing_cycle' => isset($data['billing_cycle']) ? (string) $data['billing_cycle'] : null,
                'validity_date' => isset($data['validity_date']) ? (string) $data['validity_date'] : null,
                'company' => isset($data['company']) ? (string) $data['company'] : null,
                'country' => isset($data['country']) ? (string) $data['country'] : null,
                'email_address' => isset($data['email_address']) ? (string) $data['email_address'] : null,
                'phone' => isset($data['phone']) ? (string) $data['phone'] : null,
                'created_at' => isset($data['created_at']) ? (string) $data['created_at'] : null,
                'updated_at' => isset($data['updated_at']) ? (string) $data['updated_at'] : null,
            ],
            $data
        );
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function uid(): ?string
    {
        return $this->uid;
    }

    public function senderId(): ?string
    {
        return $this->senderId;
    }

    /**
     * Approval status, e.g. "active" or "pending".
     */
    public function status(): ?string
    {
        return $this->status;
    }

    public function price(): ?string
    {
        return $this->price;
    }

    public function billingCycle(): ?string
    {
        return $this->billingCycle;
    }

    public function validityDate(): ?string
    {
        return $this->validityDate;
    }

    public function company(): ?string
    {
        return $this->company;
    }

    public function country(): ?string
    {
        return $this->country;
    }

    public function emailAddress(): ?string
    {
        return $this->emailAddress;
    }

    public function phone(): ?string
    {
        return $this->phone;
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
     * The full raw sender ID object, for fields not surfaced by dedicated getters.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
