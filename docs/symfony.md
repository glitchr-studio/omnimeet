---
title: Symfony
order: 4
---

# Symfony

Omnimeet runs without a framework ([installation](installation.md)); in a Symfony application its
bundle does the wiring. Its components - `symfony/config`, `symfony/dependency-injection`,
`symfony/http-kernel` - are not required by `glitchr/omnimeet`: the application has them, and
nothing of them is loaded outside Symfony.

Register `Omnimeet\Bridge\Symfony\OmnimeetBundle` (no Flex recipe):

```php
// config/bundles.php
return [
    // ...
    Omnimeet\Bridge\Symfony\OmnimeetBundle::class => ['all' => true],
];
```

```yaml
# config/packages/omnimeet.yaml
omnimeet:
    gateways:                      # by name: a factory and its options
        direct:
            factory: direct
            options:
                turn_secret: '%env(default::TURN_SECRET)%'
                turn_urls: '%env(default::TURN_URLS)%'       # one variable, comma-separated
        group:
            factory: jitsi
            options:
                domain: '%env(JITSI_DOMAIN)%'
                app_id: '%env(default::JITSI_APP_ID)%'
                app_secret: '%env(default::JITSI_APP_SECRET)%'
```

Every `omnimeet/*` package installed registers its factory, **autowired**: what its constructor
asks for is the application's service of that type. `omnimeet/direct` asks for a
`SignalStoreInterface` - alias it to your store:

```php
#[AsAlias(SignalStoreInterface::class)]
final class SignalRepository extends ServiceEntityRepository implements SignalStoreInterface { /* ... */ }
```

What is autowired:

| Service | |
|---|---|
| `GatewayInterface $direct`, `GatewayInterface $group` | one gateway by the argument's name (the configured name) |
| `Registry` | every configured gateway by name (`get()`, `has()`, `names()`, `options()`, `create()`) |

Nothing is built when the container compiles: a gateway is built the first time it is asked
for, and an option left empty only shows then (`InvalidConfigException`).

An application's own gateway - a class implementing `GatewayFactoryInterface` - is registered
too, autoconfigured, and can be named as a `factory`.

```php
final class MeetingController extends AbstractController
{
    #[Route('/meetings/{token}', name: 'app_meeting')]
    #[IsGranted('MEETING_ENTER', 'room')]                       // who may enter is the application's to say
    public function __invoke(Room $room, Registry $gateways): Response
    {
        $gateway = $gateways->get($room->getGateway());
        $access = $gateway->join(
            new Meeting($room->getToken(), $room->getStartsAt(), $room->getEndsAt(), reference: $room->getReference()),
            new Participant('u'.$this->getUser()->getId(), $room->isHost($this->getUser()) ? Role::HOST : Role::GUEST),
        );

        return $this->render('meeting/room.html.twig', ['room' => $room, 'access' => $access]);
    }
}
```

[`omnibase/office`](https://github.com/glitchr-studio/omnibase-office) does all of this for a
professional practice: its `Visio\Rooms` opens a room on `office.visio.gateway`, its page renders
the `Access`, its `SignalRepository` is the direct gateway's store.
