<?php

namespace Omnimeet\Model;

/**
 * How one participant enters one meeting - what Join answers, and what the
 * page renders. It is made for that participant at that moment (temporary
 * credentials, a signed token): asked again each time, never stored, never
 * shown to anyone else.
 *
 *   ENGINE  $engine names a script of the gateway's package, $script is that
 *           file on disk (the application serves it), $options what the
 *           engine starts with;
 *   FRAME   $url is the address to frame; when $engine and $script are set,
 *           that script drives the frame with $options instead;
 *   LINK    $url is where to send the participant.
 */
final readonly class Access
{
    /**
     * @param string|null          $engine      the name of the script: "direct", "jitsi"
     * @param string|null          $script      its file, an absolute path inside the gateway's package
     * @param array<string, mixed> $options     what the script is started with: a JSON document
     * @param list<string>         $origins     the third parties the participant's browser will talk to ("https://meet.example.org"): none when the call stays between the participants and the application
     * @param list<string>         $permissions what the page or the frame needs from the browser: camera, microphone, display-capture
     */
    public function __construct(
        public AccessKind $kind,
        public ?string $url = null,
        public ?string $engine = null,
        public ?string $script = null,
        public array $options = [],
        public array $origins = [],
        public array $permissions = ['camera', 'microphone', 'display-capture'],
        public ?\DateTimeImmutable $expiresAt = null,
    ) {
        if (AccessKind::ENGINE === $kind && (null === $engine || null === $script)) {
            throw new \InvalidArgumentException('An engine access names its script and its file.');
        }
        if (AccessKind::ENGINE !== $kind && (null === $url || '' === $url)) {
            throw new \InvalidArgumentException('A frame or a link needs an address.');
        }
    }

    /** @param array<string, mixed> $options */
    public static function engine(string $engine, string $script, array $options = [], ?\DateTimeImmutable $expiresAt = null): self
    {
        return new self(AccessKind::ENGINE, null, $engine, $script, $options, [], ['camera', 'microphone', 'display-capture'], $expiresAt);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>|null    $origins the address's own origin when left out
     */
    public static function frame(string $url, ?string $engine = null, ?string $script = null, array $options = [], ?array $origins = null, ?\DateTimeImmutable $expiresAt = null): self
    {
        return new self(AccessKind::FRAME, $url, $engine, $script, $options, $origins ?? self::originOf($url), ['camera', 'microphone', 'display-capture'], $expiresAt);
    }

    public static function link(string $url, ?\DateTimeImmutable $expiresAt = null): self
    {
        return new self(AccessKind::LINK, $url, null, null, [], self::originOf($url), [], $expiresAt);
    }

    public function isThirdParty(): bool
    {
        return [] !== $this->origins;
    }

    public function isExpired(?\DateTimeInterface $now = null): bool
    {
        return null !== $this->expiresAt && $this->expiresAt < ($now ?? new \DateTimeImmutable());
    }

    /** The frame's allow attribute: "camera; microphone; display-capture". */
    public function allow(): string
    {
        return implode('; ', $this->permissions);
    }

    /** @return list<string> */
    private static function originOf(string $url): array
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'])) {
            return [];
        }

        return [$parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')];
    }
}
