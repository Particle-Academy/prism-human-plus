<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Prism\HumanPlus\Contracts\RelayTransport;
use Prism\HumanPlus\Data\Participant;
use Prism\HumanPlus\Data\SurfaceAttachment;
use Prism\HumanPlus\Data\SurfaceInvitation;
use Prism\HumanPlus\Exceptions\HumanPlusException;
use Prism\HumanPlus\Transport\LongPollRelayTransport;
use Prism\HumanPlus\Transport\RelayEndpoint;
use Prism\HumanPlus\Transport\SsePostRelayTransport;

require_once __DIR__.'/../Support/RedirectLoopback.php';

/*
 * Relay redirects, against real loopback servers.
 *
 * A relay request follows a redirect only when the scheme, host and port stay
 * the same. Anything else is refused before a second request is made, so the
 * other server receives nothing at all. The client is a plain injected Guzzle
 * client with its own defaults, so the behaviour cannot depend on how the
 * application built it.
 */

const RELAY_REDIRECT_TOKEN = 'fake-relay-token-00000000000000000001';

beforeEach(function (): void {
    $this->relay = RedirectLoopback::start('127.0.0.1');
    $this->elsewhere = RedirectLoopback::start('127.0.0.2');
});

afterEach(function (): void {
    $this->relay->stop();
    $this->elsewhere->stop();
});

function redirectRelayAttachment(RedirectLoopback $relay): SurfaceAttachment
{
    return new SurfaceAttachment(
        'surface_1', 'session:one',
        new SurfaceInvitation($relay->origin().'/relay', 'demo_001', RELAY_REDIRECT_TOKEN, 'sheet:one', 'Demo', allowInsecureLoopback: true),
        new Participant('agent:one', 'One', '#000000'), 'php_worker_1',
    );
}

function redirectRelayTransport(string $kind, RedirectLoopback $relay, string $authMode): RelayTransport
{
    return match ($kind) {
        'sse' => new SsePostRelayTransport(new Client, ['127.0.0.1'], 5, allowedRelayPorts: [$relay->port], allowUnverifiedEgress: true, authMode: $authMode),
        'native' => new SsePostRelayTransport(new Client, ['127.0.0.1'], 5, allowedRelayPorts: [$relay->port], allowUnverifiedEgress: true, authMode: $authMode, useNativeCurl: true),
        'long-poll' => new LongPollRelayTransport(new Client, ['127.0.0.1'], 5, allowedRelayPorts: [$relay->port], allowUnverifiedEgress: true, authMode: $authMode, pollWaitSeconds: 1),
    };
}

/** @return array{0: ?HumanPlusException} the failure the call raised, if any */
function relayFailure(callable $call): array
{
    try {
        $call();
    } catch (HumanPlusException $e) {
        return [$e];
    }

    return [null];
}

it('does not follow a relay redirect to another host and port', function (string $kind, string $call, string $path, int $status, string $authMode): void {
    $this->relay->answer([['match' => $path, 'status' => $status, 'location' => $this->elsewhere->origin().'/landed']]);
    $transport = redirectRelayTransport($kind, $this->relay, $authMode);
    $attachment = redirectRelayAttachment($this->relay);
    $frame = ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list'];

    [$failure] = relayFailure(match ($call) {
        'notify' => fn () => $transport->notify($attachment, $frame),
        'exchange' => fn () => $transport->exchange($attachment, $frame),
        'detach' => fn () => $transport->detach($attachment),
    });

    expect($this->elsewhere->requests())->toBe([])
        ->and($this->elsewhere->received())->not->toContain(RELAY_REDIRECT_TOKEN)
        ->and($failure)->toBeInstanceOf(HumanPlusException::class)
        ->and($failure?->getMessage())->not->toContain(RELAY_REDIRECT_TOKEN);
})->with([
    'notify, bearer, 307' => ['sse', 'notify', '/inbox', 307, 'bearer'],
    'notify, query, 308' => ['sse', 'notify', '/inbox', 308, 'query'],
    'notify, bearer, 302' => ['sse', 'notify', '/inbox', 302, 'bearer'],
    'notify, bearer, 301' => ['sse', 'notify', '/inbox', 301, 'bearer'],
    'notify, bearer, 303' => ['sse', 'notify', '/inbox', 303, 'bearer'],
    'detach, bearer, 307' => ['sse', 'detach', '/unregister', 307, 'bearer'],
    'stream, bearer, 307' => ['sse', 'exchange', '/events', 307, 'bearer'],
    'stream, query, 302' => ['sse', 'exchange', '/events', 302, 'query'],
    'long poll, bearer, 307' => ['long-poll', 'exchange', '/outbox', 307, 'bearer'],
    'long poll inbox, query, 308' => ['long-poll', 'exchange', '/inbox', 308, 'query'],
    'native stream, bearer, 307' => ['native', 'exchange', '/events', 307, 'bearer'],
    'native inbox, query, 307' => ['native', 'exchange', '/inbox', 307, 'query'],
]);

