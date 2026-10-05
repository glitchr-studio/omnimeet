<?php

namespace Omnimeet\Exception;

/** A gateway misconfigured: an option missing, an unknown factory, a meeting not opened yet. */
final class InvalidConfigException extends \LogicException implements OmnimeetException
{
}
