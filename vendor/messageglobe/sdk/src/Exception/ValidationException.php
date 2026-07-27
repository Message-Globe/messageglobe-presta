<?php

declare(strict_types=1);

namespace MessageGlobe\Exception;

/**
 * Thrown when a message (SMS or email) fails client-side validation
 * before it is handed to a transport.
 */
class ValidationException extends \InvalidArgumentException implements MessageGlobeExceptionInterface
{
}
