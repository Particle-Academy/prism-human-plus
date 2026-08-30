# Prism Human+

Human+ participant presence for Prism agents in live Fancy surfaces, for PHP
8.2+ and Laravel 12/13.

This is not browser automation. A Fancy application owns the running surface,
state, stable handles, MCP tools, presence, undo, and staged-write UI. This
package lets a PHP agent join that surface as a named participant through an
explicit invitation and relay transport. It keeps Browser entirely separate.

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
wire protocol for bounded exchanges. High-concurrency applications should bind
an async gateway/long-poll transport rather than parking ordinary queue workers.

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
