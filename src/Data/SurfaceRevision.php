<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Data;

use UnexpectedValueException;

/**
 * An opaque marker for "the version of the surface the agent last saw".
 *
 * ## Why an opaque token and not a number
 *
 * This package does not know what a surface's state IS. It cannot know — the
 * whole design is that tools come from the surface's own `tools/list` and the
 * package never models the data behind them. So it cannot compute a version,
 * compare two versions, or merge anything.
 *
 * What it can do is CARRY a marker the surface itself minted, hand it back on
 * the next write, and refuse when the surface says the marker is stale. That is
 * optimistic concurrency with the comparison left where the knowledge is.
 *
 * The token is therefore never parsed, never ordered, and never inspected. An
 * ETag, a Lamport counter, a vector clock, a row version, a content hash — all
 * of them work here, and the package cannot tell which it is holding. If a
 * future surface sends something structured, this class still only has to move
 * it from a read to a write.
 *
 * ## Two writers is the case this exists for
 *
 * A single-writer agent needs none of this: nothing else changes the surface
 * between its read and its write. The case that needs it is a human dragging
 * nodes on a graph the agent is also editing, which is the first outside
 * consumer's stated Phase 5 and the case `README.md` says this package is
 * actually for.
 */
final readonly class SurfaceRevision
{
    private function __construct(
        /** The surface's own marker, moved but never interpreted. */
        public string $token,
        /** Which tool call observed it. Diagnostic only — never a decision. */
        public string $observedFrom,
    ) {}

    public static function observed(string $token, string $observedFrom): self
    {
        $token = trim($token);

        if ($token === '') {
            throw new UnexpectedValueException('A surface revision cannot be empty; omit it instead of sending a blank marker.');
        }

        // A ceiling, because this is stored on the attachment and echoed on
        // every subsequent write. A surface that puts its whole state in the
        // revision would otherwise turn durable storage and every request body
        // into a copy of the document.
        if (strlen($token) > 512) {
            throw new UnexpectedValueException('A surface revision marker is longer than 512 bytes; a revision is an identifier, not a payload.');
        }

        return new self($token, $observedFrom);
    }

    /**
     * Pull a revision out of whatever the surface returned, or null.
     *
     * Several key names because this half of the wire is the surface's, and the
     * first consumer's relay is not the only one that will ever be bound.
     * `_meta` is where MCP puts implementation data, so it is checked first and
     * then the top level.
     *
     * @param  array<string, mixed>  $result
     */
    public static function fromResult(array $result, string $observedFrom): ?self
    {
        /** @var array<string, mixed> $meta */
        $meta = is_array($result['_meta'] ?? null) ? $result['_meta'] : [];

        foreach (['revision', 'surfaceRevision', 'surface_revision', 'version', 'etag'] as $key) {
            foreach ([$meta, $result] as $source) {
                $value = $source[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return self::observed($value, $observedFrom);
                }
                // A JSON number that is a whole value, however the host
                // language decoded it. PHP and Python tell int from float and
                // JavaScript does not, so `1.0` arrived here as a float in two
                // languages and an integer in the third — and rejecting it (the
                // original behaviour) meant a surface that serialised a whole
                // revision with a decimal point had its marker DROPPED, the
                // next call went out unpinned, and lost-update protection
                // disappeared for a detail the surface cannot control.
                //
                // Fractional and out-of-range values are refused in all three
                // rather than accepted: `1.5` has no single spelling the three
                // languages agree on (PHP and JavaScript write `1.5`, Python
                // writes `1.5`, but an integral float splits `1` against
                // `1.0`), and a marker that is not byte-identical everywhere is
                // worse than no marker. Pinned by human-plus-change-feed.
                if (is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) <= 9007199254740991)) {
                    return self::observed((string) (int) $value, $observedFrom);
                }
            }
        }

        return null;
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['token' => $this->token, 'observed_from' => $this->observedFrom];
    }
}
