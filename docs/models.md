---
title: Models
order: 2
---

# Models

All models are `readonly`; times are `DateTimeImmutable`. They carry what a gateway needs and
nothing of the application: no account, no entity, no reason for the meeting.

## Meeting

| Property | |
|---|---|
| `key` | the application's own name for the meeting. It names the room on a provider: make it unguessable (a random token), not `appointment:12` |
| `startsAt`, `endsAt` | optional; a token lapses a while after the end |
| `title` | optional: shown by a provider that shows one. Leave it out and nothing says what the meeting is about |
| `reference` | the gateway's name for it, once opened: what the application keeps |
| `status` | `Status`: where it stands |
| `present` | `list<Participant>`: who is in, when the gateway can tell (`fetch()`) |

`with(reference, status, present)` gives the same meeting as its gateway has it now;
`reference()` throws `InvalidConfigException` on a meeting that was never opened.

## Participant

| Property | |
|---|---|
| `id` | opaque, stable for the meeting: `u42`, a hash - what the application finds its own account by. Never an e-mail |
| `role` | `Role::HOST` (holds the meeting: starts it, ends it, moderates where the gateway has moderators) or `Role::GUEST` |
| `name` | shown to the others; `null`: the gateway's default, or none |
| `locale` | the language of a provider's interface: `fr`, `de` |

`Participant::host($id, $name, $locale)`, `Participant::guest(...)`.

## Access

How one participant enters one meeting: what `join()` answers and the page renders. Made for
that person at that moment; asked again each time, never stored.

| Property | |
|---|---|
| `kind` | `AccessKind::ENGINE`, `FRAME` or `LINK` |
| `engine`, `script` | the name of the gateway's script (`direct`, `jitsi`) and its file inside the package: the application serves it |
| `options` | what the script starts with: a JSON document |
| `url` | `FRAME`: the address to frame; `LINK`: where to send the participant |
| `origins` | the third parties the participant's browser will talk to (`https://meet.example.org`): empty when the call stays between the participants and the application |
| `permissions` | what the page or the frame needs from the browser: `camera`, `microphone`, `display-capture`; `allow()` gives a frame's `allow` attribute |
| `expiresAt` | when its credentials lapse; `isExpired()` |

`isThirdParty()` is the question to ask before loading anything: name the third party to the
visitor, and wait for their consent.

## Capabilities

What a gateway's meetings are, said before one is opened (`$gateway->capabilities()`).

| Property | direct | jitsi |
|---|---|---|
| `maxParticipants` | 2 | `null`: the instance's own limit |
| `thirdParty` | no | yes (unless the instance is yours) |
| `endToEnd` | yes | no: the instance's bridge carries the media |
| `recording` | no | when `features.recording` is on (JaaS) |
| `screenSharing`, `chat` | yes | yes |
| `tokens` | no | when the instance checks one |
| `access` | `[ENGINE]` | `[FRAME, LINK]` |

`takes($n)`, `letsInBy(AccessKind)`, `toArray()`. The operations a gateway answers are asked of
the gateway: `supports(Close::class)`.

## Status

`PLANNED` (not opened on a gateway), `OPEN` (opened; nobody talking yet, as far as the gateway
knows), `LIVE` (at least two connected), `ENDED`, `UNKNOWN`. `admits()`: whether someone may
still be let in; `isOver()`.

## Notification

A provider's callback, read as one event about one meeting, its signature checked
(`notify($body, $headers)`).

| Property | |
|---|---|
| `event` | `Event`: `OPENED`, `JOINED`, `LEFT`, `ENDED`, `RECORDING_STARTED`, `RECORDING_ENDED`, `RECORDING_READY`, `OTHER` |
| `type` | the provider's own name for it |
| `reference` | the meeting, as `open()` named it |
| `participant` | who, for `JOINED` and `LEFT`: the identifier the application gave |
| `at`, `id` | when; and what a callback sent twice is recognised by |
| `data` | the provider's payload |
