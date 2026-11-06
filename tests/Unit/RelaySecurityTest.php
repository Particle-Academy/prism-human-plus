<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Transport {
    function dns_get_record(string $host, int $type): array|false
    {
        if (! isset($GLOBALS['relay_security_dns'])) {
            return [['ip' => '8.8.8.8']];
        }
        $state = &$GLOBALS['relay_security_dns'];
        $index = count($state['calls']);
        $state['calls'][] = ['host' => $host, 'type' => $type];
        $answers = $state['answers'][min($index, count($state['answers']) - 1)];
        if ($answers === false) {
            return false;
        }

        return array_map(fn ($address) => [str_contains($address, ':') ? 'ipv6' : 'ip' => $address], $answers);
    }
}

namespace {
    use GuzzleHttp\Client;
    use GuzzleHttp\Handler\MockHandler;
    use GuzzleHttp\HandlerStack;
    use GuzzleHttp\Middleware;
    use GuzzleHttp\Psr7\Response;
    use Prism\HumanPlus\Data\Participant;
    use Prism\HumanPlus\Data\SurfaceAttachment;
    use Prism\HumanPlus\Data\SurfaceInvitation;
    use Prism\HumanPlus\Exceptions\AttachmentUnauthorized;
    use Prism\HumanPlus\Transport\LongPollRelayTransport;
    use Prism\HumanPlus\Transport\RelayEndpoint;
    use Prism\HumanPlus\Transport\SsePostRelayTransport;

