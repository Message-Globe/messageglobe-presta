<?php

declare(strict_types=1);

namespace MessageGlobe\Exception;

/**
 * Thrown when the SDK is configured with invalid or missing values.
 */
class ConfigurationException extends \InvalidArgumentException implements MessageGlobeExceptionInterface
{
}
