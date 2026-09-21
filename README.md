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
wire protocol for bounded exchanges, and `LongPollRelayTransport` does the same
work without ever holding a connection open.

### Two transports, and how to choose between them

**Start with `SsePostRelayTransport`.** One round trip, no polling interval,
and the response arrives the instant the broker has it.

**Reach for `LongPollRelayTransport` when the streaming leg cannot survive the
trip.** Not when you want more throughput — it parks a worker for exactly as
long as the SSE transport does, and neither transport is a throughput fix. Use
it when the stream does not work at all:

- a queue worker or FaaS runtime with no streaming HTTP client, or one that
  kills a request that produces no bytes for N seconds;
- a forward proxy or corporate gateway that buffers responses, so the SSE
  frames arrive in one lump when the stream closes — which is to say, after the
  exchange has already timed out;
- a runtime that materialises the response body before user code sees it, which
  makes `stream => true` a lie that nothing reports.

In all three the failure is silent or indistinguishable from a timeout, and no
amount of tuning `timeoutSeconds` fixes it. The useful property of the long-poll
transport is not speed: it is that every leg is a discrete, ordinary, short
request that any HTTP client anywhere can make.

```php
$transport = new LongPollRelayTransport(
    $http,
    allowedRelayHosts: ['relay.fancy.example'],
    timeoutSeconds: 15,
    egressProxy: 'http://egress.internal:3128',
    pollWaitSeconds: 5,
);
```

**Both transports share one egress policy.** The URL shape, the host and port
allow-lists, the resolved-address check, the proxy requirement, the auth mode
and how a 401 is told apart from a 410 all live in `RelayEndpoint`, which both
compose. That is not tidiness: two copies of an SSRF guard do not drift on the
day the second is written, they drift the third time someone fixes a bug in one
of them. The suite runs one corpus of hostile invitations through **both**
bindings, so a transport that acquires its own policy fails on the day it is
added — which a per-transport test cannot see, because each file goes on passing
against its own class for ever.

#### The relay half of the long-poll contract

The broker is Fancy's, not this package's, so what the transport expects is
stated rather than assumed. Three ordinary requests:

```
POST {base}/{session}/inbox?token&client            the frame
GET  {base}/{session}/outbox?token&client&wait=5&after={cursor}
POST {base}/{session}/unregister?token&client       on detach
```

`wait` asks the broker to hold the poll open that many seconds if it has
nothing yet, and to answer immediately once it does. **A broker that ignores
`wait` still works.** The transport times each poll: one that came back fast and
empty is paced before the next, one that took its time is not. So it can be
pointed at a plain queue endpoint that was never built for long polling, and
neither kind of broker needs to declare which it is.

The poll body may be any of four shapes, because brokers differ and refusing
three of them would mean a code change per broker rather than a binding:

```json
{"id":"…","jsonrpc":"2.0","result":{}}     one frame
[{"…":"…"},{"…":"…"}]                      a list of frames
{"frames":[{"…":"…"}],"cursor":"17"}       a list plus a cursor
{"events":[{"…":"…"}],"next":17}           the same, named otherwise
```

A cursor, when the broker sends one, goes back as `after` on the next poll so a
frame delivered between two polls is not missed. A broker that sends none is
expected to hold undelivered frames for the client id — which is what the SSE
path already relies on.

`pollPath` is a constructor argument (default `outbox`) so a broker that already
serves such an endpoint under another name needs a binding change here, not a
fork.

### What that costs a host, stated properly

This paragraph used to say "high-concurrency applications should bind an async
gateway/long-poll transport rather than parking ordinary queue workers", and a
consumer whose chat turns *are* ordinary queue workers could not tell from it
whether they were inside or outside what the transport is for. They stopped and
asked. The adjective was the problem, so here is the mechanism instead.

**`exchange()` parks ONE WORKER PER IN-FLIGHT EXCHANGE, for at most
`timeoutSeconds`** — on BOTH transports; long polling changes how the wait is
shaped, never how long a worker is held. The SSE one POSTs the frame to
`/inbox`, opens `GET /events`, and blocks until the correlated id arrives or the
stream ends. Both legs are capped
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


## What changed since my last turn

**A revision stops an agent overwriting a change it did not know about. It does
nothing about an agent that knows exactly what it is doing and is wrong.**

The first outside consumer predicted this one before writing any adoption code,
and the prediction is worth stating in their terms: the first real two-writer
bug will not look like a conflict. It will be the agent re-reading a surface,
deciding it has drifted from what the agent intended, and putting it back — over
a person's edit, in one turn, with no error anywhere. Nothing is stale, so the
pin matches and the write lands.

Optimistic concurrency answers *did the world move under me*. This answers *what
did somebody else do*, which is the question that stops the revert.

