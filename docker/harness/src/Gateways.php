<?php

namespace Omnimeet\Harness;

use Omnimeet\Direct\DirectGatewayFactory;
use Omnimeet\Direct\SignalStoreInterface;
use Omnimeet\GatewayFactoryInterface;
use Omnimeet\Registry;

/**
 * The harness's registry, built by hand as any application outside a
 * framework builds it: a factory per gateway package installed, the
 * options from the environment (config/gateways.php).
 */
final class Gateways
{
    /** @var array<string, array{factory: string, needs: list<string>, options: array<string, mixed>, example: array<string, mixed>}> */
    public readonly array $config;

    /** @var array<string, GatewayFactoryInterface> */
    public readonly array $factories;

    public readonly Registry $registry;

    /**
     * @param SignalStoreInterface|null $signals where the direct gateway's handshake waits
     * @param bool                      $examples whether a gateway whose settings are missing runs on its example ones
     */
    public function __construct(?SignalStoreInterface $signals = null, bool $examples = false)
    {
        $this->config = require __DIR__.'/../config/gateways.php';
        $factories = [];
        foreach (require __DIR__.'/../plugins.php' as [, $class]) {
            if (class_exists($class)) {
                $factory = DirectGatewayFactory::class === $class ? new $class($signals) : new $class();
                $factories[$factory->getName()] = $factory;
            }
        }
        $this->factories = $factories;

        $gateways = [];
        foreach ($this->config as $name => $gateway) {
            if (!isset($factories[$gateway['factory']])) {
                continue;
            }
            if (!$this->missing($gateway)) {
                $gateways[$name] = ['factory' => $gateway['factory'], 'options' => array_filter($gateway['options'], static fn ($value) => null !== $value)];
            } elseif ($examples) {
                $gateways[$name] = ['factory' => $gateway['factory'], 'options' => $gateway['example']];
            }
        }
        $this->registry = new Registry($factories, $gateways);
    }

    /**
     * @param array{needs: list<string>} $gateway
     *
     * @return list<string> the environment variables it still needs
     */
    public function missing(array $gateway): array
    {
        return array_values(array_filter($gateway['needs'], static fn (string $key) => false === getenv($key) || '' === getenv($key)));
    }
}
