<?php

declare(strict_types=1);

it('checks both native HTTP legs before returning a correlated reply', function (int $postStatus, int $getStatus, bool $correlated, string $expected, bool $earlyFrame = false): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect($server)->toBeResource();
    stream_set_blocking($server, false);
    $origin = 'http://'.stream_socket_get_name($server, false);
    // Windows PHP pipes cannot reliably be made nonblocking. Reading a pipe here
    // would park the fixture server until the child timed out.
    $stdoutFile = tempnam(sys_get_temp_dir(), 'human-native-out-');
    $stderrFile = tempnam(sys_get_temp_dir(), 'human-native-err-');
    $process = proc_open([PHP_BINARY, __DIR__.'/../fixtures/native-relay-client.php', $origin], [0 => ['pipe', 'r'], 1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']], $pipes, dirname(__DIR__, 2));
    expect($process)->toBeResource();
    fclose($pipes[0]);
    $clients = [];
    $accepted = 0;
    $bytes = 0;
    $buffers = [];
    $get = null;
    $counts = ['get' => 0, 'post' => 0];
    $stdout = '';
    $stderr = '';
    $exit = null;
    $deadline = microtime(true) + 6;
    try {
        do {
            $status = proc_get_status($process);
            if (! $status['running']) {
                $exit = $status['exitcode'];
                break;
            }
            $read = [$server, ...array_values($clients)];
            $write = $except = null;
            if (stream_select($read, $write, $except, 0, 10000) === false) {
                throw new RuntimeException('Fixture select failed');
            }
            foreach ($read as $socket) {
                if ($socket === $server) {
                    $client = stream_socket_accept($server, 0);
                    if ($client !== false) {
                        $accepted++;
                        stream_set_blocking($client, false);
                        $clients[(int) $client] = $client;
                        $buffers[(int) $client] = '';
                    }

                    continue;
                }
                $id = (int) $socket;
                $chunk = fread($socket, 8192);
                $bytes += strlen($chunk);
                $buffers[$id] .= $chunk;
                $boundary = strpos($buffers[$id], "\r\n\r\n");
                if ($boundary === false) {
                    continue;
                }
                if (str_starts_with($buffers[$id], 'GET ')) {
                    $counts['get']++;
                    fwrite($socket, "HTTP/1.1 $getStatus Fixture\r\nContent-Type: text/event-stream\r\nConnection: close\r\n\r\n");
                    $get = $socket;
                    unset($clients[$id], $buffers[$id]);
                } else {
                    preg_match('/Content-Length:\s*(\d+)/i', substr($buffers[$id], 0, $boundary), $length);
                    if (strlen($buffers[$id]) < $boundary + 4 + (int) ($length[1] ?? 0)) {
                        continue;
                    }
                    $counts['post']++;
                    if ($earlyFrame && $correlated && is_resource($get)) {
                        fwrite($get, "data: {\"jsonrpc\":\"2.0\",\"id\":\"probe\",\"result\":{\"fixture\":true}}\n\n");
                        fclose($get);
                        $get = null;
                        // Force the client to observe a reply before POST has an HTTP status.
                        usleep(50000);
                    }
                    // A broken client may already have closed its POST after seeing the frame.
                    @fwrite($socket, "HTTP/1.1 $postStatus Fixture\r\nContent-Type: application/json\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
                    fclose($socket);
                    unset($clients[$id], $buffers[$id]);
                    if (is_resource($get)) {
                        if ($correlated) {
                            fwrite($get, "data: {\"jsonrpc\":\"2.0\",\"id\":\"probe\",\"result\":{\"fixture\":true}}\n\n");
                        }
                        fclose($get);
                        $get = null;
                    }
                }
            }
        } while (microtime(true) < $deadline);
    } finally {
        if (proc_get_status($process)['running']) {
            proc_terminate($process);
        }
        foreach ($clients as $socket) {
            fclose($socket);
        }
        if (is_resource($get)) {
            fclose($get);
        }
        fclose($server);
        proc_close($process);
        $stdout = file_get_contents($stdoutFile);
        $stderr = file_get_contents($stderrFile);
        unlink($stdoutFile);
        unlink($stderrFile);
    }
    expect($counts)->toBe(['get' => 1, 'post' => 1], json_encode(['accepted' => $accepted, 'bytes' => $bytes, 'exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr]));
    expect($exit)->toBe(0);
    expect($stderr)->toBe('');
    $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    expect($result['outcome'])->toBe($expected);
    if ($expected === 'returned') {
        expect($result['reply']['result'])->toBe(['fixture' => true]);
    }
})->with([
    'positive' => [200, 200, true, 'returned'],
    'post conflict with correlated get' => [409, 200, true, 'SurfaceRevisionRejected'],
    'get conflict with successful post' => [200, 409, false, 'SurfaceRevisionRejected'],
    'post unauthorized with gone get' => [401, 410, false, 'AttachmentUnauthorized'],
    'early frame before successful post' => [200, 200, true, 'returned', true],
    'early frame before conflicting post' => [409, 200, true, 'SurfaceRevisionRejected', true],
]);
