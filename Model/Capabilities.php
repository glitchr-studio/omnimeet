<?php

namespace Omnimeet\Model;

/**
 * What a gateway's meetings are, said before one is opened: how many
 * people, through whom, with what. The operations it answers are asked of
 * the gateway itself (supports()).
 */
final readonly class Capabilities
{
    /**
     * @param int|null         $maxParticipants how many people one meeting takes; null: the provider's own limit, not known here
     * @param bool             $thirdParty      whether anyone but the participants and the application carries or sees the call
     * @param bool             $endToEnd        whether the media are encrypted from one participant to the other, with nobody in between able to read them
     * @param bool             $recording       whether the gateway can record a meeting
     * @param bool             $tokens          whether each participant enters with a credential made for them
     * @param list<AccessKind> $access          how it lets someone in
     */
    public function __construct(
        public ?int $maxParticipants = null,
        public bool $thirdParty = true,
        public bool $endToEnd = false,
        public bool $recording = false,
        public bool $screenSharing = false,
        public bool $chat = false,
        public bool $tokens = false,
        public array $access = [],
    ) {
    }

    public function takes(int $participants): bool
    {
        return null === $this->maxParticipants || $participants <= $this->maxParticipants;
    }

    public function letsInBy(AccessKind $kind): bool
    {
        return \in_array($kind, $this->access, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['access' => array_map(static fn (AccessKind $kind) => $kind->value, $this->access)] + get_object_vars($this);
    }
}
