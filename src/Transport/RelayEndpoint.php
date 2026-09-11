<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Transport;

use GuzzleHttp\ClientInterface;
use Prism\HumanPlus\Data\SurfaceAttachment;
use Prism\HumanPlus\Exceptions\AttachmentUnauthorized;
use Prism\HumanPlus\Exceptions\HumanPlusException;
use Prism\HumanPlus\Exceptions\SurfaceRevisionRejected;
use Prism\HumanPlus\Exceptions\SurfaceUnavailable;
use Psr\Http\Message\ResponseInterface;

/**
 * The relay's address, its egress policy, and every check that stands between
 * an invitation and an outbound request.
 *
 * ## Why this is a class and not a trait
 *
 * There are now two transports — {@see SsePostRelayTransport} and
 * {@see LongPollRelayTransport} — and they differ in exactly one respect: how
 * they wait for the correlated response. Everything before that is identical
 * and is the part that matters if it is wrong: the URL shape, the host and
 * port allow-lists, the resolved-address check, the egress proxy requirement,
 * the auth mode, and how a 401 is told apart from a 410.
 *
 * **Two copies of an SSRF guard is one guard and one liability.** They would
 * not drift on the day the second was written; they would drift the third time
 * someone fixed a bug in one of them. So the guard is a collaborator both
 * transports compose, there is one implementation to review, and the suite runs
 * the same corpus of hostile invitations through both bindings — because a
 * check that exists in one transport and not the other is precisely the failure
 * a per-transport test cannot see.
 *
 * Nothing here is transport-specific. If a third transport arrives it inherits
 * the whole policy by construction rather than by remembering to.
 */
final readonly class RelayEndpoint
{
    /**
     * @param  list<string>  $allowedRelayHosts
     * @param  list<int>  $allowedRelayPorts
     */
    public function __construct(
        private ClientInterface $http,
        private array $allowedRelayHosts,
        private int $timeoutSeconds = 30,
        private array $allowedRelayPorts = [443],
        private ?string $egressProxy = null,
        private bool $allowUnverifiedEgress = false,
        private string $authMode = 'query',
    ) {}

    /**
     * POST a JSON frame to one of the relay's paths, and assert the relay is
     * still live before returning.
     *
     * @param  array<string, mixed>|null  $frame
     * @param  array<string, string>  $extra
     */
    public function post(
        SurfaceAttachment $attachment,
        string $path,
        ?array $frame = null,
        array $extra = [],
        bool $detach = false,
    ): ResponseInterface {
        $options = [
            'http_errors' => false,
            'timeout' => $this->timeoutSeconds,
            'headers' => $this->headers($attachment, $frame === null ? [] : ['Content-Type' => 'application/json']),
            ...$this->networkOptions(),
        ];

        if ($frame !== null) {
            $options['body'] = self::encode($frame);
        }

        $response = $this->http->request('POST', $this->url($attachment, $path, $extra), $options);
        $this->assertLive($response, $detach);

        return $response;
    }

    /**
     * GET one of the relay's paths, and assert the relay is still live.
     *
     * @param  array<string, string>  $extra
     * @param  array<string, mixed>  $options  Merged last, so a caller can
     *                                         stream or override the timeout.
     */
    public function get(SurfaceAttachment $attachment, string $path, array $extra = [], array $options = []): ResponseInterface
    {
        $response = $this->http->request('GET', $this->url($attachment, $path, $extra), [
            'http_errors' => false,
            'timeout' => $this->timeoutSeconds,
            'headers' => $this->headers($attachment),
            ...$this->networkOptions(),
            ...$options,
        ]);
        $this->assertLive($response);

        return $response;
    }

    /** @param array<string, string> $extra */
    public function url(SurfaceAttachment $attachment, string $path, array $extra = []): string
    {
        return $this->base($attachment).'/'.ltrim($path, '/').'?'.$this->query($attachment, $extra);
    }

    /**
     * The session-scoped base URL, AFTER every policy check has passed.
     *
     * Every check here answers a different question, and dropping any one of
     * them leaves a hole the others do not cover: the scheme and shape stop a
     * credentialed or query-bearing URL, the allow-lists stop a relay nobody
     * declared, and the resolved-address check stops a declared host that
     * points somewhere internal.
     */
    public function base(SurfaceAttachment $attachment): string
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
    public function query(SurfaceAttachment $attachment, array $extra = []): string
    {
        $auth = $this->authMode === 'query' ? ['token' => $attachment->invitation->token] : [];

        return http_build_query([...$auth, 'client' => $attachment->clientId, ...$extra], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    public function headers(SurfaceAttachment $attachment, array $extra = []): array
    {
        if (! in_array($this->authMode, ['query', 'bearer'], true)) {
            throw new AttachmentUnauthorized('Human+ relay authentication mode must be query or bearer.');
        }

        return $this->authMode === 'bearer'
            ? ['Authorization' => 'Bearer '.$attachment->invitation->token, ...$extra]
            : $extra;
    }

    /**
     * The auth header in cURL's list form, for the native concurrent path.
     *
     * @return list<string>
     */
    public function curlAuthHeaders(SurfaceAttachment $attachment): array
    {
        return $this->authMode === 'bearer'
            ? ['Authorization: Bearer '.$attachment->invitation->token]
            : [];
    }

    /** @return array{}|array{proxy: string} */
    public function networkOptions(): array
    {
        return $this->egressProxy === null ? [] : ['proxy' => $this->egressProxy];
    }

    public function proxy(): ?string
    {
        return $this->egressProxy;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /**
     * Distinguish "the surface is gone" from "you are not allowed" from "your
     * revision is stale" from "something else broke", because a caller does
     * something different with each and a generic failure makes all four look
     * like a retry — and a blind retry is exactly what turns a stale revision
     * into a lost update.
     */
    public function assertLive(ResponseInterface $response, bool $detach = false): void
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
        // A relay that enforces the revision itself answers 409 rather than
        // passing a JSON-RPC error back, so both routes have to be recognised or
        // a correctly-protecting relay still loses updates through this client.
        if ($status === 409) {
            throw new SurfaceRevisionRejected('The Fancy relay rejected the revision this call was pinned to.');
        }
        throw new HumanPlusException(sprintf('Fancy relay failed with HTTP %d.', $status));
    }

    /** @param array<string, mixed> $frame */
    public static function encode(array $frame): string
    {
        return json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * A declared host is not the same as a safe one.
     *
     * The allow-list says who we are willing to talk to; this says where that
     * name currently points. Both are needed, and neither is a rebinding
     * boundary on its own — the resolution here and the connection later are
     * two separate lookups, which is what the egress proxy is actually for.
     */
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
}
