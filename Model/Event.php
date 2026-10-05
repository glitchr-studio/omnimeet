<?php

namespace Omnimeet\Model;

/** What a provider's callback is about. */
enum Event: string
{
    /** The meeting started on the provider's side: its first participant came in. */
    case OPENED = 'opened';
    case JOINED = 'joined';
    case LEFT = 'left';
    /** The meeting is over on the provider's side: its last participant left, or it was ended. */
    case ENDED = 'ended';
    case RECORDING_STARTED = 'recording_started';
    case RECORDING_ENDED = 'recording_ended';
    /** A recording can be fetched: Notification::$data has its address. */
    case RECORDING_READY = 'recording_ready';
    /** Anything else the provider says: Notification::$type has its own word for it. */
    case OTHER = 'other';
}
