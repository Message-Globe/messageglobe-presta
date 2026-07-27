<?php

declare(strict_types=1);

namespace MessageGlobe\Exception;

/**
 * Marker interface implemented by every exception thrown by the SDK.
 *
 * Catch this to handle any MessageGlobe error with a single catch block,
 * regardless of the concrete exception type.
 */
interface MessageGlobeExceptionInterface extends \Throwable
{
}
