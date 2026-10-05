<?php

namespace Omnimeet;

use Omnimeet\Action\ActionInterface;
use Omnimeet\Action\ApiAwareInterface;
use Omnimeet\Exception\InvalidConfigException;
use Omnimeet\Model\Capabilities;

/**
 * The Omnibus way: a gateway's factory fills a Config - its name, title,
 * capabilities, the options it needs, its client ("omnimeet.api", a closure
 * of the Config: a provider's API, a token signer, a store) and its actions
 * ("omnimeet.action.<name>") - and the gateway is those actions, the client
 * handed to the ones that ask for it.
 *
 * Nothing here calls anything: a factory whose provider is reached over
 * HTTP takes its client in its own constructor.
 */
abstract class GatewayFactory implements GatewayFactoryInterface
{
    public function getName(): string
    {
        return $this->createConfig()['omnimeet.factory_name'];
    }

    public function create(array $options = []): GatewayInterface
    {
        $config = $this->createConfig($options);
        $config->validateNotEmpty($config->get('omnimeet.required_options', []));

        $api = $config->get('omnimeet.api');
        if ($api instanceof \Closure) {
            $api = $api($config);
        }
        $capabilities = $config->get('omnimeet.capabilities');
        if ($capabilities instanceof \Closure) {
            $capabilities = $capabilities($config);
        }
        if (!$capabilities instanceof Capabilities) {
            throw new InvalidConfigException(\sprintf('The "%s" factory does not say what its meetings are (omnimeet.capabilities).', $config['omnimeet.factory_name']));
        }

        $actions = [];
        foreach ($config as $key => $action) {
            if (!str_starts_with((string) $key, 'omnimeet.action.')) {
                continue;
            }
            if ($action instanceof \Closure) {
                $action = $action($config);
            }
            if (!$action instanceof ActionInterface) {
                continue;
            }
            if ($action instanceof ApiAwareInterface && null !== $api) {
                $action->setApi($api);
            }
            $actions[] = $action;
        }

        return new Gateway($config['omnimeet.factory_name'], $config['omnimeet.factory_title'], $capabilities, $actions);
    }

    public function createConfig(array $options = []): Config
    {
        $config = new Config($options);
        $this->populateConfig($config);

        return $config;
    }

    abstract protected function populateConfig(Config $config): void;
}
