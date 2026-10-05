<?php

namespace Omnimeet;

use Omnimeet\Model\Access;
use Omnimeet\Model\Capabilities;
use Omnimeet\Model\Meeting;
use Omnimeet\Model\Notification;
use Omnimeet\Model\Participant;
use Omnimeet\Request\Request;

/**
 * One way to hold a meeting, configured - the two browsers themselves, or a
 * conferencing service: the same questions for all of them. Each typed
 * method is a shortcut for execute() with its request; a gateway that does
 * not do something throws NotSupportedException, and supports() says so
 * beforehand.
 *
 * A gateway opens a room and lets someone in. It does not decide who may
 * enter, nor when: the application did, before asking.
 */
interface GatewayInterface
{
    public function getName(): string;

    public function getTitle(): string;

    /** What its meetings are: how many people, through whom, with what. */
    public function capabilities(): Capabilities;

    /** @param class-string<Request> $request */
    public function supports(string $request): bool;

    /**
     * @template T of Request
     *
     * @param T $request
     *
     * @return T answered
     *
     * @throws Exception\NotSupportedException
     * @throws Exception\ProviderException
     */
    public function execute(Request $request): Request;

    /** Open the meeting: it comes back with its reference on this gateway, to keep. */
    public function open(Meeting $meeting): Meeting;

    /** How this participant enters the (opened) meeting, now: an engine, a frame or an address. */
    public function join(Meeting $meeting, Participant $participant): Access;

    /** Nobody gets in any more; what the gateway kept of the meeting goes. */
    public function close(Meeting $meeting): Meeting;

    /** The meeting as the gateway has it now: its status, who is in. */
    public function fetch(Meeting $meeting): Meeting;

    /**
     * What the provider is telling us (a webhook): checked against its
     * signature, read as one event about one meeting.
     *
     * @param array<string, string|string[]> $headers
     *
     * @throws Exception\InvalidNotificationException when the signature does not hold
     */
    public function notify(string $body, array $headers = []): Notification;
}
