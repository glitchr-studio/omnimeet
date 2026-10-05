<?php

namespace Omnimeet\Model;

/** What a participant is in a meeting. */
enum Role: string
{
    /** Whoever holds the meeting: starts it, ends it, moderates it where the gateway has moderators. */
    case HOST = 'host';
    /** Whoever is received. */
    case GUEST = 'guest';
}