    function relaySecurityFixture(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../fixtures/relay-security.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    function relaySecurityClass(?Throwable $error): string
    {
        if ($error === null) {
            return 'success';
        }
        $name = basename(str_replace('\\', '/', $error::class));

        return $name === 'HumanPlusException' ? 'HumanPlusError' : $name;
    }

    function relaySecurityAttachment(string $url = 'https://relay.fixture.invalid', bool $local = false): SurfaceAttachment
    {
        return new SurfaceAttachment('surface_x', 'session:one', new SurfaceInvitation($url, 'b3session', str_repeat('T', 32), 'board:fixture', 'Fixture', $local), new Participant('agent:b3', 'Fixture', '#fff'), 'b3_client');
    }

    afterEach(function (): void {
        unset($GLOBALS['relay_security_dns']);
    });

    it('lets HTTP status own the error category', function (array $row): void {
        $endpoint = new RelayEndpoint(new Client, ['8.8.8.8'], egressProxy: 'http://proxy.fixture:3128');
        $error = null;
        try {
            $endpoint->assertLive(new Response($row['status'], [], $row['body']), $row['operation'] === 'detach');
        } catch (Throwable $caught) {
            $error = $caught;
        }
        expect(relaySecurityClass($error))->toBe($row['expected']);
        if ($error !== null) {
            expect($error->getMessage())->not->toContain('PRIVATE-B3');
        }
    })->with(function () {
        foreach (relaySecurityFixture()['statusRows'] as $row) {
            yield $row['id'] => [$row];
        }
    });

    it('applies status categories at both actual relay bindings', function (array $row, string $binding): void {
        $rpc = "data: {\"jsonrpc\":\"2.0\",\"id\":\"probe\",\"result\":{\"fixture\":true}}\n\n";
        $responses = $row['operation'] === 'stream'
            ? [new Response(200), new Response($row['status'], [], $row['body'])]
            : [new Response($row['status'], [], $row['body']), new Response(200, [], $rpc)];
        $history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $class = $binding === 'sse' ? SsePostRelayTransport::class : LongPollRelayTransport::class;
        $transport = new $class(new Client(['handler' => $stack]), ['8.8.8.8'], egressProxy: 'http://proxy.fixture:3128');
        $error = null;
        $attachment = relaySecurityAttachment('https://8.8.8.8');
        $frame = ['jsonrpc' => '2.0', 'id' => 'probe', 'method' => 'tools/call', 'params' => (object) []];
        try {
            match ($row['operation']) {
                'notify' => $transport->notify($attachment, $frame),
                'detach' => $transport->detach($attachment),
                default => $transport->exchange($attachment, $frame),
            };
        } catch (Throwable $caught) {
            $error = $caught;
        }
        expect(relaySecurityClass($error))->toBe($row['expected']);
        expect(count($history))->toBe($row['operation'] === 'stream' ? 2 : 1);
    })->with(function () {
        foreach (relaySecurityFixture()['statusRows'] as $row) {
            if (! in_array($row['status'], [401, 409, 410], true) || $row['body'] === 'PRIVATE-B3-not-session_gone') {
                continue;
            }
            foreach (['sse', 'poll'] as $binding) {
                yield $binding.'-'.$row['id'] => [$row, $binding];
            }
        }
    });

    it('checks every resolved address before each HTTP leg', function (array $profile): void {
        $GLOBALS['relay_security_dns'] = ['answers' => $profile['answers'], 'calls' => []];
        $history = [];
        $rpc = "data: {\"jsonrpc\":\"2.0\",\"id\":\"probe\",\"result\":{\"fixture\":true}}\n\n";
        $stack = HandlerStack::create(new MockHandler([new Response(200), new Response(200, [], $rpc)]));
        $stack->push(Middleware::history($history));
        $transport = new SsePostRelayTransport(new Client(['handler' => $stack]), $profile['allowedHosts'] ?? ['relay.fixture.invalid'], allowedRelayPorts: $profile['allowedPorts'] ?? [443], egressProxy: array_key_exists('proxy', $profile) && $profile['proxy'] === null ? null : 'http://proxy.fixture:3128', allowUnverifiedEgress: $profile['allowUnverified'] ?? false);
        $error = null;
        $reply = null;
        try {
            $reply = $transport->exchange(relaySecurityAttachment($profile['url'] ?? 'https://relay.fixture.invalid', $profile['allowInsecureLoopback'] ?? false), ['jsonrpc' => '2.0', 'id' => 'probe', 'method' => 'tools/call', 'params' => (object) []]);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        if ($profile['expectedPublic']) {
            expect($error)->toBeNull();
            expect($reply['result'])->toBe(['fixture' => true]);
        } else {
            expect($error)->toBeInstanceOf(AttachmentUnauthorized::class);
        }
        $posts = count(array_filter($history, fn ($item) => $item['request']->getMethod() === 'POST'));
        expect($posts)->toBe($profile['expectedPosts'] ?? ($profile['expectedPublic'] ? 1 : 0));
        expect(count($history) - $posts)->toBe($profile['expectedStreams'] ?? ($profile['expectedPublic'] ? 1 : 0));
        $lookups = $profile['expectedDnsCalls'] ?? ($profile['id'] === 'dns-rebind-before-get' || $profile['expectedPublic'] ? 2 : 1);
        expect(count($GLOBALS['relay_security_dns']['calls']))->toBe($lookups);
    })->with(function () {
        foreach (relaySecurityFixture()['dnsProfiles'] as $profile) {
            yield $profile['id'] => [$profile];
        }
    });

    it('classifies numeric literals without DNS', function (array $row): void {
        $GLOBALS['relay_security_dns'] = ['answers' => [false], 'calls' => []];
        $host = str_contains($row['address'], ':') ? '['.$row['address'].']' : $row['address'];
        $endpoint = new RelayEndpoint(new Client, [$host, $row['address']], egressProxy: 'http://proxy.fixture:3128');
        $error = null;
        try {
            $endpoint->base(relaySecurityAttachment('https://'.$host));
        } catch (Throwable $caught) {
            $error = $caught;
        }
        if ($row['publicAddress'] ?? $row['expectedPublic']) {
            expect($error)->toBeNull();
        } else {
            expect($error)->toBeInstanceOf(AttachmentUnauthorized::class);
        }
        expect($GLOBALS['relay_security_dns']['calls'])->toBe([]);
    })->with(function () {
        foreach (relaySecurityFixture()['addresses'] as $row) {
            yield $row['id'] => [$row];
        }
    });
}
