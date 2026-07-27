<?php

declare(strict_types=1);

namespace MessageGlobe\Exception;

/**
 * Thrown when the MessageGlobe REST API returns an error response
 * (either a non-2xx HTTP status or a JSON body with `status: "error"`).
 */
class ApiException extends MessageGlobeException
{
    private int $httpStatusCode;

    private ?int $apiErrorCode;

    /** @var array<string, string> Field-level validation errors keyed by field name. */
    private array $errors;

    /** @var array<mixed> The full decoded response body. */
    private array $raw;

    /**
     * @param array<string, string> $errors
     * @param array<mixed>          $raw
     */
    public function __construct(
        string $message,
        int $httpStatusCode,
        ?int $apiErrorCode = null,
        array $errors = [],
        array $raw = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $apiErrorCode ?? 0, $previous);

        $this->httpStatusCode = $httpStatusCode;
        $this->apiErrorCode = $apiErrorCode;
        $this->errors = $errors;
        $this->raw = $raw;
    }

    /**
     * The HTTP status code returned by the API.
     */
    public function httpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * The application-level error code from the response `error` field, if present.
     */
    public function apiErrorCode(): ?int
    {
        return $this->apiErrorCode;
    }

    /**
     * Field-level validation errors, e.g. ['recipient' => '... is not a valid mobile number'].
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The full decoded response body, for debugging or custom handling.
     *
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
