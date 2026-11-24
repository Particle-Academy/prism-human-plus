<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Prism\HumanPlus\Contracts\RelayTransport;
use Prism\HumanPlus\Data\Participant;
use Prism\HumanPlus\Data\SurfaceAttachment;
use Prism\HumanPlus\Data\SurfaceInvitation;
use Prism\HumanPlus\Exceptions\HumanPlusException;
use Prism\HumanPlus\Transport\LongPollRelayTransport;
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
