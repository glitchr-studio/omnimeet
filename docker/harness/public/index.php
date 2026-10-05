<?php

/**
 * The demo: Omnimeet in plain PHP, behind PHP's own web server - no
 * framework, no bundle. One page per participant, opened in two tabs (or
 * two browsers): they meet through the gateway chosen.
 *
 *   docker compose up        # http://localhost:8799/
 *
 * What an application does around a gateway is all here, in small: open the
 * meeting, let a participant in and render the Access it is given, serve
 * the gateway's engine, relay the handshake (direct), say who is there,
 * close. It checks nobody: whoever has the address is in. An application
 * decides who may enter before it asks the gateway.
 */

use Omnimeet\Direct\DirectGatewayFactory;
use Omnimeet\Direct\Exception\InvalidSignalException;
use Omnimeet\Exception\OmnimeetException;
use Omnimeet\Harness\FileSignalStore;
use Omnimeet\Harness\Gateways;
use Omnimeet\Model\AccessKind;
use Omnimeet\Model\Meeting;
use Omnimeet\Model\Participant;
use Omnimeet\Model\Role;
use Omnimeet\Request\Close;

require getenv('OMNIMEET_AUTOLOAD') ?: '/harness/vendor/autoload.php';
foreach (['FileSignalStore', 'Gateways'] as $class) {
    class_exists('Omnimeet\\Harness\\'.$class) || require __DIR__.'/../src/'.$class.'.php';
}

$data = (getenv('OMNIMEET_DEMO_DIR') ?: sys_get_temp_dir().'/omnimeet-demo');
$store = class_exists(DirectGatewayFactory::class) ? new FileSignalStore($data.'/signals') : null;
$gateways = new Gateways($store);
$registry = $gateways->registry;

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$e = static fn (?string $text): string => htmlspecialchars((string) $text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
$json = static function (mixed $body, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body);
    exit;
};

// The meeting of this request: a gateway, a key, a side.
$name = (string) ($_GET['gateway'] ?? 'direct');
$key = (string) ($_GET['key'] ?? '');
$role = Role::tryFrom((string) ($_GET['as'] ?? '')) ?? Role::GUEST;
$valid = $registry->has($name) && preg_match('~^[A-Za-z0-9_-]{8,64}$~', $key);
$query = http_build_query(['gateway' => $name, 'key' => $key, 'as' => $role->value]);
// Who was there in the last 20 seconds, and whether the host ended it: the demo's whole "room".
$room = static function (string $key, ?\Closure $change = null) use ($data): array {
    is_dir($data.'/rooms') || mkdir($data.'/rooms', 0o700, true);
    $handle = fopen($data.'/rooms/'.hash('sha256', $key).'.json', 'c+');
    flock($handle, \LOCK_EX);
    $state = json_decode((string) stream_get_contents($handle), true) ?: ['host' => 0, 'guest' => 0, 'ended' => false];
    if ($change) {
        $change($state);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($state));
    }
    flock($handle, \LOCK_UN);
    fclose($handle);

    return $state;
};

