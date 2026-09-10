<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Prism\HumanPlus\Exceptions\HumanPlusException;
use Prism\HumanPlus\Transport\LongPollRelayTransport;

/*
|--------------------------------------------------------------------------
| The transport for hosts that cannot hold a stream open
|--------------------------------------------------------------------------
|
| Built because a consumer's chat turns are ordinary queue workers and the SSE
| leg does not survive that shape. These pin the two things that make it usable
| against a relay somebody else operates: it correlates on id across polls, and
| it behaves correctly whether or not the broker honours `wait`.
|
*/

function longPollTransport(Response ...$responses): LongPollRelayTransport
{
    return new LongPollRelayTransport(
        new Client(['handler' => HandlerStack::create(new MockHandler($responses))]),
        ['relay.example.com'],
        allowUnverifiedEgress: true,
    );
}

it('POSTs the frame and returns the correlated response from a poll', function (): void {
    $transport = longPollTransport(
        new Response(202, [], '{"accepted":true}'),
        new Response(200, [], '{"frames":[{"id":"rpc-1","jsonrpc":"2.0","result":{"ok":true}}],"cursor":"7"}'),
    );

    $reply = $transport->exchange(relayAttachment(), ['jsonrpc' => '2.0', 'id' => 'rpc-1', 'method' => 'tools/list']);

    expect($reply['id'])->toBe('rpc-1')
        ->and($reply['result'])->toBe(['ok' => true]);
});

it('never asks for a streaming response, which is the whole point', function (): void {
    // The SSE transport passes `stream => true` on its GET. If this one ever
    // acquires it, the transport has silently become the thing it replaced on
    // exactly the hosts that cannot do it.
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(202, [], '{}'),
        new Response(200, [], '{"frames":[{"id":"rpc-1"}]}'),
    ]));
    $stack->push(Middleware::history($history));

    (new LongPollRelayTransport(new Client(['handler' => $stack]), ['relay.example.com'], allowUnverifiedEgress: true))
        ->exchange(relayAttachment(), ['jsonrpc' => '2.0', 'id' => 'rpc-1']);

    foreach ($history as $transaction) {
        expect($transaction['options']['stream'] ?? false)->toBeFalse();
    }
});

it('keeps polling past frames that belong to somebody else', function (): void {
    // A client-scoped queue can hold more than one pending frame, and an
    // exchange that returned the first thing it saw would hand a caller
    // another exchange's answer — the worst possible failure, because it looks
    // like a success.
    $transport = longPollTransport(
        new Response(202, [], '{}'),
        new Response(200, [], '{"frames":[{"id":"rpc-0","result":"stale"}],"cursor":"1"}'),
        new Response(200, [], '{"frames":[],"cursor":"2"}'),
        new Response(200, [], '{"frames":[{"id":"rpc-1","result":"mine"}],"cursor":"3"}'),
    );

    $reply = $transport->exchange(relayAttachment(), ['jsonrpc' => '2.0', 'id' => 'rpc-1']);

    expect($reply['result'])->toBe('mine');
});

it('sends the broker cursor back so a frame between polls is not lost', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(202, [], '{}'),
        new Response(200, [], '{"frames":[],"cursor":"41"}'),
        new Response(200, [], '{"frames":[{"id":"rpc-1"}],"cursor":"42"}'),
    ]));
    $stack->push(Middleware::history($history));

    (new LongPollRelayTransport(new Client(['handler' => $stack]), ['relay.example.com'], allowUnverifiedEgress: true, pollWaitSeconds: 0))
        ->exchange(relayAttachment(), ['jsonrpc' => '2.0', 'id' => 'rpc-1']);

    // First poll has nothing to resume from; the second carries what the first
    // was told.
    expect($history[1]['request']->getUri()->getQuery())->not->toContain('after=')
        ->and($history[2]['request']->getUri()->getQuery())->toContain('after=41');
});

it('accepts the four shapes a broker might answer a poll with', function (string $body): void {
    $transport = longPollTransport(new Response(202, [], '{}'), new Response(200, [], $body));

    expect($transport->exchange(relayAttachment(), ['jsonrpc' => '2.0', 'id' => 'rpc-1'])['id'])->toBe('rpc-1');
})->with([
    'a bare frame' => '{"id":"rpc-1","jsonrpc":"2.0"}',
    'a list' => '[{"id":"rpc-1","jsonrpc":"2.0"}]',
    'frames plus cursor' => '{"frames":[{"id":"rpc-1"}],"cursor":"1"}',
    'events plus next' => '{"events":[{"id":"rpc-1"}],"next":1}',
]);

