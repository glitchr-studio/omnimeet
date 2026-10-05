<?php

namespace Omnimeet\Harness;

use Omnimeet\Direct\DirectGatewayFactory;
use Omnimeet\Direct\InMemorySignalStore;
use Omnimeet\Exception\InvalidConfigException;
use Omnimeet\Exception\OmnimeetException;
use Omnimeet\Model\AccessKind;
use Omnimeet\Model\Meeting;
use Omnimeet\Model\Participant;
use Omnimeet\Model\Role;
use Omnimeet\Request;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The console that asks every gateway: gateways, open, join, handshake,
 * notify. A call itself happens between browsers - that is the demo
 * (docker compose up), not a command.
 */
final class Console
{
    private const OPERATIONS = ['open' => Request\Open::class, 'join' => Request\Join::class, 'close' => Request\Close::class, 'fetch' => Request\Fetch::class, 'notify' => Request\Notify::class];

    private Gateways $gateways;
    private InMemorySignalStore $signals;

    private function __construct()
    {
        $this->signals = new InMemorySignalStore();
        $this->gateways = new Gateways($this->signals);
    }

    public static function create(): Application
    {
        $self = new self();
        $gateway = new InputArgument('gateway', InputArgument::REQUIRED, 'A configured gateway: direct, jitsi');
        $key = new InputArgument('key', InputArgument::REQUIRED, 'The meeting\'s key: the application\'s own name for it');
        $times = [
            new InputOption('title', null, InputOption::VALUE_REQUIRED, 'A title, for a gateway that shows one'),
            new InputOption('starts', null, InputOption::VALUE_REQUIRED, 'When it starts', 'now'),
            new InputOption('ends', null, InputOption::VALUE_REQUIRED, 'When it ends', '+30 minutes'),
        ];
        $app = new Application('omnimeet', '1.x');
        $app->addCommand($self->command('gateways', 'Which gateways are installed, configured, what their meetings are and what each does', [], fn ($in, $out) => $self->gateways($out)));
        $app->addCommand($self->command('open', 'Open a meeting: its reference on the gateway (JSON)', [$gateway, $key, ...$times], fn ($in, $out) => $self->open($in, $out)));
        $app->addCommand($self->command('join', 'How a participant enters a meeting: the engine and its options, the frame, or the address (JSON)', [
            $gateway, $key, ...$times,
            new InputOption('role', 'r', InputOption::VALUE_REQUIRED, 'host or guest', 'guest'),
            new InputOption('id', null, InputOption::VALUE_REQUIRED, 'The participant\'s opaque identifier', 'u1'),
            new InputOption('name', null, InputOption::VALUE_REQUIRED, 'The name shown to the others'),
            new InputOption('locale', 'l', InputOption::VALUE_REQUIRED, 'The language of the provider\'s interface'),
        ], fn ($in, $out) => $self->join($in, $out)));
        $app->addCommand($self->command('handshake', 'direct: a whole handshake between a host and a guest, message by message, then the room closed', [], fn ($in, $out) => $self->handshake($out)));
        $app->addCommand($self->command('notify', 'Read a provider\'s callback from a file, its signature checked (JSON)', [
            $gateway,
            new InputArgument('file', InputArgument::REQUIRED, 'The callback\'s body, as it was received ("-" for the standard input)'),
            new InputOption('signature', 's', InputOption::VALUE_REQUIRED, 'The X-Jaas-Signature header'),
            new InputOption('header', 'H', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Another header, "Name: value"'),
        ], fn ($in, $out) => $self->notify($in, $out)));

        return $app;
    }

    /** @param list<InputArgument|InputOption> $definition */
    private function command(string $name, string $description, array $definition, \Closure $code): Command
    {
        $command = new Command($name);
        $command->setDescription($description)->setDefinition($definition);
        $command->setCode(function (InputInterface $in, OutputInterface $out) use ($code): int {
            try {
                return $code($in, $out) ?? Command::SUCCESS;
            } catch (InvalidConfigException|\InvalidArgumentException|\ValueError $e) {
                $out->writeln('<error>'.$e->getMessage().'</error>');

                return Command::INVALID;
            } catch (OmnimeetException $e) {
                $out->writeln('<error>'.$e::class.': '.$e->getMessage().'</error>');

                return Command::FAILURE;
            }
        });

        return $command;
    }

    private function gateways(OutputInterface $out): void
    {
        $table = new Table($out);
        $table->setHeaders(['Gateway', 'Factory', 'Installed', 'Configured', 'People', 'Third party', 'End to end', 'Tokens', 'Recording', 'Lets in by', 'Does']);
        foreach ($this->gateways->config as $name => $gateway) {
            $installed = isset($this->gateways->factories[$gateway['factory']]);
            $missing = $this->gateways->missing($gateway);
            $row = [$name, $gateway['factory'], $installed ? '<info>yes</info>' : '<comment>no</comment>', $missing ? '<comment>needs '.implode(', ', $missing).'</comment>' : ($installed ? '<info>yes</info>' : '')];
            if ($installed && !$missing) {
                $built = $this->gateways->registry->get($name);
                $c = $built->capabilities();
                $yes = static fn (bool $v): string => $v ? 'yes' : 'no';
                $row = [...$row, $c->maxParticipants ?? 'several', $yes($c->thirdParty), $yes($c->endToEnd), $yes($c->tokens), $yes($c->recording), implode(', ', array_map(static fn (AccessKind $k) => $k->value, $c->access)), implode(' ', array_keys(array_filter(self::OPERATIONS, static fn (string $request) => $built->supports($request))))];
            }
            $table->addRow($row);
        }
        $table->render();
        $out->writeln('Factories installed: '.(implode(', ', array_keys($this->gateways->factories)) ?: 'none'));
    }

    private function meeting(InputInterface $in): Meeting
    {
        return new Meeting((string) $in->getArgument('key'), new \DateTimeImmutable((string) $in->getOption('starts')), new \DateTimeImmutable((string) $in->getOption('ends')), $in->getOption('title'));
    }

    private function open(InputInterface $in, OutputInterface $out): void
    {
        $meeting = $this->gateways->registry->get((string) $in->getArgument('gateway'))->open($this->meeting($in));
        $out->writeln(self::json(['key' => $meeting->key, 'reference' => $meeting->reference, 'status' => $meeting->status->value, 'starts' => $meeting->startsAt?->format(\DATE_ATOM), 'ends' => $meeting->endsAt?->format(\DATE_ATOM)]));
    }

    private function join(InputInterface $in, OutputInterface $out): void
    {
        $gateway = $this->gateways->registry->get((string) $in->getArgument('gateway'));
        $meeting = $gateway->open($this->meeting($in));
        $access = $gateway->join($meeting, new Participant((string) $in->getOption('id'), Role::from((string) $in->getOption('role')), $in->getOption('name'), $in->getOption('locale')));
        $shown = [
            'reference' => $meeting->reference,
            'kind' => $access->kind->value,
            'url' => $access->url,
            'engine' => $access->engine,
            'script' => $access->script,
            'options' => $access->options,
            'origins' => $access->origins,
            'permissions' => $access->permissions,
            'expires' => $access->expiresAt?->format(\DATE_ATOM),
        ];
        if (\is_string($access->options['jwt'] ?? null)) {
            // What the token says, for whoever reads it here: the instance alone checks its signature.
            [$header, $claims] = array_map(static fn (string $part) => json_decode((string) base64_decode(strtr($part, '-_', '+/')), true), \array_slice(explode('.', $access->options['jwt']), 0, 2));
            $shown['token'] = ['header' => $header, 'claims' => $claims];
        }
        $out->writeln(self::json($shown));
    }

    private function handshake(OutputInterface $out): int
    {
        if (!class_exists(DirectGatewayFactory::class) || !$this->gateways->registry->has('direct')) {
            $out->writeln('<comment>omnimeet/direct is not installed.</comment>');

            return Command::FAILURE;
        }
        $gateway = $this->gateways->registry->get('direct');
        $signaling = (new DirectGatewayFactory($this->signals))->signaling();
        $meeting = $gateway->open(new Meeting('handshake-'.bin2hex(random_bytes(6))));
        $room = $meeting->reference();
        $say = static function (string $who, string $type, mixed $payload = null) use ($signaling, $room, $out): void {
            $signal = $signaling->send($room, $who, $type, json_encode($payload));
            $out->writeln(\sprintf('  #%d  %-5s  %-9s %s', $signal->id, $who, $type, $signal->connects() ? '<info>connects</info>' : ''));
        };
        $hear = static function (string $who, int $after = 0) use ($signaling, $room, $out): int {
            $heard = $signaling->receive($room, $who, $after);
            $out->writeln(\sprintf('       %-5s  hears     %s', $who, implode(', ', array_map(static fn (array $s) => '#'.$s['id'].' '.$s['type'], $heard)) ?: 'nothing'));

            return $heard ? end($heard)['id'] : $after;
        };
        $state = function () use ($gateway, $meeting, $out): void {
            $now = $gateway->fetch($meeting);
            $out->writeln(\sprintf('       <comment>%s</comment>, present: %s', $now->status->value, implode(', ', array_map(static fn (Participant $p) => $p->id, $now->present)) ?: 'nobody'));
        };

        $out->writeln('Room '.$room.': the guest arrives first, the host offers, the guest answers.');
        $ice = $gateway->join($meeting, Participant::host('host'))->options['iceServers'];
        $out->writeln(\sprintf('       ICE servers given to each: %s', $ice ? implode(', ', array_merge(...array_column($ice, 'urls'))) : 'none (a direct connection, or none)'));
        $say('guest', 'hello');
        $state();
        $host = $hear('host');
        $say('host', 'offer', ['type' => 'offer', 'sdp' => 'v=0']);
        $say('host', 'candidate', ['candidate' => 'candidate:1 1 udp 2122260223 192.0.2.1 54321 typ host']);
        $guest = $hear('guest');
        $say('guest', 'answer', ['type' => 'answer', 'sdp' => 'v=0']);
        $say('guest', 'candidate', ['candidate' => 'candidate:1 1 udp 2122260223 192.0.2.2 54322 typ host']);
        $host = $hear('host', $host);
        $state();
        $say('guest', 'bye');
        $hear('host', $host);
        $state();
        $closed = $gateway->close($meeting);
        $out->writeln(\sprintf('Closed: %s, %d message(s) left.', $closed->status->value, \count($this->signals->read($room))));

        return Command::SUCCESS;
    }

    private function notify(InputInterface $in, OutputInterface $out): void
    {
        $file = (string) $in->getArgument('file');
        $body = '-' === $file ? (string) stream_get_contents(\STDIN) : (is_file($file) ? (string) file_get_contents($file) : throw new \InvalidArgumentException(\sprintf('No file "%s".', $file)));
        $headers = null !== $in->getOption('signature') ? ['X-Jaas-Signature' => (string) $in->getOption('signature')] : [];
        foreach ((array) $in->getOption('header') as $header) {
            [$name, $value] = array_pad(explode(':', (string) $header, 2), 2, '');
            $headers[trim($name)] = trim($value);
        }
        $n = $this->gateways->registry->get((string) $in->getArgument('gateway'))->notify($body, $headers);
        $out->writeln(self::json([
            'event' => $n->event->value,
            'type' => $n->type,
            'reference' => $n->reference,
            'participant' => $n->participant ? ['id' => $n->participant->id, 'role' => $n->participant->role->value, 'name' => $n->participant->name] : null,
            'at' => $n->at?->format(\DATE_ATOM),
            'id' => $n->id,
        ]));
    }

    private static function json(mixed $data): string
    {
        return (string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
