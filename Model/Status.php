<?php

namespace Omnimeet\Model;

/** Where a meeting stands, as its gateway knows it. */
enum Status: string
{
    /** Described, not opened on a gateway yet: it has no reference. */
    case PLANNED = 'planned';
    /** Opened: participants may be let in. Nobody is talking yet, as far as the gateway knows. */
    case OPEN = 'open';
    /** At least two participants are connected to each other. */
    case LIVE = 'live';
    /** Closed: nobody gets in any more. */
    case ENDED = 'ended';
    /** The gateway cannot tell. */
    case UNKNOWN = 'unknown';

    public function isOver(): bool
    {
        return self::ENDED === $this;
    }

    /** Whether a participant may still be let in. */
    public function admits(): bool
    {
        return self::OPEN === $this || self::LIVE === $this;
    }
}
