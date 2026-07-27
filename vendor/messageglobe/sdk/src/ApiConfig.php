<?php

declare(strict_types=1);

namespace MessageGlobe;

use MessageGlobe\Exception\ConfigurationException;

/**
 * Immutable configuration shared by every REST feature (SMS, Contacts, ...).
 *
 * A single API token, created and copied from the MessageGlobe dashboard,
 * authorises all REST endpoints: https://dashboard.messageglobe.com/developers
 */
final class ApiConfig
{
    public const DEFAULT_BASE_URL = 'https://dashboard.messageglobe.com/api/v3';

    public const LANGUAGE_EN = 'en';
    public const LANGUAGE_IT = 'it';

    private const LANGUAGES = [self::LANGUAGE_EN, self::LANGUAGE_IT];

    private string $apiToken;

    private string $baseUrl;

    private ?string $acceptLanguage;

    /**
     * @param string      $apiToken       Bearer token from the developers dashboard.
     * @param string      $baseUrl        API base URL (a trailing slash is fine).
     * @param string|null $acceptLanguage Response language: "en", "it", or null for the API default.
     *
     * @throws ConfigurationException When any value is invalid.
     */
    public function __construct(
        string $apiToken,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?string $acceptLanguage = null
    ) {
        if (trim($apiToken) === '') {
            throw new ConfigurationException('The MessageGlobe API token must not be empty.');
        }

        if (trim($baseUrl) === '') {
            throw new ConfigurationException('The API base URL must not be empty.');
        }

        if ($acceptLanguage !== null && !in_array($acceptLanguage, self::LANGUAGES, true)) {
            throw new ConfigurationException(sprintf(
                'Invalid Accept-Language "%s". Expected one of: %s.',
                $acceptLanguage,
                implode(', ', self::LANGUAGES)
            ));
        }

        $this->apiToken = $apiToken;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->acceptLanguage = $acceptLanguage;
    }

    /**
     * Build a config from an associative array.
     *
     * Recognised keys: api_token (required), base_url, accept_language.
     *
     * @param array<string, mixed> $config
     *
     * @throws ConfigurationException
     */
    public static function fromArray(array $config): self
    {
        if (!isset($config['api_token'])) {
            throw new ConfigurationException('Missing required config key "api_token".');
        }

        return new self(
            (string) $config['api_token'],
            isset($config['base_url']) ? (string) $config['base_url'] : self::DEFAULT_BASE_URL,
            isset($config['accept_language']) ? (string) $config['accept_language'] : null
        );
    }

    public function apiToken(): string
    {
        return $this->apiToken;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function acceptLanguage(): ?string
    {
        return $this->acceptLanguage;
    }

    /**
     * Build a fully-qualified endpoint URL for the given path.
     */
    public function endpoint(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }
}
