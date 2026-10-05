<?php

namespace Omnimeet\Tests;

use Omnimeet\Exception\InvalidConfigException;
use Omnimeet\Model\Access;
use Omnimeet\Model\AccessKind;
use Omnimeet\Model\Capabilities;
use Omnimeet\Model\Meeting;
use Omnimeet\Model\Participant;
use Omnimeet\Model\Role;
use Omnimeet\Model\Status;
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    public function testAMeetingIsTheApplicationsUntilAGatewayOpensIt(): void
    {
        $start = new \DateTimeImmutable('2026-10-12 10:00');
        $meeting = new Meeting('k3y', $start, $start->modify('+30 minutes'), 'Suivi');

        self::assertFalse($meeting->isOpened());
        self::assertSame(Status::PLANNED, $meeting->status);

        $opened = $meeting->with('room-1', Status::OPEN);
        self::assertNotSame($meeting, $opened);
        self::assertSame(['k3y', 'room-1', 'Suivi', $start], [$opened->key, $opened->reference(), $opened->title, $opened->startsAt]);
        self::assertSame('room-1', $opened->with(status: Status::ENDED)->reference, 'what is not given stays');

        $this->expectException(InvalidConfigException::class);
        $meeting->reference();
    }

    public function testAMeetingNeedsAKeyAndCannotEndBeforeItStarts(): void
    {
        try {
            new Meeting('');
            self::fail();
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        new Meeting('k', new \DateTimeImmutable('10:00'), new \DateTimeImmutable('09:00'));
    }

    public function testAParticipantIsARoleANameAndAnOpaqueIdentifier(): void
    {
        $host = Participant::host('u42', 'Dr Tilleul', 'fr');
        self::assertSame(['u42', Role::HOST, 'Dr Tilleul', 'fr'], [$host->id, $host->role, $host->name, $host->locale]);
        self::assertTrue($host->isHost());
        self::assertFalse(Participant::guest('u7')->isHost());
        self::assertNull((new Participant('u7'))->name, 'a name only when the application gives one');
        self::assertSame(['id', 'role', 'name', 'locale'], array_keys(get_object_vars($host)), 'nothing else: never an account');

        $this->expectException(\InvalidArgumentException::class);
        new Participant('');
    }

    public function testThreeWaysIn(): void
    {
        $engine = Access::engine('direct', '/vendor/omnimeet/direct/public/js/visio.js', ['role' => 'host']);
        self::assertSame(AccessKind::ENGINE, $engine->kind);
        self::assertFalse($engine->isThirdParty(), 'the page\'s own script: nobody else is asked anything');
        self::assertSame('camera; microphone; display-capture', $engine->allow());

        $frame = Access::frame('https://meet.example.org:8443/room?jwt=abc#config.x=1', 'jitsi', '/vendor/omnimeet/jitsi/public/js/jitsi.js', ['room' => 'room'], expiresAt: new \DateTimeImmutable('+1 hour'));
        self::assertSame(AccessKind::FRAME, $frame->kind);
        self::assertSame(['https://meet.example.org:8443'], $frame->origins);
        self::assertTrue($frame->isThirdParty());
        self::assertFalse($frame->isExpired());
        self::assertTrue($frame->isExpired(new \DateTimeImmutable('+2 hours')));

        $link = Access::link('https://zoom.example/j/1');
        self::assertSame(AccessKind::LINK, $link->kind);
        self::assertSame([], $link->permissions, 'nothing is embedded');
        self::assertSame(['https://zoom.example'], $link->origins);
    }

    public function testAnAccessIsWhole(): void
    {
        try {
            new Access(AccessKind::ENGINE);
            self::fail('An engine names its script.');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        new Access(AccessKind::FRAME);
    }

    public function testCapabilitiesSayWhatAGatewaysMeetingsAre(): void
    {
        $direct = new Capabilities(maxParticipants: 2, thirdParty: false, endToEnd: true, access: [AccessKind::ENGINE]);
        self::assertTrue($direct->takes(2));
        self::assertFalse($direct->takes(3));
        self::assertTrue($direct->letsInBy(AccessKind::ENGINE));
        self::assertFalse($direct->letsInBy(AccessKind::FRAME));
        self::assertSame(['engine'], $direct->toArray()['access']);
        self::assertFalse($direct->toArray()['recording']);
        self::assertTrue((new Capabilities())->takes(500), 'no limit known');
        self::assertTrue((new Capabilities())->thirdParty, 'a third party unless the gateway says otherwise');
    }

    public function testAStatusSaysWhetherSomeoneMayStillComeIn(): void
    {
        self::assertTrue(Status::OPEN->admits());
        self::assertTrue(Status::LIVE->admits());
        self::assertFalse(Status::PLANNED->admits());
        self::assertFalse(Status::ENDED->admits());
        self::assertTrue(Status::ENDED->isOver());
    }
}
