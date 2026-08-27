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
 * The outbound subscription is established before a frame is posted so a fast
 * browser response cannot be lost. Each request carries the same client id on
 * subscription and post; omitting it opts into the legacy broadcast behavior
 * and can disclose another participant's results.
 */
final readonly class SsePostRelayTransport implements RelayTransport
{
    /** @param list<string> $allowedRelayHosts */
    public function __construct(
        private ClientInterface $http,
        private array $allowedRelayHosts,
        private int $timeoutSeconds = 30,
        private int $maxFrameBytes = 262144,
    ) {}

    public function exchange(SurfaceAttachment $attachment, array $frame): array
    {
        $base = $this->base($attachment);
        $query = $this->query($attachment, ['direction' => 'outbound']);
        $streamResponse = $this->http->requestAsync('GET', $base.'/events?'.$query, [
            'stream' => true, 'timeout' => $this->timeoutSeconds,
            'headers' => ['Accept' => 'text/event-stream', 'Cache-Control' => 'no-cache'],
        ])->wait();
        $this->assertLive($streamResponse);

        $post = $this->http->request('POST', $base.'/inbox?'.$this->query($attachment), [
            'http_errors' => false, 'timeout' => $this->timeoutSeconds,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $this->assertLive($post);

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

    public function notify(SurfaceAttachment $attachment, array $frame): void
    {
        $response = $this->http->request('POST', $this->base($attachment).'/inbox?'.$this->query($attachment), [
            'http_errors' => false, 'timeout' => $this->timeoutSeconds,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $this->assertLive($response);
    }

    public function detach(SurfaceAttachment $attachment): void
    {
        $response = $this->http->request('POST', $this->base($attachment).'/unregister?'.$this->query($attachment), [
            'http_errors' => false, 'timeout' => $this->timeoutSeconds,
        ]);
        $this->assertLive($response, detach: true);
    }

    private function base(SurfaceAttachment $attachment): string
    {
        $url = rtrim($attachment->invitation->relayBaseUrl, '/');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! in_array($host, array_map(strtolower(...), $this->allowedRelayHosts), true)) {
            throw new AttachmentUnauthorized(sprintf('Relay host [%s] is not declared by local Human+ policy.', $host));
        }

        return $url.'/'.rawurlencode($attachment->invitation->sessionId);
    }

    /** @param array<string, string> $extra */
    private function query(SurfaceAttachment $attachment, array $extra = []): string
    {
        return http_build_query(['token' => $attachment->invitation->token, 'client' => $attachment->clientId, ...$extra], '', '&', PHP_QUERY_RFC3986);
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
