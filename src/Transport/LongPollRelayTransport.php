<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Transport;

use GuzzleHttp\ClientInterface;
use Prism\HumanPlus\Contracts\RelayTransport;
use Prism\HumanPlus\Data\SurfaceAttachment;
use Prism\HumanPlus\Exceptions\HumanPlusException;

/**
 * A relay transport that never holds a connection open.
 *
 * ## What this is for, and what it is NOT for
 *
 * {@see SsePostRelayTransport} POSTs the frame and then opens `GET /events`,
 * holding that stream until the correlated id arrives. That works, and where it
 * works it is the better transport — one round trip, no polling interval, the
 * response arrives the instant the broker has it.
 *
 * It stops working when the host cannot hold a streaming response open. That is
 * not a throughput complaint and this transport is not a throughput fix — **it
 * parks a worker for exactly as long as the SSE transport does.** It is for the
 * hosts where the streaming leg does not survive the trip at all:
 *
 * - a queue worker or FaaS runtime that has no streaming HTTP client, or kills
 *   a request that produces no bytes for N seconds;
 * - a forward proxy or corporate gateway that buffers responses, so the SSE
 *   frames arrive in one lump when the stream closes — which is to say, after
 *   the exchange has already timed out;
 * - a runtime where the response body is materialised before user code sees it,
 *   which makes `stream => true` a lie no error reports.
 *
 * In all three the failure is silent or looks like a timeout, so the useful
 * property here is not speed: it is that every leg is a discrete, ordinary,
 * short request that any HTTP client anywhere can make.
 *
 * **If SSE works for you, keep it.** Reach for this when it does not.
 *
 * ## The wire contract this expects
 *
 * The relay half is Fancy's, not this package's, so it is stated rather than
 * assumed. Three requests, all ordinary:
 *
 *     POST {base}/{session}/inbox?token&client            the frame
 *     GET  {base}/{session}/outbox?token&client&wait=5&after={cursor}
 *     POST {base}/{session}/unregister?token&client       on detach
 *
 * `wait` asks the broker to hold the poll open for that many seconds if it has
 * nothing yet, and to answer immediately once it does. **A broker that ignores
 * `wait` still works** — see the pacing note on {@see self::exchange()} — which
 * matters, because it means this transport can be pointed at a plain queue
 * endpoint that was never built for long polling.
 *
 * The poll's response body may be any of these, because brokers differ and
 * refusing three of the four shapes would be a code change per broker rather
 * than a binding:
 *
 *     {"id":"…","jsonrpc":"2.0","result":{…}}      one frame
 *     [{…},{…}]                                     a list of frames
 *     {"frames":[{…}],"cursor":"17"}                a list plus a cursor
 *     {"events":[{…}],"next":"17"}                  the same, named otherwise
 *
 * A cursor, when the broker sends one, goes back as `after` on the next poll so
 * a frame delivered between two polls is not missed. A broker that sends none
 * is expected to hold undelivered frames for the client id, which is what the
 * SSE path already relies on.
 *
 * ## Everything before the wait is shared, deliberately
 *
 * The URL shape, the host and port allow-lists, the resolved-address check, the
 * egress proxy requirement and the auth mode all live in {@see RelayEndpoint}
 * and are the SAME OBJECT the SSE transport uses. This transport adds a way of
 * waiting; it does not get its own security policy, and the suite runs one
 * corpus of hostile invitations through both bindings so it cannot quietly
 * acquire one.
 */
