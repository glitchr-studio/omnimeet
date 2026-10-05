<?php

namespace Omnimeet\Harness;

use Omnimeet\Direct\Signal;
use Omnimeet\Direct\SignalStoreInterface;

/**
 * The demo's store of the handshake: one JSON file per room, locked while
 * it is read and written - enough for PHP's own web server and two tabs,
 * and an example of how little a SignalStoreInterface is. An application
 * keeps it where it keeps the rest: a table, a cache.
 */
final class FileSignalStore implements SignalStoreInterface
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('The directory "%s" cannot be made.', $directory));
        }
    }

    public function append(string $room, string $sender, string $type, string $payload): int
    {
        return $this->locked($room, static function (array &$state) use ($sender, $type, $payload): int {
            $state['signals'][] = ['id' => ++$state['last'], 'sender' => $sender, 'type' => $type, 'payload' => $payload];

            return $state['last'];
        });
    }

    public function read(string $room, int $after = 0, ?string $except = null, int $limit = 200): array
    {
        $signals = array_filter($this->locked($room, static fn (array &$state): array => $state['signals']), static fn (array $signal) => $signal['id'] > $after && $signal['sender'] !== $except);

        return array_map(static fn (array $signal) => new Signal($signal['id'], $room, $signal['sender'], $signal['type'], $signal['payload']), \array_slice(array_values($signals), 0, $limit));
    }

    public function purge(string $room): int
    {
        return $this->locked($room, static function (array &$state): int {
            $count = \count($state['signals']);
            // The last number stays: numbers never come back.
            $state['signals'] = [];

            return $count;
        });
    }

    /**
     * @template T
     *
     * @param \Closure(array{last: int, signals: list<array{id: int, sender: string, type: string, payload: string}>}&): T $do
     *
     * @return T
     */
    private function locked(string $room, \Closure $do): mixed
    {
        $handle = fopen($this->directory.'/'.hash('sha256', $room).'.json', 'c+');
        flock($handle, \LOCK_EX);
        try {
            $state = json_decode((string) stream_get_contents($handle), true) ?: ['last' => 0, 'signals' => []];
            $before = $state;
            $result = $do($state);
            if ($state !== $before) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, json_encode($state));
            }

            return $result;
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }
}
