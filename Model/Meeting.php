<?php

namespace Omnimeet\Model;

use Omnimeet\Exception\InvalidConfigException;

/**
 * A meeting, as the application describes it - its own key, when it starts
 * and ends, a title - and, once a gateway opened it, as the gateway knows
 * it: its reference there, where it stands, who is in. Whom it is for, who
 * may enter and when is the application's business; nothing of it is here.
 */
final readonly class Meeting
{
    /**
     * @param string            $key       the application's name for it: unguessable when it may reach a third party ("appointment:12" tells and can be guessed; a random token does neither)
     * @param string|null       $title     shown by a provider that shows one; null says nothing of what the meeting is about
     * @param string|null       $reference the gateway's name for it, once opened: what is kept to join, close and fetch
     * @param list<Participant> $present   who is in, when the gateway can tell (Fetch)
     */
    public function __construct(
        public string $key,
        public ?\DateTimeImmutable $startsAt = null,
        public ?\DateTimeImmutable $endsAt = null,
        public ?string $title = null,
        public ?string $reference = null,
        public Status $status = Status::PLANNED,
        public array $present = [],
    ) {
        if ('' === $key) {
            throw new \InvalidArgumentException('A meeting needs a key.');
        }
        if (null !== $startsAt && null !== $endsAt && $endsAt < $startsAt) {
            throw new \InvalidArgumentException('A meeting cannot end before it starts.');
        }
    }

    /** The same meeting as its gateway has it now. */
    public function with(?string $reference = null, ?Status $status = null, ?array $present = null): self
    {
        return new self($this->key, $this->startsAt, $this->endsAt, $this->title, $reference ?? $this->reference, $status ?? $this->status, $present ?? $this->present);
    }

    public function isOpened(): bool
    {
        return null !== $this->reference && '' !== $this->reference;
    }

    /** The gateway's reference, which only an opened meeting has. */
    public function reference(): string
    {
        return $this->isOpened() ? $this->reference : throw new InvalidConfigException(\sprintf('The meeting "%s" is not opened on a gateway yet: open() gives its reference.', $this->key));
    }
}
