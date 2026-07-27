<?php

declare(strict_types=1);

namespace MessageGlobe\Exception;

/**
 * Thrown when a transport-level failure occurs, e.g. a network error,
 * a timeout, or an unreadable response.
 */
class TransportException extends MessageGlobeException
{
}
