<?php

namespace Omnimeet\Model;

/**
 * Someone in a meeting, as little as a gateway needs: a role, the name
 * shown to the others, and an identifier that says nothing by itself -
 * never the application's account. A provider on the other side of the
 * network sees exactly this, and no more.
 */
final readonly class Participant
{
    /**
     * @param string      $id     opaque and stable for the meeting: "u42", a hash - what the application can find its own account by
     * @param string|null $name   shown to the other participants; null: the gateway's own default, or none
     * @param string|null $locale the language of the provider's interface, when it has one: "fr", "de"
     */
    public function __construct(
        public string $id,
        public Role $role = Role::GUEST,
        public ?string $name = null,
        public ?string $locale = null,
    ) {
        if ('' === $id) {
            throw new \InvalidArgumentException('A participant needs an identifier.');
        }
    }

    public static function host(string $id, ?string $name = null, ?string $locale = null): self
    {
        return new self($id, Role::HOST, $name, $locale);
    }

    public static function guest(string $id, ?string $name = null, ?string $locale = null): self
    {
        return new self($id, Role::GUEST, $name, $locale);
    }

    public function isHost(): bool
    {
        return Role::HOST === $this->role;
    }
}
