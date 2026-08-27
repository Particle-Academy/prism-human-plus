<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
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

    return new SsePostRelayTransport(new Client(['handler' => HandlerStack::create($mock)]), ['relay.example.com']);
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
    $transport = new SsePostRelayTransport(new Client(['handler' => HandlerStack::create(new MockHandler)]), ['trusted.example.com']);
    expect(fn () => $transport->notify(relayAttachment(), ['jsonrpc' => '2.0', 'method' => 'ping']))
        ->toThrow(AttachmentUnauthorized::class, 'not declared');
});