```php
$changes = $humanPlus->changesSince($owner, $attachmentId);

if (! $changes->answered()) {
    // The surface has no feed. An empty list here is not evidence of quiet.
}

foreach ($changes->deferTo() as $change) {
    // A handle this agent should re-read rather than correct.
    $change->handle;   // the surface's own id
    $change->kind;     // created | updated | deleted | moved | unknown
    $change->actor;    // human | agent | other | unknown
}
```

### The empty answer is the dangerous value

"Nothing changed since your marker" and "I cannot answer that question" are the
same empty array on the wire. Returning a bare list would make them
indistinguishable, and an agent that reads silence as calm is the agent this
whole feature exists to stop.

So the feed state comes first, and `nothingChanged()` is the only method that
means what an empty list looks like it means:

| `ChangeFeed` | What is known |
|---|---|
| `NotObserved` | The surface has not listed its tools yet. |
| `Unavailable` | It offers no feed. **"What changed" is unanswerable here.** |
| `Offered` | A feed exists. Whether it can name WHO is not yet observable. |
| `Attributed` | It has named a hand other than this agent's. Proven, because it happened. |

This package shipped the other version of this once. `conflictDetection()` was a
boolean that answered "does this surface mint revisions" while its documentation
claimed a lost update would be caught — different questions, and the first
integrator satisfied the cheap one. The states now say only what was seen, and
`Attributed` is evidence when it arrives, never a precondition: a surface nobody
else is editing legitimately never reports a human change and is
indistinguishable from one that cannot report it.

### Attribution is the load-bearing field

"What changed" without "who" does not stop the revert. A list of moved handles
includes the agent's own last write and looks identical to a person's.

A change is deferred to **unless the surface positively said this agent made
it**. One rule, and it lands correctly in both worlds: a surface that cannot
attribute reports everything as `unknown`, so everything is deferred to — not
because it is all a person's, but because none of it can be shown to be the
agent's own, and undoing a person's work is the expensive mistake. A surface
that can attribute gets its answer used.

**A surface where every write path is an agent tool cannot attribute anything**,
and that is not hypothetical: it is what the first surface asked reported about
itself. On such a surface an agent gains "these handles moved, re-read them" and
does not gain "leave this one alone" — which is a smaller thing honestly
delivered rather than a larger thing faked.

### A surface may admit its answer is partial

`complete` is true unless the surface says otherwise. The opposite default would
mark every existing surface's answers partial for having never heard of the
flag — a warning nobody can act on and everybody learns to skip.

It exists because feeds have holes their authors know about. The first surface
asked hard-deletes rows with no tombstone, so a removal moves no revision and
appears in no feed: "nothing changed" is what it says when a screen was
destroyed. A package cannot detect that from outside. It can let the surface say
so, and refuse to call the answer calm.

### The surface's half

A tool named any of `changes_since`, `surface_changes`, `what_changed` or
`changes`, taking `since` and answering with rows under `changes` (in `_meta` or
at the top level):

```json
{
  "_meta": {
    "revision": "r42",
    "complete": true,
    "changes": [
      { "screen_id": "screen_7", "change": "moved", "actor_type": "human", "kind": "chart" }
    ]
  }
}
```

`handle` is read from `handle`, `id`, `screen_id`, `node_id` or `key`; the
change from `change`, `event`, `action` or `op`; the actor from `actor_type`,
`actor`, `by` or `changed_by`. Several spellings per field, because this half of
the wire is the surface's and refusing four shapes of five would make every new
surface a code change here.

**Send `change` even when you also send `kind`.** A surface that uses `kind` for
a component type — "chart", "table" — and `change` for what happened is the
shape already in the wild. The change keys are read first and `kind` is only
consulted when none is present, so sending both is correct; sending only a
component `kind` would have it read as an event type and land on `unknown`.

An actor this package does not recognise is `unknown`, never a default. A
surface that says `"actor": "operator"` means something, and quietly deciding it
means `agent` would be the revert bug arriving through the parser. An
`actor_id`, if you send one, is **not consumed** — `actor_type` is enough to
decide deference, and carrying a person's identity through a package that does
not need it is how PII ends up somewhere nobody meant it to be.

## Two writers

**A human editing the same surface as the agent used to lose their work in
silence.** The agent read, the person committed, the agent wrote, and both
writes succeeded — which is exactly what a lost update looks like from the
inside. Nothing failed, so nothing was reported, and the only party who could
tell was the person watching their change disappear.

That is now detected. It is **not** resolved, and the difference matters.

### What the package does

Optimistic concurrency, with the comparison left where the knowledge is:

1. A tool result may carry a **revision** — the surface's own marker for the
   state it just showed. The package stores it on the attachment.
2. Every later call is **pinned** to that marker.
3. If the surface says the marker is stale, the call is refused with
   `SurfaceChangedUnderYou` (code `surface_changed_under_you`), **nothing is
   written**, and the stored marker is dropped so the agent can read again.

