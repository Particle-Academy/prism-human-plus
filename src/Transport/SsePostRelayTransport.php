<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Transport;

use GuzzleHttp\ClientInterface;
use Prism\HumanPlus\Contracts\RelayTransport;
use Prism\HumanPlus\Data\SurfaceAttachment;
use Prism\HumanPlus\Exceptions\AttachmentUnauthorized;
use Prism\HumanPlus\Exceptions\HumanPlusException;
use Prism\HumanPlus\Exceptions\SurfaceUnavailable;
use Psr\Http\Message\ResponseInterface;

/**
 * Fancy's client-scoped SSE + POST relay transport.
 *
 * The broker queues a correlated response for its client id, which lets the
 * PHP transport POST before opening its bounded receive stream. This ordering
 * works with synchronous queue workers and avoids parking one HTTP handler
 * while another request is still needed to produce the first event.
 */
final readonly class SsePostRelayTransport implements RelayTransport
{
    /**
     * @param  list<string>  $allowedRelayHosts
     * @param  list<int>  $allowedRelayPorts
     */
    public function __construct(
        private ClientInterface $http,
        private array $allowedRelayHosts,
        private int $timeoutSeconds = 30,
        private int $maxFrameBytes = 262144,
        private array $allowedRelayPorts = [443],
        private ?string $egressProxy = null,
        private bool $allowUnverifiedEgress = false,
        private string $authMode = 'query',
        private bool $useNativeCurl = false,
    ) {}

    public function exchange(SurfaceAttachment $attachment, array $frame): array
    {
        $base = $this->base($attachment);
        if ($this->useNativeCurl) {
            return $this->exchangeNative($attachment, $frame, $base);
        }
        $query = $this->query($attachment, ['direction' => 'outbound']);
        $post = $this->http->request('POST', $base.'/inbox?'.$this->query($attachment), [
            'http_errors' => false, 'timeout' => $this->timeoutSeconds,
            'headers' => $this->headers($attachment, ['Content-Type' => 'application/json']),
            'body' => json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ...$this->networkOptions(),
        ]);
        $this->assertLive($post);
        $streamResponse = $this->http->request('GET', $base.'/events?'.$query, [
            'stream' => true, 'timeout' => $this->timeoutSeconds,
            'headers' => $this->headers($attachment, ['Accept' => 'text/event-stream', 'Cache-Control' => 'no-cache']),
            ...$this->networkOptions(),
        ]);
        $this->assertLive($streamResponse);

        $expectedId = $frame['id'] ?? null;
        $buffer = '';
        $body = $streamResponse->getBody();
        while (! $body->eof()) {
            $buffer .= $body->read(8192);
            if (strlen($buffer) > $this->maxFrameBytes) {
                throw new HumanPlusException('Fancy relay response exceeded the frame byte budget.');
            }
            while (($boundary = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $boundary);
                $buffer = substr($buffer, $boundary + 2);
                $data = $this->eventData($event);
                if ($data === null) {
                    continue;
                }
                $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded) && ($decoded['id'] ?? null) === $expectedId) {
                    return $decoded;
                }
            }
        }
        throw new HumanPlusException('Fancy relay stream ended before the correlated response arrived.');
    }

    /**
     * @param  array<string, mixed>  $frame
     * @return array<string, mixed>
     */
    private function exchangeNative(SurfaceAttachment $attachment, array $frame, string $base): array
    {
        if (! function_exists('curl_multi_init')) {
            throw new HumanPlusException('Native concurrent Human+ transport requires ext-curl.');
        }
        $expectedId = $frame['id'] ?? null;
        $decoded = null;
        $buffer = '';
        $headersReady = false;
        $get = curl_init($base.'/events?'.$this->query($attachment, ['direction' => 'outbound']));
        $post = curl_init($base.'/inbox?'.$this->query($attachment));
        $common = [CURLOPT_TIMEOUT => $this->timeoutSeconds, CURLOPT_HTTPHEADER => $this->curlHeaders($attachment)];
        if ($this->egressProxy !== null) {
            $common[CURLOPT_PROXY] = $this->egressProxy;
        }
        curl_setopt_array($get, $common + [
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$headersReady): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $headersReady = true;
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$buffer, &$decoded, $expectedId): int {
                $buffer .= $chunk;
                if (strlen($buffer) > $this->maxFrameBytes) {
                    return 0;
                }
                while (($boundary = strpos($buffer, "\n\n")) !== false) {
                    $event = substr($buffer, 0, $boundary);
                    $buffer = substr($buffer, $boundary + 2);
                    $data = $this->eventData($event);
                    if ($data === null) {
                        continue;
                    }
                    $candidate = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($candidate) && ($candidate['id'] ?? null) === $expectedId) {
                        $decoded = $candidate;

                        return 0;
                    }
                }

                return strlen($chunk);
            },
        ]);
        curl_setopt_array($post, $common + [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [...$this->curlHeaders($attachment), 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $get);
        $postAdded = false;
        $running = null;
        $getStatus = 0;
        $postStatus = 0;
        $deadline = microtime(true) + $this->timeoutSeconds;
        try {
            do {
                curl_multi_exec($multi, $running);
                if ($headersReady && ! $postAdded) {
                    curl_multi_add_handle($multi, $post);
                    $postAdded = true;
                }
                if ($decoded !== null) {
                    return $decoded;
                }
                if (microtime(true) >= $deadline) {
                    throw new HumanPlusException('Fancy relay timed out before the correlated response arrived.');
                }
                curl_multi_select($multi, 0.1);
            } while ($running > 0 || ! $postAdded);
        } finally {
            $getStatus = (int) curl_getinfo($get, CURLINFO_RESPONSE_CODE);
            $postStatus = (int) curl_getinfo($post, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($multi, $get);
            if ($postAdded) {
                curl_multi_remove_handle($multi, $post);
            }
            curl_close($get);
            curl_close($post);
            curl_multi_close($multi);
        }

        $status = $getStatus !== 0 ? $getStatus : $postStatus;
        if ($status === 401) {
            throw new AttachmentUnauthorized('The Fancy surface attachment is unauthorized.');
        }
        if ($status === 410) {
            throw new SurfaceUnavailable('The Fancy surface is gone; this attachment cannot resume.');
        }
        throw new HumanPlusException('Fancy relay stream ended before the correlated response arrived.');
    }

    /** @return list<string> */
    private function curlHeaders(SurfaceAttachment $attachment): array
    {
        $headers = ['Accept: text/event-stream', 'Cache-Control: no-cache'];
        if ($this->authMode === 'bearer') {
            $headers[] = 'Authorization: Bearer '.$attachment->invitation->token;
        }

        return $headers;
    }

    public function notify(SurfaceAttachment $attachment, array $frame): void
    {
        $response = $this->http->request('POST', $this->base($attachment).'/inbox?'.$this->query($attachment), [
            'http_errors' => false, 'timeout' => $this->timeoutSeconds,
            'headers' => $this->headers($attachment, ['Content-Type' => 'application/json']),
            'body' => json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ...$this->networkOptions(),
        ]);
        $this->assertLive($response);
    }

    public function detach(SurfaceAttachment $attachment): void
    {
        $response = $this->http->request('POST', $this->base($attachment).'/unregister?'.$this->query($attachment), [
            'http_errors' => false, 'timeout' => $this->timeoutSeconds,
            'headers' => $this->headers($attachment),
            ...$this->networkOptions(),
        ]);
        $this->assertLive($response, detach: true);
    }

    private function base(SurfaceAttachment $attachment): string
    {
        $url = rtrim($attachment->invitation->relayBaseUrl, '/');
        if ($this->egressProxy === null && ! $this->allowUnverifiedEgress) {
            throw new AttachmentUnauthorized('Human+ relay transport requires a trusted egress proxy; explicitly opt into unverified egress only for isolated local dogfooding.');
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $insecureLoopback = $this->allowUnverifiedEgress && $scheme === 'http' && in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
        if (($scheme !== 'https' && ! $insecureLoopback)
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null
            || parse_url($url, PHP_URL_QUERY) !== null
            || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new AttachmentUnauthorized('Human+ relay URL must be credential-free HTTPS without query or fragment components.');
        }
        if (! in_array($host, array_map(strtolower(...), $this->allowedRelayHosts), true)) {
            throw new AttachmentUnauthorized(sprintf('Relay host [%s] is not declared by local Human+ policy.', $host));
        }
        $port = parse_url($url, PHP_URL_PORT) ?? 443;
        if (! is_int($port) || ! in_array($port, $this->allowedRelayPorts, true)) {
            throw new AttachmentUnauthorized(sprintf('Relay port [%s] is not declared by local Human+ policy.', (string) $port));
        }
        if (! $insecureLoopback) {
            $this->assertPublicHost($host);
        }

        return $url.'/'.rawurlencode($attachment->invitation->sessionId);
    }

    /** @param array<string, string> $extra */
    private function query(SurfaceAttachment $attachment, array $extra = []): string
    {
        $auth = $this->authMode === 'query' ? ['token' => $attachment->invitation->token] : [];

        return http_build_query([...$auth, 'client' => $attachment->clientId, ...$extra], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function headers(SurfaceAttachment $attachment, array $extra = []): array
    {
        if (! in_array($this->authMode, ['query', 'bearer'], true)) {
            throw new AttachmentUnauthorized('Human+ relay authentication mode must be query or bearer.');
        }

        return $this->authMode === 'bearer'
            ? ['Authorization' => 'Bearer '.$attachment->invitation->token, ...$extra]
            : $extra;
    }

    /** @return array{}|array{proxy: string} */
    private function networkOptions(): array
    {
        return $this->egressProxy === null ? [] : ['proxy' => $this->egressProxy];
    }

    private function assertPublicHost(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new AttachmentUnauthorized('Human+ relay resolved to a private or reserved address.');
            }

            return;
        }

        if ($this->allowUnverifiedEgress) {
            return;
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            throw new AttachmentUnauthorized('Human+ relay host did not resolve to a public address.');
        }
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new AttachmentUnauthorized('Human+ relay resolved to a private or reserved address.');
            }
        }
    }

    private function assertLive(ResponseInterface $response, bool $detach = false): void
    {
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }
        $body = (string) $response->getBody();
        if ($status === 410 || str_contains($body, 'session_gone')) {
            if ($detach) {
                return;
            }
            throw new SurfaceUnavailable('The Fancy surface is gone; this attachment cannot resume.');
        }
        if ($status === 401) {
            throw new AttachmentUnauthorized('The Fancy surface attachment is unauthorized.');
        }
        throw new HumanPlusException(sprintf('Fancy relay failed with HTTP %d.', $status));
    }

    private function eventData(string $event): ?string
    {
        $lines = preg_split('/\r?\n/', $event) ?: [];
        $data = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5));
            }
        }

        return $data === [] ? null : implode("\n", $data);
    }
}
