<?php

namespace Omnimeet\Tests;

use Omnimeet\Exception\InvalidConfigException;
use Omnimeet\Exception\InvalidNotificationException;
use Omnimeet\Exception\NotSupportedException;
use Omnimeet\Exception\ProviderException;
use Omnimeet\Model\AccessKind;
use Omnimeet\Model\Event;
use Omnimeet\Model\Meeting;
use Omnimeet\Model\Participant;
use Omnimeet\Model\Status;
use Omnimeet\Request\Close;
use Omnimeet\Request\Fetch;
use Omnimeet\Request\Join;
use Omnimeet\Request\Notify;
use Omnimeet\Request\Open;
use PHPUnit\Framework\TestCase;

/** A gateway is its actions behind one door: open, join, close, fetch, notify. */
final class GatewayTest extends TestCase
{
    public function testAMeetingIsOpenedJoinedFetchedAndClosed(): void
    {
        $gateway = (new StubFactory())->create(['host' => 'meet.example.org']);
        self::assertSame(['stub', 'Stub'], [$gateway->getName(), $gateway->getTitle()]);
        self::assertSame(50, $gateway->capabilities()->maxParticipants);
        self::assertTrue($gateway->capabilities()->letsInBy(AccessKind::FRAME));

        $planned = new Meeting('a-key-nobody-guesses', new \DateTimeImmutable('+5 minutes'), new \DateTimeImmutable('+35 minutes'));
        self::assertSame(Status::PLANNED, $planned->status);

        $meeting = $gateway->open($planned);
        self::assertTrue($meeting->isOpened());
        self::assertSame(Status::OPEN, $meeting->status);
        self::assertSame($meeting->reference, $gateway->open($planned)->reference, 'the same key, the same room');
        self::assertSame('a-key-nobody-guesses', $meeting->key, 'the application\'s key stays');

        $host = $gateway->join($meeting, Participant::host('u1', 'Dr Tilleul'));
        self::assertSame(AccessKind::FRAME, $host->kind);
        self::assertSame('https://meet.example.org/'.$meeting->reference.'?as=u1', $host->url);
        self::assertSame(['https://meet.example.org'], $host->origins, 'a third party: the page says so before it loads anything');
        self::assertSame(Status::OPEN, $gateway->fetch($meeting)->status);

        $gateway->join($meeting, Participant::guest('u2'));
        $now = $gateway->fetch($meeting);
        self::assertSame(Status::LIVE, $now->status);
        self::assertSame(['u1', 'u2'], array_map(static fn (Participant $p) => $p->id, $now->present));

        $closed = $gateway->close($meeting);
        self::assertSame(Status::ENDED, $closed->status);
        self::assertTrue($closed->status->isOver());
        self::assertSame(Status::ENDED, $gateway->fetch($meeting)->status);
    }

    public function testAMeetingNotOpenedCannotBeJoined(): void
    {
        $gateway = (new StubFactory())->create(['host' => 'meet.example.org']);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The meeting "k" is not opened on a gateway yet');
        $gateway->join(new Meeting('k'), Participant::guest('u2'));
    }

    public function testACallbackIsReadOnceItsSignatureHolds(): void
    {
        $gateway = (new StubFactory())->create(['host' => 'meet.example.org']);
        $body = '{"event": "joined", "room": "room-1"}';

        $notification = $gateway->notify($body, ['x-stub-signature' => ['stub-signature']]);
        self::assertSame(Event::JOINED, $notification->event);
        self::assertSame('room-1', $notification->reference);

        $this->expectException(InvalidNotificationException::class);
        $gateway->notify($body, ['X-Stub-Signature' => 'forged']);
    }

    public function testAGatewaySaysWhatItDoesNotDo(): void
    {
        $gateway = (new StubFactory())->create(['host' => 'meet.example.org', 'silent' => true, 'link' => true]);

        self::assertTrue($gateway->supports(Open::class));
        self::assertTrue($gateway->supports(Join::class));
        self::assertFalse($gateway->supports(Close::class));
        self::assertFalse($gateway->supports(Fetch::class));
        self::assertFalse($gateway->supports(Notify::class));

        $meeting = $gateway->open(new Meeting('k'));
        self::assertSame(AccessKind::LINK, $gateway->join($meeting, Participant::guest('u2'))->kind);

        try {
            $gateway->close($meeting);
            self::fail('close() is not supported.');
        } catch (NotSupportedException $e) {
            self::assertSame('The "stub" gateway does not support Close.', $e->getMessage());
        }
        $this->expectException(NotSupportedException::class);
        $gateway->notify('{}');
    }

    public function testAnActionThatLeavesARequestUnansweredIsAnError(): void
    {
        $gateway = (new StubFactory())->create(['host' => 'meet.example.org']);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('[stub] Omnimeet\Tests\MuteAction left the request unanswered.');
        $gateway->execute(new Mute());
    }

    public function testAnOptionMissingIsSaidWhenTheGatewayIsBuilt(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The "stub" gateway needs: host.');
        (new StubFactory())->create();
    }
}
