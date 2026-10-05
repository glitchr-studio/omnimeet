<?php

namespace Omnimeet\Tests;

use Omnimeet\Exception\InvalidConfigException;
use Omnimeet\Registry;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    private function registry(): Registry
    {
        return new Registry([new StubFactory()], [
            'consultation' => ['factory' => 'stub', 'options' => ['host' => 'meet.example.org']],
            'staff' => ['factory' => 'stub', 'options' => ['host' => 'staff.example.org', 'link' => true]],
            'broken' => ['factory' => 'stub'],
            'elsewhere' => ['factory' => 'zoom'],
        ]);
    }

    public function testGatewaysByNameBuiltOnce(): void
    {
        $registry = $this->registry();

        self::assertSame(['consultation', 'staff', 'broken', 'elsewhere'], $registry->names());
        self::assertSame(['stub'], $registry->factories());
        self::assertSame($registry->get('consultation'), $registry->get('consultation'));
        self::assertTrue($registry->has('staff'));
        self::assertFalse($registry->has('direct'));
        self::assertSame(['host' => 'staff.example.org', 'link' => true], $registry->options('staff'));
        self::assertSame([], $registry->options('broken'));
    }

    public function testNothingIsBuiltBeforeItIsAskedFor(): void
    {
        $registry = $this->registry();
        self::assertSame('stub', $registry->get('staff')->getName(), 'the others\' options are not looked at');

        try {
            $registry->get('broken');
            self::fail('An option is missing.');
        } catch (InvalidConfigException $e) {
            self::assertSame('The "stub" gateway needs: host.', $e->getMessage());
        }
        try {
            $registry->get('elsewhere');
            self::fail('No such factory.');
        } catch (InvalidConfigException $e) {
            self::assertSame('No "zoom" factory for the "elsewhere" gateway; installed: stub.', $e->getMessage());
        }
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('No "direct" gateway; configured: consultation, staff, broken, elsewhere.');
        $registry->get('direct');
    }

    public function testAGatewayBuiltAfreshWithOptionsOverTheConfiguredOnes(): void
    {
        $registry = $this->registry();
        $other = $registry->create('consultation', ['host' => 'other.example.org']);
        $meeting = $other->open(new \Omnimeet\Model\Meeting('k'));

        self::assertStringStartsWith('https://other.example.org/', $other->join($meeting, \Omnimeet\Model\Participant::guest('u'))->url);
        self::assertNotSame($other, $registry->get('consultation'), 'not kept: get() still gives the configured one');
    }
}
