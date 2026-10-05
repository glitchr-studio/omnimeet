<?php

namespace Omnimeet\Request;

use Omnimeet\Model\Meeting;

/**
 * Open a meeting on the gateway. Result: the Meeting with its reference
 * there - what the application keeps to let people in, close and fetch.
 * Opening the same meeting (the same key) twice gives the same reference.
 */
final class Open extends Request
{
    public function __construct(public readonly Meeting $meeting)
    {
    }

    public function getMeeting(): ?Meeting
    {
        return $this->getResult();
    }
}
