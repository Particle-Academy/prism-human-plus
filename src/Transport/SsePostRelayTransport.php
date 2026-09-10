<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Transport;

use GuzzleHttp\ClientInterface;
use Prism\HumanPlus\Contracts\RelayTransport;
use Prism\HumanPlus\Data\SurfaceAttachment;
use Prism\HumanPlus\Exceptions\AttachmentUnauthorized;
use Prism\HumanPlus\Exceptions\HumanPlusException;
use Prism\HumanPlus\Exceptions\SurfaceUnavailable;

/**
 * Fancy's client-scoped SSE + POST relay transport.
 *
 * The broker queues a correlated response for its client id, which lets the
 * PHP transport POST before opening its bounded receive stream. This ordering
 * works with synchronous queue workers and avoids parking one HTTP handler
 * while another request is still needed to produce the first event.
 *
 * **This is the transport to reach for first.** One round trip, no polling
 * interval, and the response arrives the instant the broker has it. Reach for
 * {@see LongPollRelayTransport} only when the streaming leg cannot survive the
 * trip - a runtime with no streaming client, a proxy that buffers the response,
 * a gateway that kills a request producing no bytes. That is a different
 * problem from throughput, and neither transport solves throughput.
 *
 * Every check between the invitation and the wire lives in
 * {@see RelayEndpoint}, which both transports compose. This class owns exactly
 * one thing the other does not: how it waits.
 */
final readonly class SsePostRelayTransport implements RelayTransport
{
    private RelayEndpoint $endpoint;

    /**
     * @param  list<string>  $allowedRelayHosts
     * @param  list<int>  $allowedRelayPorts
     */
    public function __construct(
        private ClientInterface $http,
        array $allowedRelayHosts,
        private int $timeoutSeconds = 30,
        private int $maxFrameBytes = 262144,
        array $allowedRelayPorts = [443],
        ?string $egressProxy = null,
        bool $allowUnverifiedEgress = false,
        string $authMode = 'query',
        private bool $useNativeCurl = false,
    ) {
        $this->endpoint = new RelayEndpoint(
            $http,
            $allowedRelayHosts,
            $timeoutSeconds,
            $allowedRelayPorts,
            $egressProxy,
            $allowUnverifiedEgress,
            $authMode,
        );
    }

    public function exchange(SurfaceAttachment $attachment, array $frame): array
    {
        if ($this->useNativeCurl) {
            return $this->exchangeNative($attachment, $frame, $this->endpoint->base($attachment));
        }
        $this->endpoint->post($attachment, 'inbox', $frame);
        $streamResponse = $this->endpoint->get($attachment, 'events', ['direction' => 'outbound'], [
            'stream' => true,
            'headers' => $this->endpoint->headers($attachment, ['Accept' => 'text/event-stream', 'Cache-Control' => 'no-cache']),
        ]);

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
        $get = curl_init($base.'/events?'.$this->endpoint->query($attachment, ['direction' => 'outbound']));
        $post = curl_init($base.'/inbox?'.$this->endpoint->query($attachment));
        $common = [CURLOPT_TIMEOUT => $this->timeoutSeconds, CURLOPT_HTTPHEADER => $this->curlHeaders($attachment)];
        $proxy = $this->endpoint->proxy();
        if ($proxy !== null) {
            $common[CURLOPT_PROXY] = $proxy;
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
            CURLOPT_POSTFIELDS => RelayEndpoint::encode($frame),
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
        return ['Accept: text/event-stream', 'Cache-Control: no-cache', ...$this->endpoint->curlAuthHeaders($attachment)];
    }

    public function notify(SurfaceAttachment $attachment, array $frame): void
    {
        $this->endpoint->post($attachment, 'inbox', $frame);
    }

    public function detach(SurfaceAttachment $attachment): void
    {
        $this->endpoint->post($attachment, 'unregister', detach: true);
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