it('asks the broker to hold the poll open', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(202, [], '{}'), new Response(200, [], '{"frames":[{"id":"rpc-1"}]}')]));
    $stack->push(Middleware::history($history));

    (new LongPollRelayTransport(new Client(['handler' => $stack]), ['relay.example.com'], allowUnverifiedEgress: true, pollWaitSeconds: 5))
        ->exchange(relayAttachment(), ['jsonrpc' => '2.0', 'id' => 'rpc-1']);

    expect($history[1]['request']->getUri()->getQuery())->toContain('wait=5');
});

it('does not hot-loop against a broker that ignores wait', function (): void {
    // A broker that answers an empty poll instantly is a real deployment — the
    // `wait` parameter is an ask, not a guarantee. Without pacing this would
    // spend the whole timeout hammering somebody else's relay, which is the
    // kind of thing that gets a client blocked rather than debugged.
    $responses = [new Response(202, [], '{}')];
    for ($i = 0; $i < 40; $i++) {
        $responses[] = new Response(200, [], '{"frames":[]}');
    }

    $transport = new LongPollRelayTransport(
        new Client(['handler' => HandlerStack::create($mock = new MockHandler($responses))]),
        ['relay.example.com'],
        timeoutSeconds: 1,
        allowUnverifiedEgress: true,
        pollWaitSeconds: 1,
    );

    $startedAt = microtime(true);
    expect(fn (): array => $transport->exchange(relayAttachment(), ['jsonrpc' => '2.0', 'id' => 'rpc-1']))
        ->toThrow(HumanPlusException::class, 'timed out');

    // One POST plus a small number of paced polls, not forty. The bound is
    // deliberately loose — this asserts "paced", not a schedule.
    expect(count($responses) - $mock->count())->toBeLessThan(6)
        ->and(microtime(true) - $startedAt)->toBeLessThan(3.0);
});

it('stops at the deadline rather than overshooting it', function (): void {
    $transport = new LongPollRelayTransport(
        new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, 20, new Response(200, [], '{"frames":[]}'))))]),
        ['relay.example.com'],
        timeoutSeconds: 1,
        allowUnverifiedEgress: true,
        pollWaitSeconds: 30,
    );

    $startedAt = microtime(true);
    expect(fn (): array => $transport->exchange(relayAttachment(), ['jsonrpc' => '2.0', 'id' => 'rpc-1']))
        ->toThrow(HumanPlusException::class);

    // pollWaitSeconds is 30 against a timeout of 1. A transport that paced on
    // the poll interval rather than the deadline would sit here for half a
    // minute holding the worker it was chosen to protect.
    expect(microtime(true) - $startedAt)->toBeLessThan(4.0);
});

it('refuses a poll body over the frame byte budget', function (): void {
    $transport = new LongPollRelayTransport(
        new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(202, [], '{}'),
            new Response(200, [], json_encode(['frames' => [['id' => 'rpc-1', 'pad' => str_repeat('x', 4096)]]])),
        ]))]),
        ['relay.example.com'],
        maxFrameBytes: 512,
        allowUnverifiedEgress: true,
    );

    expect(fn (): array => $transport->exchange(relayAttachment(), ['jsonrpc' => '2.0', 'id' => 'rpc-1']))
        ->toThrow(HumanPlusException::class, 'byte budget');
});

it('lets the broker path be named, because that half of the wire is theirs', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(202, [], '{}'), new Response(200, [], '{"id":"rpc-1"}')]));
    $stack->push(Middleware::history($history));

    (new LongPollRelayTransport(new Client(['handler' => $stack]), ['relay.example.com'], allowUnverifiedEgress: true, pollPath: 'pending'))
        ->exchange(relayAttachment(), ['jsonrpc' => '2.0', 'id' => 'rpc-1']);

    expect($history[1]['request']->getUri()->getPath())->toEndWith('/pending');
});

it('does not wait at all to announce, exactly as the SSE transport does not', function (): void {
    // notify() is fire-and-forget on both transports. If this one ever started
    // polling after an announce, every activity notification would cost a
    // worker for the poll interval.
    $mock = new MockHandler([new Response(200, [], '{}')]);

    (new LongPollRelayTransport(new Client(['handler' => HandlerStack::create($mock)]), ['relay.example.com'], allowUnverifiedEgress: true))
        ->notify(relayAttachment(), ['jsonrpc' => '2.0', 'method' => 'notifications/activity']);

    expect($mock->count())->toBe(0);
});
