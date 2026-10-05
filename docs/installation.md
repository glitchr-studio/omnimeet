---
title: Installation
order: 1
---

# Installation and a first meeting

```sh
composer require glitchr/omnimeet omnimeet/direct     # from browser to browser: no key, no third party
composer require omnimeet/jitsi                       # a Jitsi Meet instance
```

PHP 8.2 or later.

Omnimeet needs no framework, and no library either: the core requires PHP alone, and so do the two
gateway packages - neither calls anything (a direct meeting is carried by the two browsers, a Jitsi
room exists when its first participant comes in, a token is signed on this side). It runs the same
in plain PHP, in a worker, in Laravel or Slim, and in Symfony, where a bundle does the wiring
([Symfony](symfony.md)).

## Plain PHP

```php
<?php // meet.php

require __DIR__.'/vendor/autoload.php';

use Omnimeet\Direct\DirectGatewayFactory;
use Omnimeet\Direct\InMemorySignalStore;
use Omnimeet\Jitsi\JitsiGatewayFactory;
use Omnimeet\Model\AccessKind;
use Omnimeet\Model\Meeting;
use Omnimeet\Model\Participant;
use Omnimeet\Registry;

$registry = new Registry([new DirectGatewayFactory(new InMemorySignalStore()), new JitsiGatewayFactory()], [
    'direct' => ['factory' => 'direct', 'options' => ['turn_secret' => 'the-relay-s-secret', 'turn_urls' => ['turn:turn.example.org:3478']]],
    'group' => ['factory' => 'jitsi', 'options' => ['domain' => 'meet.example.org', 'app_id' => 'my_app', 'app_secret' => 'the-instance-s-secret']],
]);

// The application's meeting: its own key - unguessable -, when it starts and ends.
$planned = new Meeting(bin2hex(random_bytes(16)), new DateTimeImmutable('+5 minutes'), new DateTimeImmutable('+35 minutes'));

foreach ($registry->all() as $name => $gateway) {
    $capabilities = $gateway->capabilities();
    $meeting = $gateway->open($planned);                                  // $meeting->reference: what to keep
    $access = $gateway->join($meeting, Participant::host('u42', 'Dr Tilleul', 'fr'));

    printf("%s (%s): %s, %s\n", $name, $gateway->getTitle(), $capabilities->maxParticipants ? $capabilities->maxParticipants.' people' : 'several people', $capabilities->thirdParty ? 'through a third party' : 'no third party');
    printf("  reference %s\n", $meeting->reference);
    echo match ($access->kind) {
        AccessKind::ENGINE => sprintf("  run %s with %s; ICE servers: %s\n", basename($access->script), json_encode(array_diff_key($access->options, ['iceServers' => 1])), implode(', ', array_merge(...array_column($access->options['iceServers'], 'urls')))),
        AccessKind::FRAME => sprintf("  frame %s\n  (asks %s; the token lapses at %s)\n", strtok($access->url, '?'), implode(', ', $access->origins), $access->expiresAt?->format('H:i')),
        AccessKind::LINK => sprintf("  send them to %s\n", $access->url),
    };
}
```

```
$ php meet.php
direct (Direct): 2 people, no third party
  reference d9ab80f93063685d8b1b41eb74525232
  run visio.js with {"room":"d9ab80f93063685d8b1b41eb74525232","participant":"u42","role":"host"}; ICE servers: turn:turn.example.org:3478
group (Jitsi Meet): several people, through a third party
  reference f2d270427b62a8d67a39c90455382499
  frame https://meet.example.org/f2d270427b62a8d67a39c90455382499
  (asks https://meet.example.org; the token lapses at 06:43)
```

(as answered on 2026-10-05; nothing was called)

That is all there is to it:

- a **factory** per gateway package (`DirectGatewayFactory`, `JitsiGatewayFactory`), which takes
  what its gateway works with - for the direct one, the store its handshake waits in
  ([omnimeet/direct](https://github.com/glitchr-studio/omnimeet-direct));
- the **registry**, built by hand from the factories and the gateways' options, by name;
- the **gateways** it gives, each answering the same five questions: `open()`, `join()`,
  `close()`, `fetch()`, `notify()` ([gateways](gateways.md)).

No class of a framework is loaded on the way - a test of this package checks it in a process of
its own (`Tests/BareTest.php`), and so does `docker compose run --rm omnimeet bare`
([harness](harness.md)); `docker compose up` there serves a call between two tabs from PHP's own
web server.

## What the application does around a gateway

A gateway opens a room and lets someone in. Everything else is the application's:

1. **Decide** who may enter, and when - before asking. A gateway checks nobody.
2. **Open** the meeting once and **keep its reference** (`$meeting->reference`) with its own
   record of the meeting, and the gateway's name.
3. On the participant's page, **join** - each time: an `Access` is made for one person, now (a
   token, temporary credentials) - and **render** what comes back ([gateways](gateways.md#rendering-an-access)):
   an engine to run, a frame to show, or an address to send them to.
4. **Close** when it is over, where the gateway can (`supports(Close::class)`).

```php
use Omnimeet\Model\Meeting;
use Omnimeet\Model\Participant;
use Omnimeet\Request\Close;

$gateway = $registry->get('direct');

// once, when the meeting is planned
$meeting = $gateway->open(new Meeting($row->token, $row->startsAt, $row->endsAt));
$row->reference = $meeting->reference;

// on each participant's page, once the application let them through
$meeting = new Meeting($row->token, $row->startsAt, $row->endsAt, reference: $row->reference);
$access = $gateway->join($meeting, Participant::guest('u'.$user->id));

// when it is over
if ($gateway->supports(Close::class)) {
    $gateway->close($meeting);
}
```

What a gateway is told is what these models carry and no more: a key (make it unguessable - it
names the room on a provider), a start and an end, a title if you give one, and of each person a
role, an opaque identifier and a name if you give one. Never an account, never an e-mail.

## In a framework

- **Symfony**: `Omnimeet\Bridge\Symfony\OmnimeetBundle` registers the factories, builds the
  registry from `config/packages/omnimeet.yaml` and makes each gateway injectable by its name:
  see [Symfony](symfony.md). Its components (`symfony/config`, `symfony/dependency-injection`,
  `symfony/http-kernel`) are not required by this package: a Symfony application has them.
  [`omnibase/office`](https://github.com/glitchr-studio/omnibase-office) is built on it: its
  video room keeps the room, the waiting room and the pages, and asks a gateway for the rest.
- **Any other**: build the `Registry` once, where the framework builds its services (a service
  provider, a container definition), as the script above does.

## Errors

| Exception | When |
|---|---|
| `NotSupportedException` | the gateway does not do that: `supports()` says so beforehand |
| `InvalidConfigException` | a gateway not configured, a factory not installed, an option missing, a meeting not opened yet |
| `InvalidNotificationException` | a callback whose signature does not hold, or too old |
| `ProviderException` | the provider refused or could not be reached |

All implement `Omnimeet\Exception\OmnimeetException`.
