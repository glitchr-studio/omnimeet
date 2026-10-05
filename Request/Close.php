<?php

namespace Omnimeet\Request;

use Omnimeet\Model\Meeting;

/**
 * Close a meeting: nobody gets in any more, what the gateway kept of it
 * goes. Result: the Meeting, ENDED.
 */
final class Close extends Request
{
    public function __construct(public readonly Meeting $meeting)
    {
    }

    public function getMeeting(): ?Meeting
    {
        return $this->getResult();
    }
}
