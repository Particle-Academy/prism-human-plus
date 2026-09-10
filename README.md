# Prism Human+

Human+ participant presence for Prism agents in live Fancy surfaces, for PHP
8.2+ and Laravel 12/13.

This is not browser automation. A Fancy application owns the running surface,
state, stable handles, MCP tools, presence, undo, and staged-write UI. This
package lets a PHP agent join that surface as a named participant through an
explicit invitation and relay transport. It keeps Browser entirely separate.

## Reach for this when the agent and the surface are different systems

**Do not pay for a relay when both ends are yours.**

This package exists to cross a real trust boundary: an agent in one system
driving a surface owned by another — Claude Code on a laptop moving something in
somebody's browser. Everything expensive about it is the price of that boundary.
The relay endpoint, the per-session token, the token in the query string that
makes log redaction load-bearing across proxies and referrers and error pages —
those are correct costs when there is a boundary, and pure loss when there is
not.

So if your server already owns the surface, already wrote the schema it renders,
and already holds an authenticated socket to that browser, adopting this makes
your security posture **worse**: you would be adding a network hop and a bearer
credential in order to change state you already control. Broadcast over the
socket you already run.

The signal that you have crossed into this package's case is a **second
writer** — a human dragging nodes on the same graph the agent is editing.
Then you need the surface's real state rather than your own assumption of it,
presence a person can see, and tool definitions that come from the surface's own
`tools/list` rather than from your side's idea of them.

This framing came from the first team to evaluate the package. They read it
properly, concluded correctly that their v1 had no boundary to cross, and told
us so — which is more useful than an adoption would have been, and is why it is
now the first thing on this page instead of something the next reader has to
work out for themselves.

## Invariants

- Trust is declared before `initialize` or `tools/list` reaches the surface.
- Tool definitions may be allowlisted and pinned; success and error results are
  bounded and provenance-framed before a model reads them.
- Every relay connection uses a nonempty `clientId`, preventing the legacy
  reply-broadcast leak in shared sessions.
- `410 session_gone` maps to terminal `surface_unavailable`; `401` maps to
  `attachment_unauthorized`. Neither is retried or treated as the other.
- An agent may submit bridge tools and staged proposals, but this package never
  impersonates the human confirmation callback.
- Participant identity, activity target, priority, and correlation remain
  visible to the host surface.
- Every operation re-presents the attachment owner. Attachment ids locate state;
  they are not bearer credentials and cannot cross Harness sessions.

## Status

The first vertical slice ships participant/surface value objects, lifecycle
states, explicit trust and pinning, bounded result framing, the isolated MCP
`2025-06-18` initialize state machine, activity notifications, and a transport
contract. `SsePostRelayTransport` implements Fancy's client-scoped POST + SSE
wire protocol for bounded exchanges.

### What that costs a host, stated properly

This paragraph used to say "high-concurrency applications should bind an async
gateway/long-poll transport rather than parking ordinary queue workers", and a
consumer whose chat turns *are* ordinary queue workers could not tell from it
whether they were inside or outside what the transport is for. They stopped and
asked. The adjective was the problem, so here is the mechanism instead.

**`exchange()` parks ONE WORKER PER IN-FLIGHT EXCHANGE, for at most
`timeoutSeconds`.** It POSTs the frame to `/inbox`, opens `GET /events`, and
blocks until the correlated id arrives or the stream ends. Both legs are capped
by `timeoutSeconds` — a constructor argument, default 30. The stream is opened
per exchange and closed when the response arrives; nothing is held between
calls. `notify()` is POST-only and does not wait, so `announce()` costs nothing
here.

So there is no concurrency limit inside this package. The failure mode is worker
starvation in the *host*: turns queueing behind each other because concurrent
attachments exceeded the workers available to them. That is a capacity question,
and it is answerable with arithmetic rather than an adjective.

For a queue-worker host: give canvas turns their **own queue**, size its workers
to your expected concurrent attachments, and **lower** `timeoutSeconds` rather
than raising it — a dead surface should fail inside the turn instead of holding
a worker for thirty seconds a call.

**Attach per turn.** `attach()` performs no network at all; it mints ids and
writes the store row. The wire cost starts at first use — `initialize`, a
non-blocking `notifications/initialized`, then `tools/list` — and
`LegacyMcpClient` caches initialisation **per process**, so an attachment held
across a whole conversation re-initialises on whichever worker takes the next
turn anyway. Holding one open buys less than it appears to. Attach, call, then
`detach()`.

**None of the above is measured.** It is read off the transport, which is why it
is stated as a mechanism rather than as numbers: one worker, one exchange,
`timeoutSeconds`. A measurement pass against a production-shaped queue-worker
host was offered and then correctly withdrawn — that team concluded they had no
trust boundary to cross and are not running this transport, so there is nothing
to measure yet. The arithmetic here does not depend on their numbers; a claim
about how it *performs* would, and none is made.

The transport requires a trusted egress proxy by default and independently checks
the declared host, port, URL shape, and resolved addresses. The explicit
`allowUnverifiedEgress` escape hatch is only for isolated local dogfooding and
does not make application-layer DNS checks a rebinding boundary.

Fancy's current SSE relay wire contract carries its invitation token in the
query string because browser `EventSource` cannot set an Authorization header.
Use redacted proxy/access logs and never emit relay URLs to telemetry. Relays
that support header authentication can opt into `authMode: 'bearer'`.

The package deliberately does not claim conflict resolution. Fancy currently
provides staged writes, activity, presence, and undo; concurrent committed edits
remain a surface-specific contract.