it('does not follow a relay redirect to the same host on another port', function (): void {
    $other = RedirectLoopback::start('127.0.0.1');

    try {
        $this->relay->answer([['match' => '/inbox', 'status' => 307, 'location' => $other->origin().'/landed']]);

        [$failure] = relayFailure(fn () => redirectRelayTransport('sse', $this->relay, 'bearer')
            ->notify(redirectRelayAttachment($this->relay), ['jsonrpc' => '2.0', 'method' => 'ping']));

        expect($other->requests())->toBe([])
            ->and($failure)->toBeInstanceOf(HumanPlusException::class);
    } finally {
        $other->stop();
    }
});

it('does not follow a relay redirect that changes the scheme', function (): void {
    $this->relay->answer([['match' => '/inbox', 'status' => 308, 'location' => 'https://{host}/landed']]);

    [$failure] = relayFailure(fn () => redirectRelayTransport('sse', $this->relay, 'bearer')
        ->notify(redirectRelayAttachment($this->relay), ['jsonrpc' => '2.0', 'method' => 'ping']));

    expect($this->relay->requests())->toHaveCount(1)
        ->and($failure)->toBeInstanceOf(HumanPlusException::class)
        ->and($failure?->getMessage())->toContain('redirect');
});

it('follows a relay redirect that keeps the scheme, host and port', function (): void {
    $this->relay->answer([['match' => '/inbox', 'status' => 307, 'location' => '/relay/demo_001/landed']]);

    redirectRelayTransport('sse', $this->relay, 'bearer')
        ->notify(redirectRelayAttachment($this->relay), ['jsonrpc' => '2.0', 'method' => 'ping']);

    $requests = $this->relay->requests();

    expect($requests)->toHaveCount(2)
        ->and($requests[1]['uri'])->toBe('/relay/demo_001/landed')
        ->and($requests[1]['headers']['Authorization'] ?? null)->toBe('Bearer '.RELAY_REDIRECT_TOKEN)
        ->and($this->elsewhere->requests())->toBe([]);
});

/*
 * Hostile Location headers. Each one either names another origin, or is shaped
 * to make a careless same-origin check (a string prefix, the raw header, a
 * parser that disagrees with the client) think it names the relay. None may
 * reach the other server. Placeholders: {A} is the relay's host:port, {E} the
 * other server's, {AH}/{EH} the bare hosts and {AP}/{EP} the bare ports.
 */
const HOSTILE_RELAY_LOCATIONS = [
    'scheme-relative' => ['//{E}/landed'],
    'backslashes for slashes' => ['http:\\\\{E}/landed'],
    'relay as userinfo' => ['http://{A}@{E}/landed'],
    'relay host as userinfo' => ['http://{AH}@{E}/landed'],
    'fragment before @' => ['http://{E}#@{A}/landed'],
    'query before @' => ['http://{E}?@{A}/landed'],
    'encoded @' => ['http://{A}%40{E}/landed'],
    'upper-case scheme and host elsewhere' => ['HTTP://{E}/landed'],
    'explicit default port on the relay host' => ['http://{AH}:80/landed'],
    'zero-padded default port on the relay host' => ['http://{AH}:080/landed'],
    'trailing-dot relay host' => ['http://{AH}.:{AP}/landed'],
    'trailing-dot other host' => ['http://{EH}.:{EP}/landed'],
    'IPv6-mapped other host' => ['http://[::ffff:{EH}]:{EP}/landed'],
    'IPv6-mapped other host in hex' => ['http://[::ffff:7f00:2]:{EP}/landed'],
    'IPv6-mapped relay host' => ['http://[::ffff:{AH}]:{AP}/landed'],
    'https to the other host' => ['https://{E}/landed'],
    'https to the relay host' => ['https://{A}/landed'],
    'ftp' => ['ftp://{E}/landed'],
    'file' => ['file:///etc/passwd'],
    'malformed port' => ['http://{EH}:99999/landed'],
    'non-numeric port' => ['http://{EH}:x{EP}/landed'],
    'space in authority' => ['http://{EH} :{EP}/landed'],
    'unclosed IPv6 bracket' => ['http://[::1:{EP}/landed'],
];