try {
    // The gateways' scripts, served from their packages.
    if (preg_match('~^/engine/([a-z]+)\.js$~', $path, $m)) {
        $scripts = array_filter(['direct' => class_exists(Omnimeet\Direct\Api::class) ? Omnimeet\Direct\Api::script() : null, 'jitsi' => class_exists(Omnimeet\Jitsi\Api::class) ? Omnimeet\Jitsi\Api::script() : null]);
        if (!isset($scripts[$m[1]])) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: text/javascript; charset=utf-8');
        readfile($scripts[$m[1]]);
        exit;
    }

    if ('/signal' === $path && $valid) {
        $state = $room($key);
        if ($state['ended']) {
            $json(['signals' => [], 'host' => false, 'guest' => false, 'open' => false, 'ended' => true]);
        }
        if ('POST' === $method) {
            if (null === $store) {
                $json(['error' => 'omnimeet/direct is not installed'], 404);
            }
            $body = json_decode((string) file_get_contents('php://input'), true);
            if (!\is_array($body) || !isset($body['type'])) {
                $json(['error' => 'bad_request'], 400);
            }
            try {
                $signal = (new DirectGatewayFactory($store))->signaling()->send($key, $role->value, (string) $body['type'], json_encode($body['payload'] ?? null));
            } catch (InvalidSignalException $exception) {
                $json(['error' => $exception->getMessage()], 400);
            }
            $json(['id' => $signal->id], 201);
        }
        // Asking is also saying "I am here".
        $state = $room($key, static function (array &$state) use ($role): void { $state[$role->value] = time(); });
        $json([
            'signals' => null === $store ? [] : (new DirectGatewayFactory($store))->signaling()->receive($key, $role->value, (int) ($_GET['after'] ?? 0)),
            'host' => $state['host'] >= time() - 20,
            'guest' => $state['guest'] >= time() - 20,
            'open' => true,
            'ended' => false,
        ]);
    }

    if ('/end' === $path && 'POST' === $method && $valid) {
        if (Role::HOST === $role) {
            $room($key, static function (array &$state): void { $state['ended'] = true; });
            $gateway = $registry->get($name);
            if ($gateway->supports(Close::class)) {
                $gateway->close($gateway->open(new Meeting($key)));
            }
        }
        $json(['ended' => true]);
    }

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $head = static fn (string $title): string => '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$title.'</title><style>
        body{font:16px/1.5 system-ui,sans-serif;margin:0;padding:1.5rem;max-width:60rem;color:#1b1f1d;background:#fff}
        h1{font-size:1.4rem;margin:0 0 .2rem} p{margin:.4rem 0} code{background:#eef1ef;padding:.1rem .3rem;border-radius:.2rem}
        a.button,button{font:inherit;padding:.5rem .9rem;border:1px solid #1b1f1d;border-radius:.3rem;background:#fff;color:inherit;cursor:pointer;text-decoration:none;display:inline-block}
        button[aria-pressed=true]{background:#1b1f1d;color:#fff} [hidden]{display:none!important}
        table{border-collapse:collapse;margin:.8rem 0} td,th{border:1px solid #c9cfcc;padding:.3rem .6rem;text-align:left}
        .stage{position:relative;background:#101413;aspect-ratio:16/9;max-height:70vh;border-radius:.4rem;overflow:hidden;margin:.6rem 0}
        .stage video.remote{width:100%;height:100%;object-fit:contain} .stage video.local{position:absolute;right:.6rem;bottom:.6rem;width:24%;border:2px solid #fff;border-radius:.3rem;transform:scaleX(-1)}
        .frame{height:70vh;border:1px solid #c9cfcc;border-radius:.4rem;overflow:hidden;margin:.6rem 0} .controls{display:flex;flex-wrap:wrap;gap:.5rem}
        ol.messages{list-style:none;padding:0;max-height:10rem;overflow:auto} .sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
        .status{font-weight:700} .note{color:#4d5753}
    </style></head><body>';

    if ('/meet' === $path && $valid) {
        $gateway = $registry->get($name);
        $meeting = $gateway->open(new Meeting($key, new DateTimeImmutable(), new DateTimeImmutable('+1 hour')));
        $access = $gateway->join($meeting, new Participant($role->value, $role, Role::HOST === $role ? 'Host' : 'Guest', 'en'));
        $ended = $room($key)['ended'];
        echo $head('Omnimeet demo - '.$e($gateway->getTitle())), '<h1>', $e($gateway->getTitle()), ', as the ', $role->value, '</h1>';
        echo '<p class="note">Meeting <code>', $e($key), '</code>, reference <code>', $e($meeting->reference), '</code>. Open <a href="/meet?', $e(http_build_query(['gateway' => $name, 'key' => $key, 'as' => Role::HOST === $role ? 'guest' : 'host'])), '">the other side</a> in another tab or browser.</p>';
        if ($ended) {
            echo '<p class="status">This meeting is over.</p>';
        } elseif (AccessKind::ENGINE === $access->kind) {
            // omnimeet/direct's markup: the root and what the engine drives inside it.
            echo '<section data-omnimeet-direct data-role="', $e($access->options['role']), '" data-ice-servers="', $e(json_encode($access->options['iceServers'])), '" data-signal-url="/signal?', $e($query), '" data-end-url="/end?', $e($query), '">',
                '<p class="status" data-visio-status role="status">Checking the camera and the microphone...</p>',
                '<div class="stage"><video class="remote" data-visio-remote autoplay playsinline></video><video class="local" data-visio-local autoplay playsinline muted></video></div>',
                '<div class="controls"><button type="button" data-visio-join hidden>', Role::HOST === $role ? 'Start' : 'Enter the waiting room', '</button><button type="button" data-visio-mic aria-pressed="false" hidden>Mute</button><button type="button" data-visio-camera aria-pressed="false" hidden>Camera off</button><button type="button" data-visio-screen aria-pressed="false" hidden>Share the screen</button><button type="button" data-visio-hangup hidden>', Role::HOST === $role ? 'End' : 'Leave', '</button></div>',
                '<section data-visio-chat hidden><ol class="messages" data-visio-messages></ol><form data-visio-chat-form><input type="text" maxlength="1000" autocomplete="off" data-visio-input aria-label="Message"> <button type="submit">Send</button></form></section>',
                '<script type="application/json" data-visio-texts>', json_encode(['check' => 'Checking the camera and the microphone...', 'ready' => 'Camera and microphone ready.', 'denied' => 'No camera or microphone: allow them, then reload.', 'waiting_host' => 'In the waiting room: the call starts when the host comes.', 'waiting_guest' => 'Waiting for the guest.', 'guest_here' => 'The guest is in the waiting room - connecting...', 'connecting' => 'Connecting...', 'connected' => 'Connected.', 'lost' => 'Connection lost, trying again...', 'ended' => 'This meeting is over.', 'you' => 'You'], \JSON_HEX_TAG), '</script>',
                '</section><script src="/engine/', $e($access->engine), '.js" defer></script>';
        } elseif (AccessKind::FRAME === $access->kind && null !== $access->engine) {
            // A frame driven by the gateway's script: nothing is asked of the third party before "Enter".
            echo '<section data-omnimeet-jitsi data-options="', $e(json_encode($access->options)), '">',
                '<p class="note">This meeting goes through <strong>', $e(implode(', ', $access->origins)), '</strong>, a third party', $access->expiresAt ? ' (the token lapses at '.$e($access->expiresAt->format('H:i')).')' : '', '. Nothing is loaded from it before you enter.</p>',
                '<p class="status" data-status role="status">Not entered.</p>',
                '<div class="controls"><button type="button" data-enter>Enter</button><button type="button" data-leave hidden>Leave</button>', Role::HOST === $role ? '<button type="button" data-end hidden>End for everyone</button>' : '', '</div>',
                '<div class="frame" data-jitsi-frame></div></section>',
                '<script src="/engine/', $e($access->engine), '.js"></script>',
                '<script>(function(){var root=document.querySelector("[data-omnimeet-jitsi]"),q=function(s){return root.querySelector(s)};
                    q("[data-enter]").onclick=function(){root.omnimeet.join()};q("[data-leave]").onclick=function(){root.omnimeet.leave()};
                    if(q("[data-end]"))q("[data-end]").onclick=function(){root.omnimeet.end();fetch("/end?', $e($query), '",{method:"POST"})};
                    root.addEventListener("omnimeet:state",function(ev){q("[data-status]").textContent=ev.detail.state+(ev.detail.message?": "+ev.detail.message:"");var inCall=ev.detail.state==="joined"||ev.detail.state==="loading";q("[data-enter]").hidden=inCall;q("[data-leave]").hidden=!inCall;if(q("[data-end]"))q("[data-end]").hidden=!inCall});
                    setInterval(function(){fetch("/signal?', $e($query), '").then(function(r){return r.json()}).then(function(d){if(d.ended){root.omnimeet.dispose();q("[data-status]").textContent="This meeting is over.";}})},5000);
                })();</script>';
        } else {
            echo '<p><a class="button" href="', $e($access->url), '" rel="noopener noreferrer" target="_blank">Go to the meeting</a> on ', $e(implode(', ', $access->origins)), '</p>';
        }
        echo '<p><a href="/">Another meeting</a></p></body></html>';
        exit;
    }

    // The home page: the gateways, and a new meeting on each.
    $fresh = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    echo $head('Omnimeet demo'), '<h1>Omnimeet, in plain PHP</h1><p class="note">PHP\'s own web server, the registry built by hand: no framework is loaded. Open a meeting as the host in this tab, as the guest in another.</p><table><tr><th>Gateway</th><th>Its meetings</th><th>A new meeting</th></tr>';
    foreach ($gateways->config as $gatewayName => $gateway) {
        echo '<tr><td><strong>', $e($gatewayName), '</strong></td>';
        if (!$registry->has($gatewayName)) {
            echo '<td colspan="2" class="note">', isset($gateways->factories[$gateway['factory']]) ? 'needs '.$e(implode(', ', $gateways->missing($gateway))).' in docker/.env' : 'omnimeet/'.$e($gateway['factory']).' is not installed', '</td></tr>';
            continue;
        }
        $c = $registry->get($gatewayName)->capabilities();
        echo '<td>', null === $c->maxParticipants ? 'several people' : $c->maxParticipants.' people', ', ', $c->thirdParty ? 'through a third party' : 'no third party', ', ', $c->endToEnd ? 'end to end' : 'not end to end', '</td>';
        echo '<td><a class="button" href="/meet?', $e(http_build_query(['gateway' => $gatewayName, 'key' => $fresh, 'as' => 'host'])), '">Host</a> <a class="button" href="/meet?', $e(http_build_query(['gateway' => $gatewayName, 'key' => $fresh, 'as' => 'guest'])), '">Guest</a></td></tr>';
    }
    echo '</table><p class="note">The direct gateway relays its handshake through <code>/signal</code> into a file per room (<code>Omnimeet\Harness\FileSignalStore</code>); the media go from one browser to the other.</p></body></html>';
} catch (OmnimeetException $exception) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $exception::class, ': ', $exception->getMessage(), "\n";
}