final readonly class LongPollRelayTransport implements RelayTransport
{
    private RelayEndpoint $endpoint;

    /**
     * @param  list<string>  $allowedRelayHosts
     * @param  list<int>  $allowedRelayPorts
     * @param  int  $pollWaitSeconds  How long the broker is asked to hold each
     *                                poll open. Also the pacing floor when it
     *                                ignores the ask — see {@see self::exchange()}.
     * @param  string  $pollPath  The path the broker serves undelivered frames
     *                            on. Configurable because this half of the wire
     *                            is the relay's, and a broker that already has
     *                            such an endpoint should not need a fork here.
     */
    public function __construct(
        ClientInterface $http,
        array $allowedRelayHosts,
        private int $timeoutSeconds = 30,
        private int $maxFrameBytes = 262144,
        array $allowedRelayPorts = [443],
        ?string $egressProxy = null,
        bool $allowUnverifiedEgress = false,
        string $authMode = 'query',
        private int $pollWaitSeconds = 5,
        private string $pollPath = 'outbox',
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

    /**
     * POST the frame, then poll until the correlated response arrives.
     *
     * ## Pacing, and why it is measured rather than assumed
     *
     * If the broker honours `wait`, an empty poll has already cost that long
     * and the next one should go out immediately. If it ignores `wait` and
     * answers instantly, going straight round again is a hot loop that would
     * hammer the relay for the whole of `timeoutSeconds`.
     *
     * The transport cannot know which kind of broker it is talking to, and
     * asking the operator to declare it is asking them to know something they
     * would have to read someone else's server to find out. So it TIMES the
     * poll: one that came back fast and empty is paced, one that took its time
     * is not. Both brokers behave correctly against the same binding.
     */
    public function exchange(SurfaceAttachment $attachment, array $frame): array
    {
        $this->endpoint->post($attachment, 'inbox', $frame);

        $expectedId = $frame['id'] ?? null;
        $cursor = null;
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (microtime(true) < $deadline) {
            $remaining = $deadline - microtime(true);
            $startedAt = microtime(true);

            $response = $this->endpoint->get($attachment, $this->pollPath, [
                'direction' => 'outbound',
                'wait' => (string) max(1, (int) min($this->pollWaitSeconds, ceil($remaining))),
                ...($cursor === null ? [] : ['after' => $cursor]),
            ]);

            $body = (string) $response->getBody();
            if (strlen($body) > $this->maxFrameBytes) {
                throw new HumanPlusException('Fancy relay response exceeded the frame byte budget.');
            }

            $decoded = $body === '' ? null : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $cursor = self::cursorFrom($decoded) ?? $cursor;

            foreach (self::framesFrom($decoded) as $candidate) {
                if (is_array($candidate) && ($candidate['id'] ?? null) === $expectedId) {
                    return $candidate;
                }
            }

            $this->pace(microtime(true) - $startedAt, $deadline);
        }

        throw new HumanPlusException('Fancy relay did not deliver the correlated response before the exchange timed out.');
    }

    public function notify(SurfaceAttachment $attachment, array $frame): void
    {
        $this->endpoint->post($attachment, 'inbox', $frame);
    }

    public function detach(SurfaceAttachment $attachment): void
    {
        $this->endpoint->post($attachment, 'unregister', detach: true);
    }

    /**
     * Sleep only when the broker plainly did not hold the poll open, and never
     * past the deadline — a transport that overshoots its own timeout is worse
     * than the streaming one it was chosen to replace.
     */
    private function pace(float $elapsed, float $deadline): void
    {
        $shortfall = $this->pollWaitSeconds - $elapsed;
        if ($shortfall <= 0.25) {
            return;
        }

        $sleep = min($shortfall, max(0.0, $deadline - microtime(true)));
        if ($sleep > 0) {
            usleep((int) round($sleep * 1_000_000));
        }
    }

    /**
     * Every frame the poll returned, whichever of the four shapes it used.
     *
     * @return list<mixed>
     */
    private static function framesFrom(mixed $decoded): array
    {
        if (! is_array($decoded) || $decoded === []) {
            return [];
        }

        foreach (['frames', 'events', 'messages'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return array_values($decoded[$key]);
            }
        }

        // A list is a batch; anything else with keys is a single frame. Both
        // are real broker shapes, and telling them apart on "is it a list"
        // avoids sniffing for JSON-RPC members that a future frame may not have.
        return array_is_list($decoded) ? $decoded : [$decoded];
    }

    private static function cursorFrom(mixed $decoded): ?string
    {
        if (! is_array($decoded)) {
            return null;
        }

        foreach (['cursor', 'next', 'last_event_id'] as $key) {
            $value = $decoded[$key] ?? null;
            if (is_string($value) || is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
