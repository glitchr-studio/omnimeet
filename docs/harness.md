---
title: The Docker harness
order: 5
---

# The Docker harness

`docker/` runs this package with every `omnimeet/*` gateway installed - from GitHub (branch 1.x),
or from the checkouts beside this one when `OMNIMEET_PLUGINS=../..` is set in `docker/.env` - with
a console, a script in bare PHP, and a demo where two browser tabs meet.

```sh
cd docker && cp .env.dist .env       # a relay, a Jitsi instance, when you have them
docker compose run --rm omnimeet gateways
```

| Command | |
|---|---|
| `gateways` | the gateways installed and configured, what their meetings are, what each does |
| `open <gateway> <key>` | the meeting's reference on that gateway (`--title`, `--starts`, `--ends`) |
| `join <gateway> <key>` | how a participant enters (JSON): `--role host\|guest`, `--id`, `--name`, `--locale`; a token is shown decoded |
| `handshake` | direct: a whole handshake between a host and a guest, message by message, then the room closed |
| `notify <gateway> <file>` | a provider's callback read from a file, its signature checked: `--signature "t=...,v1=..."` |
| `bare` | plain PHP: the registry built by hand, a meeting held on each gateway, what PHP loaded |
| `test` | every package's tests |
| `docker compose up` | the demo: http://localhost:8799/ |

The configured gateways are `direct` (nothing needed; `TURN_SECRET` and `TURN_URLS` for a relay)
and `jitsi` (when `JITSI_DOMAIN` is set; `JITSI_APP_ID` and `JITSI_APP_SECRET`, or
`JITSI_PRIVATE_KEY` and `JITSI_KEY_ID`, when the instance checks tokens; `JAAS_WEBHOOK_SECRET`).

```
$ docker compose run --rm omnimeet gateways
+---------+---------+-----------+--------------------+--------+-------------+------------+--------+-----------+------------+-----------------------+
| Gateway | Factory | Installed | Configured         | People | Third party | End to end | Tokens | Recording | Lets in by | Does                  |
+---------+---------+-----------+--------------------+--------+-------------+------------+--------+-----------+------------+-----------------------+
| direct  | direct  | yes       | yes                | 2      | no          | yes        | no     | no        | engine     | open join close fetch |
| jitsi   | jitsi   | yes       | needs JITSI_DOMAIN |        |             |            |        |           |            |                       |
+---------+---------+-----------+--------------------+--------+-------------+------------+--------+-----------+------------+-----------------------+

$ docker compose run --rm omnimeet handshake
Room handshake-3435a15e6532: the guest arrives first, the host offers, the guest answers.
       ICE servers given to each: none (a direct connection, or none)
  #1  guest  hello
       open, present: guest
       host   hears     #1 hello
  #2  host   offer
  #3  host   candidate
       guest  hears     #2 offer, #3 candidate
  #4  guest  answer    connects
  #5  guest  candidate
       host   hears     #4 answer, #5 candidate
       live, present: guest, host
  #6  guest  bye
       host   hears     #6 bye
       open, present: host
Closed: ended, 0 message(s) left.
```

## Bare: no bundle, no container

The console above is a `symfony/console` application over a registry built by hand; `bare` is
less still - one PHP script, `docker/harness/bin/bare`, that requires the autoloader and nothing
else. It builds the `Registry` from the gateway packages installed, asks each what its meetings
are and what it does, holds a meeting on each (a gateway whose settings are not in the
environment runs on example ones: nothing is called either way), then lists what PHP loaded and
exits 1 if a class of a framework is among it (`Symfony\Component\DependencyInjection`, `Config`,
`HttpKernel`, `HttpFoundation`, a bundle, Doctrine, Twig):

```
$ docker compose run --rm omnimeet bare
Omnimeet in bare PHP: the registry built by hand, no bundle, no container.

  direct   Direct: 2 people, no third party, end to end; lets in by engine; does open join close fetch
  jitsi    Jitsi Meet: several people, through a third party, not end to end; lets in by frame, link; does open join (example settings)

direct, the meeting "bare-meeting-a-key-nobody-guesses":
  the host is let in by the direct engine (visio.js), 0 ICE server(s), the guest as "guest"
  the host heard: hello; the guest heard: offer, candidate
  the answer connects them: live (present: u2, u1)
  closed: ended, 0 message(s) left in the store

jitsi, the same meeting:
  its room: 124fa45aeff281624eb50bbf2ec46352 (the same when opened again: yes)
  the host is let in by a frame on https://meet.example.org/124fa45aeff281624eb50bbf2ec46352, driven by jitsi.js through https://meet.example.org/external_api.js
  a token for the room 124fa45aeff281624eb50bbf2ec46352, for Dr Claire Tilleul (u1), valid until after the meeting's end: yes

Loaded from Symfony: nothing
Classes of a framework (DependencyInjection, Config, HttpKernel, HttpFoundation, a bundle, Doctrine, Twig): none
```

`bare --json` prints the same whole, every class and file loaded: `Tests/BareTest.php` runs it in
a process of its own and checks the list - nothing of Symfony at all is loaded, since no gateway
calls anything.

## The demo: a call between two tabs, in plain PHP

```sh
docker compose up        # http://localhost:8799/ (OMNIMEET_PORT)
```

`docker/harness/public/index.php` behind PHP's own web server - no framework. Pick a gateway,
open the meeting as the host in one tab and as the guest in another (or another browser): the
page opens the meeting, joins, renders the `Access` it is given, serves the gateway's script from
its package and, for the direct gateway, relays the handshake into a file per room
(`Omnimeet\Harness\FileSignalStore`, forty lines: what a `SignalStoreInterface` is). It is what
an application does around a gateway, in small - except that it checks nobody: whoever has the
address is in.

`localhost` is a secure context: the browser gives the camera without TLS.

The image is `php:8.4-cli-alpine` with Composer; the harness's packages live in the `harness`
volume of the `omnimeet-harness` project. The gateways are cloned from GitHub as plain git
repositories over HTTPS: no GitHub API, no ssh in the image.