The refusal is written to be read by a model mid-turn, because that is who
receives it: it names the tool, the revision it was working from, and the one
thing the agent must not do — repeat the call with the same arguments, which is
how the other change gets overwritten.

### What it deliberately does not do

**It does not merge.** This package never models what a surface's state is —
tools come from the surface's own `tools/list` and the data behind them is
opaque here. A merge invented at this layer would be guessing at a document it
cannot read, and it would be wrong silently. The agent is told its read is
stale and decides; it is the only party in the exchange that knows what it was
trying to achieve.

**It does not know a read from a write**, so it pins every call. MCP's
`readOnlyHint` is explicitly a hint the spec says not to trust for security
decisions, and deciding from it would let a surface mark a mutating tool
read-only and have its writes go out unpinned — the one direction that must not
be possible. Pinning a read costs nothing, because a read overwrites nothing.

### The surface has to hold up its half

The relay and the surface are Fancy's, not this package's, so what is expected
is stated rather than assumed. A surface participates by doing two things:

**Mint a revision on results.** Any of these is read, because implementations
differ and refusing four of five shapes would make each new surface a code
change here:

```json
{"_meta": {"revision": "r42"}}      preferred — _meta is where MCP puts this
{"revision": "r42"}                 a real surface's shape; do not narrow this away
{"surfaceRevision": "r42"}
{"etag": "r42"}
{"version": 42}
```

The marker is **opaque**: never parsed, never ordered, never compared here. An
ETag, a row version, a Lamport counter, a content hash all work, and the package
cannot tell which it is holding. It is capped at 512 bytes — a revision is an
identifier, not a payload.

**Reject a stale one.** The pin arrives as `params._meta.revision` on
`tools/call`. Reject it with an HTTP 409, or a JSON-RPC error whose `code` or
`data.code` is `409`, `conflict`, `revision_mismatch`, `revision_stale`,
`stale_revision` or `precondition_failed`. All of those are recognised, because
JSON-RPC has no precondition code of its own and a surface protecting its state
correctly should not lose updates through this client over a spelling.

### What this package can see, and what it cannot

Read this before relying on any of it.

```php
$humanPlus->conflictDetection($owner, $id);   // a ConflictDetection enum
```

| state | what it means |
|---|---|
| `NotObserved` | The surface has not answered a call. Nothing is known. |
| `Unavailable` | It answered and minted nothing. Writes are unpinned; a concurrent edit **will** be lost silently. The one definite negative. |
| `Minted` | It mints revisions, so every call is pinned. **Whether it enforces the pin is not observable from here.** Half a green light. |
| `Enforced` | It has actually refused a stale pin. Enforcement is proven, because it happened. |

**`Minted` is not protection, and this used to claim it was.** The method
returned a `?bool` whose `true` was documented as "a lost update will be
caught". The first integrator found the hole by reading their own surface rather
than trusting that sentence: they mint a revision on every write result and
**read an incoming pin nowhere** — no `_meta`, no `If-Match`, no rejection path,
no 409. A pinned call is accepted and applied exactly as an unpinned one. They
satisfied the minting half, the detector said `true`, and every update would
still have been lost.

That is this package's own failure mode one level up — a check reporting success
because it was asked the question it could answer instead of the one that
mattered. The states now say only what was seen.

**There is deliberately no "require enforcement" mode.** A surface with one
writer legitimately never rejects anything, and is indistinguishable from a
surface that cannot reject, so a flag demanding proof would refuse every write
on a healthy surface until a conflict happened to occur. `Enforced` is evidence
when it arrives, never a precondition.

```php
new HumanPlusManager($transport, $store, $trust, $guard, requireRevision: true);
```

`requireRevision: true` refuses a call to a surface in `Unavailable` with
`ConflictDetectionUnavailable`. The first call is always allowed — there is no
way to know what a surface supplies before it has answered, and refusing it
would refuse the read that finds out. Off by default, because a single-writer
surface is real and refusing it would be inventing a requirement. **It requires
minting, not protection**: the surface above satisfies it and loses every
update.

### A coarse revision manufactures refusals that conflict with nothing

Worth knowing before a refusal rate reads as a bug here. The first integrator's
counter is monotonic **per surface**, not per screen — deliberately, because the
question a turn asks is "did anything change here", and a per-row counter makes
that a scan rather than a comparison.

So a human editing screen A bumps the same counter an agent's pending write to
screen B is pinned against, and `SurfaceChangedUnderYou` is **true by the marker
and wrong by intent**. That is a property of the marker's granularity, not of
this package: the fix is a finer revision, or one that encodes which rows moved,
and both belong to the surface. Nothing here can tell the two apart, because the
marker is opaque by design.

### Still not claimed

Merge, operational transform, CRDTs, and any ordering of concurrent edits.
Fancy provides staged writes, activity, presence and undo; what a *resolved*
concurrent edit means remains a surface-specific contract. What has changed is
that losing one is no longer silent.
