<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Prism\HumanPlus\Contracts\RelayTransport;
use Prism\HumanPlus\Data\SurfaceAttachment;
use Prism\HumanPlus\Exceptions\AttachmentUnauthorized;
use Prism\HumanPlus\Exceptions\SurfaceUnavailable;
use Prism\HumanPlus\Transport\LongPollRelayTransport;
use Prism\HumanPlus\Transport\SsePostRelayTransport;

/*
|--------------------------------------------------------------------------
| One egress policy, proved against EVERY transport
|--------------------------------------------------------------------------
|
| There are two transports now, and the thing that would go wrong is not that
| the second one was written without a guard — it is that a guard gets fixed in
| one of them, six months from now, and the other keeps the old behaviour.
|
| A per-transport test cannot see that: each file passes, against its own class,
| for ever. So every hostile invitation below runs through BOTH bindings from
| one dataset. A transport added later that forgets to compose RelayEndpoint
| fails here on the day it is written, and adding it to this list is one line.
|
*/

/** @return array<string, list<callable(list<string>, array<string, mixed>): RelayTransport>> */
function everyTransport(): array
{
    return [
        'sse' => [fn (array $hosts, array $options): RelayTransport => new SsePostRelayTransport(
            new Client(['handler' => HandlerStack::create(new MockHandler($options['responses'] ?? []))]),
            $hosts,
            allowedRelayPorts: $options['ports'] ?? [443],
            egressProxy: $options['proxy'] ?? null,
            allowUnverifiedEgress: $options['unverified'] ?? false,
            authMode: $options['authMode'] ?? 'query',
        )],
        'long-poll' => [fn (array $hosts, array $options): RelayTransport => new LongPollRelayTransport(
            new Client(['handler' => HandlerStack::create(new MockHandler($options['responses'] ?? []))]),
            $hosts,
            allowedRelayPorts: $options['ports'] ?? [443],
            egressProxy: $options['proxy'] ?? null,
            allowUnverifiedEgress: $options['unverified'] ?? false,
            authMode: $options['authMode'] ?? 'query',
        )],
    ];
}

$ping = ['jsonrpc' => '2.0', 'method' => 'ping'];

it('requires a trusted egress boundary before making any request', function (callable $build) use ($ping): void {
    expect(fn () => $build(['relay.example.com'], [])->notify(relayAttachment('https://relay.example.com'), $ping))
        ->toThrow(AttachmentUnauthorized::class, 'egress proxy');
})->with(everyTransport());

it('refuses a relay host nobody declared', function (callable $build) use ($ping): void {
    expect(fn () => $build(['trusted.example.com'], ['unverified' => true])->notify(relayAttachment('https://relay.example.com'), $ping))
        ->toThrow(AttachmentUnauthorized::class, 'not declared');
})->with(everyTransport());

it('refuses an undeclared port even on a declared host', function (callable $build) use ($ping): void {
    expect(fn () => $build(['relay.example.com'], ['unverified' => true])->notify(relayAttachment('https://relay.example.com:8443'), $ping))
        ->toThrow(AttachmentUnauthorized::class, 'port');
})->with(everyTransport());

it('refuses a relay URL carrying credentials', function (callable $build) use ($ping): void {
    // A userinfo component puts a secret somewhere no redaction watches: the
    // URL itself, in every proxy log between here and the relay.
    expect(fn () => $build(['relay.example.com'], ['unverified' => true])->notify(relayAttachment('https://user:pass@relay.example.com'), $ping))
        ->toThrow(AttachmentUnauthorized::class, 'credential-free');
})->with(everyTransport());

it('refuses a relay URL carrying its own query or fragment', function (callable $build) use ($ping): void {
    // The transport appends `?token=…`. A base that already has a query would
    // either be silently mangled or let an invitation smuggle parameters into
    // every call the transport makes.
    expect(fn () => $build(['relay.example.com'], ['unverified' => true])->notify(relayAttachment('https://relay.example.com?tenant=other'), $ping))
        ->toThrow(AttachmentUnauthorized::class, 'query or fragment');
})->with(everyTransport());

it('refuses plaintext http at the invitation, before a transport is involved', function (): void {
    // NOT run per transport, and the reason is worth recording: the value
    // object refuses this at construction, so the identical check inside
    // RelayEndpoint::base() is unreachable through a valid invitation. It stays
    // there as defence in depth — an invitation could be built by a future
    // caller that skips the constructor — but a per-transport test asserting it
    // would be testing the value object twice and the transports not at all.
    expect(fn (): SurfaceAttachment => relayAttachment('http://relay.example.com'))
        ->toThrow(InvalidArgumentException::class, 'HTTPS');
});

it('refuses a declared host that is a private address', function (callable $build) use ($ping): void {
    // The allow-list says who we will talk to; this says where that points.
    // A declared 169.254.169.254 is still cloud metadata.
    expect(fn () => $build(['169.254.169.254'], ['proxy' => 'http://egress.internal:3128'])->notify(relayAttachment('https://169.254.169.254'), $ping))
        ->toThrow(AttachmentUnauthorized::class, 'private or reserved');
})->with(everyTransport());

it('refuses an authentication mode nobody implements', function (callable $build) use ($ping): void {
    expect(fn () => $build(['relay.example.com'], ['unverified' => true, 'authMode' => 'cookie'])->notify(relayAttachment('https://relay.example.com'), $ping))
        ->toThrow(AttachmentUnauthorized::class, 'query or bearer');
})->with(everyTransport());

it('tells a gone surface apart from an unauthorized one', function (callable $build) use ($ping): void {
    // A caller retries one of these and abandons the other, so collapsing them
    // into a generic failure makes both wrong.
    expect(fn () => $build(['relay.example.com'], ['unverified' => true, 'responses' => [new Response(410, [], '{"error":"session_gone"}')]])->notify(relayAttachment('https://relay.example.com'), $ping))
        ->toThrow(SurfaceUnavailable::class)
        ->and(fn () => $build(['relay.example.com'], ['unverified' => true, 'responses' => [new Response(401, [], '{"error":"invalid_token"}')]])->notify(relayAttachment('https://relay.example.com'), $ping))
        ->toThrow(AttachmentUnauthorized::class);
})->with(everyTransport());

it('treats unregistering an already gone surface as done', function (callable $build): void {
    $build(['relay.example.com'], ['unverified' => true, 'responses' => [new Response(410, [], '{"error":"session_gone"}')]])
        ->detach(relayAttachment('https://relay.example.com'));

    expect(true)->toBeTrue();
})->with(everyTransport());

it('carries the token in the query by default and never in a header', function (string $class) use ($ping): void {
    // Fancy's contract, because browser EventSource cannot set headers. Pinned
    // on both transports so the long-poll one — which has no such constraint —
    // does not quietly diverge from the relay it has to talk to.
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
    $stack->push(Middleware::history($history));

    /** @var RelayTransport $transport */
    $transport = new $class(new Client(['handler' => $stack]), ['relay.example.com'], allowUnverifiedEgress: true);
    $transport->notify(relayAttachment(), $ping);

    expect($history[0]['request']->getUri()->getQuery())->toContain('token='.str_repeat('a', 32))
        ->and($history[0]['request']->hasHeader('Authorization'))->toBeFalse();
})->with([SsePostRelayTransport::class, LongPollRelayTransport::class]);
