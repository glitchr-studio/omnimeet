<?php

namespace Omnimeet\Tests\Bridge;

use Omnimeet\Bridge\Symfony\OmnimeetBundle;
use Omnimeet\Direct\DirectGatewayFactory;
use Omnimeet\Direct\InMemorySignalStore;
use Omnimeet\Direct\SignalStoreInterface;
use Omnimeet\GatewayInterface;
use Omnimeet\Model\Meeting;
use Omnimeet\Model\Participant;
use Omnimeet\Registry;
use Omnimeet\Tests\StubFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class OmnimeetBundleTest extends TestCase
{
    public function testTheGatewaysConfiguredAreBuiltAndInjectableByName(): void
    {
        $container = new ContainerBuilder();
        // An application's own gateway, autoconfigured.
        $container->register(StubFactory::class)->setAutoconfigured(true);
        $container->register(Practice::class)->setAutowired(true)->setPublic(true);
        $bundle = new OmnimeetBundle();
        $container->registerExtension($bundle->getContainerExtension());
        $container->loadFromExtension('omnimeet', ['gateways' => [
            'consultation' => ['factory' => 'stub', 'options' => ['host' => 'meet.example.org']],
            'staff' => ['factory' => 'stub', 'options' => ['host' => 'staff.example.org']],
        ]]);
        $container->compile();

        $registry = $container->get(Registry::class);
        self::assertSame(['consultation', 'staff'], array_keys($registry->all()));

        $practice = $container->get(Practice::class);
        $meeting = $practice->consultation->open(new Meeting('k'));
        self::assertStringStartsWith('https://meet.example.org/', $practice->consultation->join($meeting, Participant::guest('u2'))->url, 'injected by its name');
        self::assertStringStartsWith('https://staff.example.org/', $practice->staff->join($meeting, Participant::guest('u2'))->url);
    }

    public function testAFactoryIsGivenWhatItsConstructorAsksOfTheApplication(): void
    {
        if (!class_exists(DirectGatewayFactory::class)) {
            self::markTestSkipped('omnimeet/direct is not installed.');
        }
        $container = new ContainerBuilder();
        // The application's store of the handshake: the direct gateway's factory asks for one.
        $container->register(InMemorySignalStore::class)->setPublic(true);
        $container->setAlias(SignalStoreInterface::class, InMemorySignalStore::class);
        $bundle = new OmnimeetBundle();
        $container->registerExtension($bundle->getContainerExtension());
        $container->loadFromExtension('omnimeet', ['gateways' => ['direct' => ['factory' => 'direct']]]);
        $container->compile();

        $registry = $container->get(Registry::class);
        self::assertContains('direct', $registry->factories(), 'the gateway packages installed are registered');
        $store = $container->get(InMemorySignalStore::class);
        $store->append('k', 'u1', 'hello', 'null');

        $registry->get('direct')->close($registry->get('direct')->open(new Meeting('k')));
        self::assertSame([], $store->read('k'), 'closed through the application\'s own store');
    }
}

final class Practice
{
    public function __construct(public readonly GatewayInterface $consultation, public readonly GatewayInterface $staff)
    {
    }
}