function hostileLocation(string $location, RedirectLoopback $relay, RedirectLoopback $elsewhere): string
{
    return strtr($location, [
        '{A}' => "{$relay->host}:{$relay->port}",
        '{E}' => "{$elsewhere->host}:{$elsewhere->port}",
        '{AH}' => $relay->host,
        '{EH}' => $elsewhere->host,
        '{AP}' => (string) $relay->port,
        '{EP}' => (string) $elsewhere->port,
    ]);
}

it('refuses a hostile relay redirect before any further request', function (string $call, string $location): void {
    $path = $call === 'notify' ? '/inbox' : '/events';
    $this->relay->answer([['match' => $path, 'status' => 307, 'location' => hostileLocation($location, $this->relay, $this->elsewhere)]]);
    $transport = redirectRelayTransport('sse', $this->relay, 'bearer');
    $attachment = redirectRelayAttachment($this->relay);
    $frame = ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list'];

    [$failure] = relayFailure($call === 'notify'
        ? fn () => $transport->notify($attachment, $frame)
        : fn () => $transport->exchange($attachment, $frame));

    // The stream also posts the frame, so the relay can see more than one
    // request; what it must never see is the redirect being followed.
    $relayPaths = array_map(fn (array $request): string => (string) parse_url($request['uri'], PHP_URL_PATH), $this->relay->requests());

    expect($this->elsewhere->requests())->toBe([])
        ->and($relayPaths)->not->toContain('/landed')
        ->and($failure)->toBeInstanceOf(HumanPlusException::class)
        ->and($failure?->getMessage())->not->toContain(RELAY_REDIRECT_TOKEN);
})->with(['notify', 'stream'])->with(HOSTILE_RELAY_LOCATIONS);

/*
 * These name the other server only in a part of the URL that is not the host:
 * the userinfo, or (for `/\other`) a path that some client versions reject and
 * newer ones resolve on the relay. Whether the client refuses them or follows
 * them to the relay depends on its version; reaching the other server never
 * happens.
 */
it('never sends a redirect to a host named outside the authority', function (string $location): void {
    $this->relay->answer([['match' => '/inbox', 'status' => 307, 'location' => hostileLocation($location, $this->relay, $this->elsewhere)]]);

    relayFailure(fn () => redirectRelayTransport('sse', $this->relay, 'bearer')
        ->notify(redirectRelayAttachment($this->relay), ['jsonrpc' => '2.0', 'method' => 'ping']));

    expect($this->elsewhere->requests())->toBe([])
        ->and($this->elsewhere->received())->not->toContain(RELAY_REDIRECT_TOKEN);
})->with([
    'backslash before @' => ['http://{E}\@{A}/landed'],
    'encoded # before @' => ['http://{E}%23@{A}/landed'],
    'backslash path' => ['/\\{E}/landed'],
]);

it('follows a same-origin relay redirect however it is spelled', function (string $location): void {
    $this->relay->answer([['match' => '/inbox', 'status' => 307, 'location' => hostileLocation($location, $this->relay, $this->elsewhere)]]);

    redirectRelayTransport('sse', $this->relay, 'bearer')
        ->notify(redirectRelayAttachment($this->relay), ['jsonrpc' => '2.0', 'method' => 'ping']);

    $requests = $this->relay->requests();

    expect($requests)->toHaveCount(2)
        ->and($requests[1]['uri'])->toBe('/relay/demo_001/landed')
        ->and($requests[1]['headers']['Authorization'] ?? null)->toBe('Bearer '.RELAY_REDIRECT_TOKEN)
        ->and($this->elsewhere->requests())->toBe([]);
})->with([
    'relative path' => ['landed'],
    'absolute path' => ['/relay/demo_001/landed'],
    'explicit same port' => ['http://{A}/relay/demo_001/landed'],
    'upper-case scheme' => ['HTTP://{A}/relay/demo_001/landed'],
]);

