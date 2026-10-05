<?php

namespace Omnimeet\Model;

/** The three ways a gateway lets someone in. */
enum AccessKind: string
{
    /** The page runs a JavaScript engine of the gateway's package, on the page's own markup. */
    case ENGINE = 'engine';
    /** The page frames the provider's own interface, and may drive it through a script. */
    case FRAME = 'frame';
    /** The participant is sent to an address: nothing is embedded. */
    case LINK = 'link';
}
