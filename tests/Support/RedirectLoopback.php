<?php

declare(strict_types=1);

/**
 * A real HTTP server on a loopback address, for redirect tests.
 *
 * Fakes cannot answer the question these tests ask — what the HTTP client does
 * on the wire when a server answers with a redirect — so this runs `php -S` on
 * a 127.x address and records every request it receives.
 */
final class RedirectLoopback
{
    /** @var resource */
    private $process;

    private function __construct(
        public readonly string $host,
        public readonly int $port,
        private readonly string $log,
        private readonly string $config,
        mixed $process,
    ) {
        $this->process = $process;
    }

    public static function start(string $host): self
    {
        $probe = stream_socket_server("tcp://{$host}:0", $errno, $error);
        if ($probe === false) {
            throw new RuntimeException("Cannot bind {$host}: {$error}");
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'redirect-loopback-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $log = $dir.DIRECTORY_SEPARATOR.'requests.log';
        $config = $dir.DIRECTORY_SEPARATOR.'rules.json';
        touch($log);
        file_put_contents($config, '{}');

        $env = getenv();
        $env['REDIRECT_LOOPBACK_LOG'] = $log;
        $env['REDIRECT_LOOPBACK_CONFIG'] = $config;

        $process = proc_open(
            [PHP_BINARY, '-S', "{$host}:{$port}", __DIR__.DIRECTORY_SEPARATOR.'redirect-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $dir.DIRECTORY_SEPARATOR.'out.txt', 'w'], 2 => ['file', $dir.DIRECTORY_SEPARATOR.'err.txt', 'w']],
            $pipes,
            $dir,
            $env,
        );
        if (! is_resource($process)) {
            throw new RuntimeException("Cannot start a server on {$host}:{$port}");
        }

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            // A refused connection is expected until the server is up; the
            // test runner's error handler would otherwise report it.
            set_error_handler(static fn (): bool => true);
            try {
                $socket = stream_socket_client("tcp://{$host}:{$port}", $errno, $error, 0.2);
            } finally {
                restore_error_handler();
            }
            if ($socket !== false) {
                fclose($socket);

                return new self($host, $port, $log, $config, $process);
            }
            usleep(50_000);
        }
        proc_terminate($process);

        throw new RuntimeException("The server on {$host}:{$port} did not start");
    }

    public function origin(): string
    {
        return "http://{$this->host}:{$this->port}";
    }

    /**
     * @param  list<array{match: string, status: int, location: string}>  $redirects
     */
    public function answer(array $redirects = [], string $body = '{}', string $contentType = 'application/json'): void
    {
        file_put_contents($this->config, json_encode([
            'redirects' => $redirects,
            'body' => $body,
            'contentType' => $contentType,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Every request this server has received, in order.
     *
     * @return list<array{method: string, uri: string, headers: array<string, string>, body: string}>
     */
    public function requests(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($this->log)));

        return array_values(array_map(fn (string $line): array => json_decode($line, true), $lines));
    }

    /** Everything this server received, as one string, to search for a value. */
    public function received(): string
    {
        return (string) file_get_contents($this->log);
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);

        $dir = dirname($this->log);
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