it('follows a same-origin redirect when the relay host is written in mixed case', function (): void {
    $transport = new SsePostRelayTransport(new Client, ['localhost'], 5, allowedRelayPorts: [$this->relay->port], allowUnverifiedEgress: true, authMode: 'bearer');
    $attachment = new SurfaceAttachment(
        'surface_1', 'session:one',
        new SurfaceInvitation("http://localhost:{$this->relay->port}/relay", 'demo_001', RELAY_REDIRECT_TOKEN, 'sheet:one', 'Demo', allowInsecureLoopback: true),
        new Participant('agent:one', 'One', '#000000'), 'php_worker_1',
    );
    $this->relay->answer([['match' => '/inbox', 'status' => 307, 'location' => "http://LocalHost:{$this->relay->port}/relay/demo_001/landed"]]);

    $transport->notify($attachment, ['jsonrpc' => '2.0', 'method' => 'ping']);

    expect($this->relay->requests())->toHaveCount(2)
        ->and($this->relay->requests()[1]['uri'])->toBe('/relay/demo_001/landed');
});

it('keeps the redirect rule when a caller passes its own redirect option to get()', function (mixed $callerOption): void {
    $endpoint = new RelayEndpoint(new Client, ['127.0.0.1'], 5, [$this->relay->port], null, true, 'bearer');
    $this->relay->answer([['match' => '/events', 'status' => 307, 'location' => $this->elsewhere->origin().'/landed']]);

    [$failure] = relayFailure(fn () => $endpoint->get(redirectRelayAttachment($this->relay), 'events', [], ['allow_redirects' => $callerOption]));

    expect($this->elsewhere->requests())->toBe([])
        ->and($failure)->toBeInstanceOf(HumanPlusException::class);
})->with([
    'true' => [true],
    'an array without a hook' => [['max' => 10]],
    'an array with a permissive hook' => [['max' => 10, 'on_redirect' => static function (): void {}]],
]);

it('reports an unreachable relay as a Human+ failure without the token', function (): void {
    $transport = redirectRelayTransport('sse', $this->relay, 'query');
    $attachment = redirectRelayAttachment($this->relay);
    $this->relay->stop();
    $this->relay = RedirectLoopback::start('127.0.0.1');

    [$failure] = relayFailure(fn () => $transport->notify($attachment, ['jsonrpc' => '2.0', 'method' => 'ping']));

    expect($failure)->toBeInstanceOf(HumanPlusException::class)
        ->and($failure?->getMessage())->not->toContain(RELAY_REDIRECT_TOKEN)
        ->and($failure?->getPrevious())->toBeNull();
});

it('follows no redirect when a caller turns redirects off in get()', function (mixed $off): void {
    $endpoint = new RelayEndpoint(new Client, ['127.0.0.1'], 5, [$this->relay->port], null, true, 'bearer');
    $this->relay->answer([['match' => '/events', 'status' => 307, 'location' => $this->relay->origin().'/relay/demo_001/landed']]);

    [$failure] = relayFailure(fn () => $endpoint->get(redirectRelayAttachment($this->relay), 'events', [], ['allow_redirects' => $off]));

    expect($this->relay->requests())->toHaveCount(1)
        ->and($this->elsewhere->requests())->toBe([])
        ->and($failure?->getMessage())->toBe('Fancy relay failed with HTTP 307.');
})->with([
    'false' => [false],
    'zero' => [0],
    'empty string' => [''],
    'null' => [null],
]);

it('follows no redirect when the injected client has redirects off', function (string $call, mixed $off): void {
    $endpoint = new RelayEndpoint(new Client(['allow_redirects' => $off]), ['127.0.0.1'], 5, [$this->relay->port], null, true, 'bearer');
    $attachment = redirectRelayAttachment($this->relay);
    $this->relay->answer([['match' => $call === 'get' ? '/events' : '/inbox', 'status' => 307, 'location' => $this->relay->origin().'/relay/demo_001/landed']]);

    [$failure] = relayFailure(fn () => $call === 'get'
        ? $endpoint->get($attachment, 'events')
        : $endpoint->post($attachment, 'inbox', ['jsonrpc' => '2.0', 'method' => 'ping']));

    expect($this->relay->requests())->toHaveCount(1)
        ->and($this->elsewhere->requests())->toBe([])
        ->and($failure?->getMessage())->toBe('Fancy relay failed with HTTP 307.');
})->with(['get', 'post'])->with([
    'false' => [false],
    'null' => [null],
]);
