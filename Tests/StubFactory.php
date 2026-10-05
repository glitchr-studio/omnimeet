<?php

namespace Omnimeet\Tests;

use Omnimeet\Action\ActionInterface;
use Omnimeet\Action\ApiAwareInterface;
use Omnimeet\Action\ApiAwareTrait;
use Omnimeet\Config;
use Omnimeet\Exception\InvalidNotificationException;
use Omnimeet\GatewayFactory;
use Omnimeet\Model\Access;
use Omnimeet\Model\AccessKind;
use Omnimeet\Model\Capabilities;
use Omnimeet\Model\Event;
use Omnimeet\Model\Notification;
use Omnimeet\Model\Status;
use Omnimeet\Request\Close;
use Omnimeet\Request\Fetch;
use Omnimeet\Request\Join;
use Omnimeet\Request\Notify;
use Omnimeet\Request\Open;
use Omnimeet\Request\Request;

/**
 * A conferencing service kept in memory, for the tests: its API is the
 * list of its rooms. "link: true" lets people in by an address instead of a
 * frame; "silent: true" leaves out what a gateway may not do (close, fetch,
 * notify).
 */
final class StubFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnimeet.factory_name' => 'stub',
            'omnimeet.factory_title' => 'Stub',
            'omnimeet.required_options' => ['host'],
            'link' => false,
            'silent' => false,
            'omnimeet.capabilities' => static fn (Config $c) => new Capabilities(maxParticipants: 50, thirdParty: true, tokens: true, access: [$c['link'] ? AccessKind::LINK : AccessKind::FRAME]),
            'omnimeet.api' => static fn (Config $c) => new StubApi((string) $c['host'], (bool) $c['link']),
            'omnimeet.action.open' => new StubAction(Open::class),
            'omnimeet.action.join' => new StubAction(Join::class),
            'omnimeet.action.close' => static fn (Config $c) => $c['silent'] ? null : new StubAction(Close::class),
            'omnimeet.action.fetch' => static fn (Config $c) => $c['silent'] ? null : new StubAction(Fetch::class),
            'omnimeet.action.notify' => static fn (Config $c) => $c['silent'] ? null : new StubAction(Notify::class),
            // Supports a request and never answers it: the gateway must say so.
            'omnimeet.action.mute' => new MuteAction(),
        ]);
    }
}

final class StubApi
{
    /** @var array<string, list<string>> who is in each open room */
    public array $rooms = [];

    public function __construct(public readonly string $host, public readonly bool $link)
    {
    }
}

final class StubAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<StubApi> */
    use ApiAwareTrait;

    /** @param class-string<Request> $request */
    public function __construct(private readonly string $request)
    {
        $this->apiClass = StubApi::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof $this->request;
    }

    public function execute(Request $request): void
    {
        if ($request instanceof Open) {
            $reference = 'room-'.substr(sha1($request->meeting->key), 0, 8);
            $this->api->rooms[$reference] ??= [];
            $request->setResult($request->meeting->with($reference, Status::OPEN));
        } elseif ($request instanceof Join) {
            $reference = $request->meeting->reference();
            $this->api->rooms[$reference][] = $request->participant->id;
            $url = \sprintf('https://%s/%s?as=%s', $this->api->host, $reference, $request->participant->id);
            $request->setResult($this->api->link ? Access::link($url) : Access::frame($url));
        } elseif ($request instanceof Close) {
            unset($this->api->rooms[$request->meeting->reference()]);
            $request->setResult($request->meeting->with(status: Status::ENDED, present: []));
        } elseif ($request instanceof Fetch) {
            $in = $this->api->rooms[$request->meeting->reference()] ?? null;
            $request->setResult($request->meeting->with(
                status: null === $in ? Status::ENDED : (\count($in) > 1 ? Status::LIVE : Status::OPEN),
                present: array_map(static fn (string $id) => new \Omnimeet\Model\Participant($id), $in ?? []),
            ));
        } elseif ($request instanceof Notify) {
            if ('stub-signature' !== $request->header('X-Stub-Signature')) {
                throw new InvalidNotificationException('stub', 'The signature does not hold.');
            }
            $event = json_decode($request->body, true);
            $request->setResult(new Notification(Event::from($event['event']), $event['event'], $event['room'] ?? null, data: $event));
        }
    }
}

final class Mute extends Request
{
}

final class MuteAction implements ActionInterface
{
    public function supports(Request $request): bool
    {
        return $request instanceof Mute;
    }

    public function execute(Request $request): void
    {
    }
}
