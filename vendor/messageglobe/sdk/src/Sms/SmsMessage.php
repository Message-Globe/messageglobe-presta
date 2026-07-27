<?php

declare(strict_types=1);

namespace MessageGlobe\Sms;

use MessageGlobe\Exception\ValidationException;

/**
 * Fluent builder describing an outbound SMS.
 *
 * Example:
 * <code>
 * $sms = (new SmsMessage())
 *     ->to('393612345678')
 *     ->from('YourName')          // sender_id, used with the HQ gateway
 *     ->message('Hello world')
 *     ->highQuality();            // gateway HQ (custom sender + delivery reports)
 * </code>
 */
final class SmsMessage
{
    /** High-quality gateway: custom sender_id and delivery reports. */
    public const GATEWAY_HQ = 'HQ';

    /** Low-quality gateway: no custom sender and no delivery report. */
    public const GATEWAY_LQ = 'LQ';

    public const TYPE_PLAIN = 'plain';
    public const TYPE_UNICODE = 'unicode';

    private const GATEWAYS = [self::GATEWAY_HQ, self::GATEWAY_LQ];
    private const TYPES = [self::TYPE_PLAIN, self::TYPE_UNICODE];

    private const MAX_ALPHANUMERIC_SENDER_LENGTH = 11;

    /**
     * GSM 03.38 default alphabet plus the extension table. Characters outside
     * this set require the "unicode" SMS type.
     */
    private const GSM_ALPHABET =
        "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ ÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
        . "¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà"
        . "^{}\\[~]|€\f";

    /** @var array<int, string> */
    private array $recipients = [];

    private string $gateway = self::GATEWAY_HQ;

    private ?string $senderId = null;

    private string $type = self::TYPE_PLAIN;

    private ?string $message = null;

    private ?string $dlrCallbackUrl = null;

    /**
     * Add one or more recipients. Accepts a single number or a comma-separated
     * list; may be called multiple times to accumulate recipients.
     *
     * @throws ValidationException When a number is malformed.
     */
    public function to(string $recipient): self
    {
        foreach (explode(',', $recipient) as $number) {
            $number = trim($number);
            if ($number === '') {
                continue;
            }

            if (preg_match('/^\+?[0-9]{6,20}$/', $number) !== 1) {
                throw new ValidationException(sprintf('Invalid recipient number "%s".', $number));
            }

            $this->recipients[] = ltrim($number, '+');
        }

        return $this;
    }

    /**
     * Set the sender id (a phone number with international prefix, or an
     * alphanumeric string of up to 11 characters). Only used with the HQ gateway.
     */
    public function from(string $senderId): self
    {
        $this->senderId = $senderId;

        return $this;
    }

    /**
     * Alias of {@see from()}.
     */
    public function senderId(string $senderId): self
    {
        return $this->from($senderId);
    }

    /**
     * @throws ValidationException When the gateway is not HQ or LQ.
     */
    public function gateway(string $gateway): self
    {
        if (!in_array($gateway, self::GATEWAYS, true)) {
            throw new ValidationException(sprintf(
                'Invalid gateway "%s". Expected one of: %s.',
                $gateway,
                implode(', ', self::GATEWAYS)
            ));
        }

        $this->gateway = $gateway;

        return $this;
    }

    /**
     * Use the HQ gateway (custom sender_id and delivery reports).
     */
    public function highQuality(): self
    {
        return $this->gateway(self::GATEWAY_HQ);
    }

    /**
     * Use the LQ gateway (no custom sender, no delivery report).
     */
    public function lowQuality(): self
    {
        return $this->gateway(self::GATEWAY_LQ);
    }

    /**
     * @throws ValidationException When the type is not plain or unicode.
     */
    public function type(string $type): self
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new ValidationException(sprintf(
                'Invalid SMS type "%s". Expected one of: %s.',
                $type,
                implode(', ', self::TYPES)
            ));
        }

        $this->type = $type;

        return $this;
    }

    public function asPlain(): self
    {
        return $this->type(self::TYPE_PLAIN);
    }

    public function asUnicode(): self
    {
        return $this->type(self::TYPE_UNICODE);
    }

    public function message(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    /**
     * Set the message body and automatically pick the plain/unicode type
     * based on whether it contains non-GSM characters.
     */
    public function messageAutoType(string $message): self
    {
        $this->message = $message;
        $this->type = self::detectType($message);

        return $this;
    }

    /**
     * Provide a delivery-report callback URL (HQ gateway only). MessageGlobe
     * will POST a JSON payload to this URL for each delivery update.
     *
     * @throws ValidationException When the URL is malformed.
     */
    public function dlrCallbackUrl(string $url): self
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ValidationException(sprintf('Invalid DLR callback URL "%s".', $url));
        }

        $this->dlrCallbackUrl = $url;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    public function getGateway(): string
    {
        return $this->gateway;
    }

    public function getSenderId(): ?string
    {
        return $this->senderId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getDlrCallbackUrl(): ?string
    {
        return $this->dlrCallbackUrl;
    }

    /**
     * Detect whether a message needs the "unicode" type by checking for
     * characters outside the GSM 03.38 alphabet.
     */
    public static function detectType(string $message): string
    {
        if (!function_exists('mb_str_split')) {
            // Conservative fallback: treat anything beyond printable ASCII as unicode.
            return preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $message) === 1
                ? self::TYPE_UNICODE
                : self::TYPE_PLAIN;
        }

        $allowed = mb_str_split(self::GSM_ALPHABET, 1, 'UTF-8');
        $allowedSet = array_fill_keys($allowed, true);

        foreach (mb_str_split($message, 1, 'UTF-8') as $char) {
            if (!isset($allowedSet[$char])) {
                return self::TYPE_UNICODE;
            }
        }

        return self::TYPE_PLAIN;
    }

    /**
     * Validate that the message is ready to be sent.
     *
     * @throws ValidationException
     */
    public function validate(): void
    {
        if ($this->recipients === []) {
            throw new ValidationException('An SMS must have at least one recipient.');
        }

        if ($this->message === null || $this->message === '') {
            throw new ValidationException('An SMS must have a non-empty message.');
        }

        if ($this->gateway === self::GATEWAY_HQ && ($this->senderId === null || trim($this->senderId) === '')) {
            throw new ValidationException('A sender_id is required when using the HQ gateway.');
        }

        if ($this->senderId !== null && $this->isAlphanumericSender($this->senderId)) {
            if (mb_strlen($this->senderId) > self::MAX_ALPHANUMERIC_SENDER_LENGTH) {
                throw new ValidationException(sprintf(
                    'An alphanumeric sender_id must not exceed %d characters, got "%s".',
                    self::MAX_ALPHANUMERIC_SENDER_LENGTH,
                    $this->senderId
                ));
            }
        }
    }

    /**
     * Build the JSON request payload.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $payload = [
            'recipient' => implode(',', $this->recipients),
            'sender_id' => (string) $this->senderId,
            'type' => $this->type,
            'message' => (string) $this->message,
            'gateway' => $this->gateway,
        ];

        if ($this->dlrCallbackUrl !== null) {
            $payload['dlr_callback_url'] = $this->dlrCallbackUrl;
        }

        return $payload;
    }

    private function isAlphanumericSender(string $senderId): bool
    {
        return preg_match('/^\+?[0-9]+$/', $senderId) !== 1;
    }
}
