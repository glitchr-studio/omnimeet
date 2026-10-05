<?php

namespace Omnimeet\Exception;

use Omnimeet\Request\Request;

/**
 * The gateway does not do that: no callbacks from two browsers talking to
 * each other, no state read from a service that publishes none. supports()
 * says so beforehand.
 */
final class NotSupportedException extends \LogicException implements OmnimeetException
{
    public static function for(Request $request, string $gateway): self
    {
        return new self(\sprintf('The "%s" gateway does not support %s.', $gateway, (new \ReflectionClass($request))->getShortName()));
    }
}
