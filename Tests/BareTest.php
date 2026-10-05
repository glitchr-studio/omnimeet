<?php

namespace Omnimeet\Tests;

use Omnimeet\Bridge\Symfony\OmnimeetBundle;
use Omnimeet\Direct\DirectGatewayFactory;
use Omnimeet\Jitsi\JitsiGatewayFactory;
use Omnimeet\Registry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Omnimeet outside Symfony: the harness's bare script (docker/harness/bin/bare)
 * run in a PHP process of its own - this one has loaded the bundle's tests -
 * builds the registry by hand, holds a meeting on each gateway installed
 * (a whole handshake on omnimeet/direct, a frame and its token on
 * omnimeet/jitsi), and reports every class and file PHP loaded on the way.
 * None may be a framework's - and, since no gateway calls anything, none
 * may be Symfony's at all.
 */
final class BareTest extends TestCase
{
    private const FRAMEWORK = '~^(?:Symfony\\\\Component\\\\(?:DependencyInjection|Config|HttpKernel|HttpFoundation)|Symfony\\\\Bundle|Doctrine|Twig)\\\\~';
    private const FRAMEWORK_FILES = '~/vendor/(?:symfony/(?:dependency-injection|config|http-kernel|http-foundation|[a-z-]*bundle)|doctrine|twig)/~';

    public function testTheRegistryIsBuiltByHandAndNoClassOfAFrameworkIsLoaded(): void
    {
        [$status, $report] = self::php([__DIR__.'/../docker/harness/bin/bare', '--json']);

        self::assertSame(0, $status);
        self::assertContains(Registry::class, $report['symbols'], 'the registry was built there');
        self::assertSame([], self::framework($report), 'no class nor file of a framework');
        self::assertSame([], array_values(preg_grep('~^Symfony\\\\(?!Polyfill)~', $report['symbols'])), 'nothing of Symfony at all: no gateway calls anything');
        $installed = array_values(array_filter(array_column(require __DIR__.'/../docker/harness/plugins.php', 1), 'class_exists'));
        foreach ($installed as $factory) {
            self::assertContains($factory, $report['symbols'], 'every gateway package installed, its factory built');
        }
        self::assertCount(\count($installed), $report['gateways']);
    }

    public function testAMeetingIsHeldFromBrowserToBrowserWithNoClassOfAFrameworkLoaded(): void
    {
        if (!class_exists(DirectGatewayFactory::class)) {
            self::markTestSkipped('omnimeet/direct is not installed.');
        }
        [$status, $report] = self::php([__DIR__.'/../docker/harness/bin/bare', '--json'], ['TURN_SECRET' => '', 'TURN_URLS' => '', 'STUN_URLS' => '']);

        self::assertSame(0, $status);
        self::assertSame(['open', 'join', 'close', 'fetch'], $report['gateways']['direct']['does'], 'no callbacks: nobody to send any');
        self::assertSame([2, false, true, false], [$report['gateways']['direct']['maxParticipants'], $report['gateways']['direct']['thirdParty'], $report['gateways']['direct']['endToEnd'], $report['gateways']['direct']['recording']]);
        self::assertSame(['kind' => 'engine', 'engine' => 'direct', 'script' => 'visio.js', 'role' => 'host', 'ice_servers' => 0, 'third_party' => false], $report['direct']['access'], 'the engine\'s file is in the package; no relay configured, no STUN of someone else\'s');
        self::assertSame('guest', $report['direct']['guest_role']);
        self::assertSame(['hello'], $report['direct']['host_heard'], 'one does not hear oneself');
        self::assertSame(['offer', 'candidate'], $report['direct']['guest_heard'], 'in the order they were sent');
        self::assertTrue($report['direct']['connects']);
        self::assertSame(['live', ['u2', 'u1']], [$report['direct']['status'], $report['direct']['present']]);
        self::assertSame(['ended', 0], [$report['direct']['closed'], $report['direct']['left_in_store']], 'closing purges the handshake');
        self::assertSame([], self::framework($report), 'no class nor file of a framework');
    }

    public function testAFrameAndItsTokenAreMadeWithNoClassOfAFrameworkLoaded(): void
    {
        if (!class_exists(JitsiGatewayFactory::class)) {
            self::markTestSkipped('omnimeet/jitsi is not installed.');
        }
        [$status, $report] = self::php([__DIR__.'/../docker/harness/bin/bare', '--json'], ['JITSI_DOMAIN' => '']);

        self::assertSame(0, $status);
        self::assertTrue($report['gateways']['jitsi']['example'], 'no instance in the environment: the example one, which nothing calls');
        self::assertSame(['open', 'join'], $report['gateways']['jitsi']['does'], 'what Jitsi\'s documentation does not show is not done');
        self::assertTrue($report['gateways']['jitsi']['thirdParty']);
        self::assertMatchesRegularExpression('~^[0-9a-f]{32}$~', $report['jitsi']['reference']);
        self::assertTrue($report['jitsi']['same_again'], 'the same key, the same room');
        self::assertSame('https://meet.example.org/'.$report['jitsi']['reference'], $report['jitsi']['access']['url']);
        self::assertSame(['frame', 'jitsi', 'jitsi.js', 'https://meet.example.org/external_api.js', ['https://meet.example.org'], true], [$report['jitsi']['access']['kind'], $report['jitsi']['access']['engine'], $report['jitsi']['access']['script'], $report['jitsi']['access']['api'], $report['jitsi']['access']['origins'], $report['jitsi']['access']['third_party']]);
        self::assertSame(['room' => $report['jitsi']['reference'], 'user' => ['id' => 'u1', 'name' => 'Dr Claire Tilleul'], 'expires_after_the_end' => true], $report['jitsi']['token']);
        self::assertSame([], self::framework($report), 'no class nor file of a framework');
    }

    /** The check is not blind: the same report, once the bundle is loaded, names the framework. */
    public function testTheBundleDoesLoadTheFramework(): void
    {
        if (!class_exists(AbstractBundle::class)) {
            self::markTestSkipped('symfony/http-kernel is not installed.');
        }
        [$status, $report] = self::php(['-r', 'require getenv("OMNIMEET_AUTOLOAD"); class_exists($argv[1]) || exit(2); echo json_encode(["symbols" => [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()], "files" => get_included_files()]);', '--', OmnimeetBundle::class]);

        self::assertSame(0, $status);
        $framework = self::framework($report);
        self::assertContains(AbstractBundle::class, $framework);
        self::assertNotEmpty(preg_grep('~/symfony/http-kernel/~', $framework));
    }

    /**
     * @param array{symbols: list<string>, files: list<string>} $report
     *
     * @return list<string> the classes, interfaces, traits and files of a framework among those loaded
     */
    private static function framework(array $report): array
    {
        return [...array_values(preg_grep(self::FRAMEWORK, $report['symbols'])), ...array_values(preg_grep(self::FRAMEWORK_FILES, $report['files']))];
    }

    /**
     * Runs PHP apart, on the autoloader of this run.
     *
     * @param list<string>          $arguments
     * @param array<string, string> $env
     *
     * @return array{int, array<string, mixed>} the exit status, the JSON printed
     */
    private static function php(array $arguments, array $env = []): array
    {
        $autoload = \dirname((string) (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2).'/autoload.php';
        $process = proc_open([\PHP_BINARY, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['OMNIMEET_AUTOLOAD' => $autoload] + getenv());
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        $status = proc_close($process);
        $report = json_decode($out, true);
        self::assertIsArray($report, 'PHP exited '.$status.': '.$err.$out);

        return [$status, $report];
    }
}
