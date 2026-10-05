<?php

namespace Omnimeet;

use Omnimeet\Exception\InvalidConfigException;

/**
 * A gateway's configuration while its factory builds it: the options given,
 * the factory's defaults under them, and the keys that must be filled.
 *
 * @extends \ArrayObject<string, mixed>
 */
final class Config extends \ArrayObject
{
    /** Sets each key not set yet. */
    public function defaults(array $defaults): self
    {
        foreach ($defaults as $key => $value) {
            if (!$this->offsetExists($key)) {
                $this[$key] = $value;
            }
        }

        return $this;
    }

    /** @param string[] $keys */
    public function validateNotEmpty(array $keys): void
    {
        $missing = array_values(array_filter($keys, fn (string $key) => !isset($this[$key]) || '' === $this[$key] || [] === $this[$key]));
        if ($missing) {
            throw new InvalidConfigException(\sprintf('The "%s" gateway needs: %s.', $this['omnimeet.factory_name'] ?? '?', implode(', ', $missing)));
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->offsetExists($key) ? $this[$key] : $default;
    }

    /**
     * A list given as one environment variable arrives as a single
     * comma-separated string: split here, at run time.
     *
     * @return list<string>
     */
    public function list(string $key): array
    {
        $list = [];
        foreach ((array) $this->get($key, []) as $value) {
            foreach (explode(',', (string) $value) as $one) {
                if ('' !== trim($one)) {
                    $list[] = trim($one);
                }
            }
        }

        return $list;
    }
}
