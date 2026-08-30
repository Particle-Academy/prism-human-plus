<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Prism\HumanPlus\Data\Participant;
use Prism\HumanPlus\Data\SurfaceAttachment;
use Prism\HumanPlus\Data\SurfaceInvitation;
use Prism\HumanPlus\Exceptions\AttachmentUnauthorized;
use Prism\HumanPlus\Exceptions\SurfaceUnavailable;
use Prism\HumanPlus\Transport\SsePostRelayTransport;

function relayAttachment(): SurfaceAttachment
{
    return new SurfaceAttachment(
        'surface_1', 'session:one',
        new SurfaceInvitation('https://relay.example.com', 'demo_001', str_repeat('a', 32), 'sheet:one', 'Demo'),
        new Participant('agent:one', 'One', '#000000'), 'php_worker_1',
    );
}

function relayTransport(Response ...$responses): SsePostRelayTransport
{
    $mock = new MockHandler($responses);

    return new SsePostRelayTransport(new Client(['handler' => HandlerStack::create($mock)]), ['relay.example.com'], allowUnverifiedEgress: true);
}

it('keeps session gone distinct from unauthorized', function (): void {
    expect(fn () => relayTransport(new Response(410, [], '{"error":"session_gone"}'))->notify(relayAttachment(), ['jsonrpc' => '2.0', 'method' => 'ping']))
        ->toThrow(SurfaceUnavailable::class)
        ->and(fn () => relayTransport(new Response(401, [], '{"error":"invalid_token"}'))->notify(relayAttachment(), ['jsonrpc' => '2.0', 'method' => 'ping']))
        ->toThrow(AttachmentUnauthorized::class);
});

it('treats unregistering an already gone surface as successful', function (): void {
    relayTransport(new Response(410, [], '{"error":"session_gone"}'))->detach(relayAttachment());
    expect(true)->toBeTrue();
});

it('refuses relay hosts outside local policy before making a request', function (): void {
    $transport = new SsePostRelayTransport(new Client(['handler' => HandlerStack::create(new MockHandler)]), ['trusted.example.com'], allowUnverifiedEgress: true);
    expect(fn () => $transport->notify(relayAttachment(), ['jsonrpc' => '2.0', 'method' => 'ping']))
        ->toThrow(AttachmentUnauthorized::class, 'not declared');
});

it('requires a trusted egress boundary by default', function (): void {
    $transport = new SsePostRelayTransport(new Client(['handler' => HandlerStack::create(new MockHandler)]), ['relay.example.com']);
    expect(fn () => $transport->notify(relayAttachment(), ['jsonrpc' => '2.0', 'method' => 'ping']))
        ->toThrow(AttachmentUnauthorized::class, 'egress proxy');
});

it('rejects undeclared relay ports', function (): void {
    $attachment = new SurfaceAttachment(
        'surface_1', 'session:one',
        new SurfaceInvitation('https://relay.example.com:8443', 'demo_001', str_repeat('a', 32), 'sheet:one', 'Demo'),
        new Participant('agent:one', 'One', '#000000'), 'php_worker_1',
    );
    expect(fn () => relayTransport()->notify($attachment, ['jsonrpc' => '2.0', 'method' => 'ping']))
        ->toThrow(AttachmentUnauthorized::class, 'port');
});

it('uses the Fancy EventSource-compatible query token contract by default', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
    $stack->push(Middleware::history($history));
    $transport = new SsePostRelayTransport(new Client(['handler' => $stack]), ['relay.example.com'], allowUnverifiedEgress: true);

    $transport->notify(relayAttachment(), ['jsonrpc' => '2.0', 'method' => 'ping']);

    expect($history[0]['request']->getUri()->getQuery())->toContain('token='.str_repeat('a', 32))
        ->and($history[0]['request']->hasHeader('Authorization'))->toBeFalse();
});

it('supports bearer authentication for relays that explicitly implement it', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
    $stack->push(Middleware::history($history));
    $transport = new SsePostRelayTransport(new Client(['handler' => $stack]), ['relay.example.com'], allowUnverifiedEgress: true, authMode: 'bearer');

    $transport->notify(relayAttachment(), ['jsonrpc' => '2.0', 'method' => 'ping']);

    expect($history[0]['request']->getUri()->getQuery())->not->toContain('token=')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer '.str_repeat('a', 32));
});
