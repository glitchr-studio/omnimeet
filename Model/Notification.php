<?php

namespace Omnimeet\Model;

/**
 * What a provider told the application (a webhook), read as one event about
 * one meeting, its signature checked.
 */
final readonly class Notification
{
    /**
     * @param string               $type      the provider's own name for the event
     * @param string|null          $reference the meeting, as Open named it
     * @param string|null          $id        the same for a callback sent twice: what to deduplicate on
     * @param array<string, mixed> $data      the provider's payload
     */
    public function __construct(
        public Event $event,
        public string $type,
        public ?string $reference = null,
        public ?Participant $participant = null,
        public ?\DateTimeImmutable $at = null,
        public ?string $id = null,
        public array $data = [],
    ) {
    }
}
