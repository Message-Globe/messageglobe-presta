<?php

declare(strict_types=1);

namespace MessageGlobe\Sms;

/**
 * Immutable value object representing a single message as returned by the
 * `sms/send` and `sms/status/{id}` endpoints.
 */
final class SmsResult
{
    private string $messageId;

    private ?string $gateway;

    private ?string $senderId;

    private ?string $recipient;

    private ?string $country;

    private ?string $message;

    private int $smsCount;

    private ?string $smsType;

    private ?string $status;

    private ?float $cost;

    private ?string $createdAt;

    private ?string $updatedAt;

    /** @var array<string, mixed> */
    private array $raw;

    /**
     * @param array<string, mixed> $raw
     */
    private function __construct(
        string $messageId,
        ?string $gateway,
        ?string $senderId,
        ?string $recipient,
        ?string $country,
        ?string $message,
        int $smsCount,
        ?string $smsType,
        ?string $status,
        ?float $cost,
        ?string $createdAt,
        ?string $updatedAt,
        array $raw
    ) {
        $this->messageId = $messageId;
        $this->gateway = $gateway;
        $this->senderId = $senderId;
        $this->recipient = $recipient;
        $this->country = $country;
        $this->message = $message;
        $this->smsCount = $smsCount;
        $this->smsType = $smsType;
        $this->status = $status;
        $this->cost = $cost;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->raw = $raw;
    }

    /**
     * @param array<string, mixed> $data A single message object from the API response.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['message_id']) ? (string) $data['message_id'] : '',
            isset($data['gateway']) ? (string) $data['gateway'] : null,
            isset($data['sender_id']) ? (string) $data['sender_id'] : null,
            isset($data['recipient']) ? (string) $data['recipient'] : null,
            isset($data['country']) ? (string) $data['country'] : null,
            isset($data['message']) ? (string) $data['message'] : null,
            isset($data['sms_count']) ? (int) $data['sms_count'] : 0,
            isset($data['sms_type']) ? (string) $data['sms_type'] : null,
            isset($data['status']) ? (string) $data['status'] : null,
            isset($data['cost']) ? (float) $data['cost'] : null,
            isset($data['created_at']) ? (string) $data['created_at'] : null,
            isset($data['updated_at']) ? (string) $data['updated_at'] : null,
            $data
        );
    }

    public function messageId(): string
    {
        return $this->messageId;
    }

    public function gateway(): ?string
    {
        return $this->gateway;
    }

    public function senderId(): ?string
    {
        return $this->senderId;
    }

    public function recipient(): ?string
    {
        return $this->recipient;
    }

    public function country(): ?string
    {
        return $this->country;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    public function smsCount(): int
    {
        return $this->smsCount;
    }

    public function smsType(): ?string
    {
        return $this->smsType;
    }

    public function status(): ?string
    {
        return $this->status;
    }

    public function cost(): ?float
    {
        return $this->cost;
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
     * The full raw message object, for fields not surfaced by dedicated getters.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
