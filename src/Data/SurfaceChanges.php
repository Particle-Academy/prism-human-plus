<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Data;

use Prism\HumanPlus\Enums\ChangeActor;
use Prism\HumanPlus\Enums\ChangeFeed;

/**
 * What a surface said changed since a marker — and, first, whether it was in
 * any position to say.
 *
 * ## The empty list is the dangerous value
 *
 * "Nothing changed since your marker" and "I cannot answer that question" are
 * the same empty array on the wire. Returning a bare list would make them
 * indistinguishable, and an agent reading an empty list as calm is precisely
 * the agent that reverts a human's edit believing it is fixing drift.
 *
 * So {@see self::$feed} comes first and {@see self::answered()} is the question
 * to ask before {@see self::$changes} means anything. This package has already
 * shipped the other mistake once, in `conflictDetection()`, and the fix was the
 * same shape: say what was observed, and name the state that cannot be proven.
 *
 * ## Incomplete feeds are a real case, not a hypothetical
 *
 * The first surface asked can report creates, updates and layout moves since a
 * marker, and **cannot report a delete at all** — the row is hard-deleted, the
 * head does not advance, and there is no tombstone. So "nothing changed" is
 * what it says when a screen was destroyed.
 *
 * A package cannot detect that from outside. What it can do is let a surface
 * SAY so, and treat the admission as load-bearing: {@see self::$complete} is
 * false when the surface declares its feed partial, and a caller that ignores
 * it is ignoring something the surface volunteered.
 */
final readonly class SurfaceChanges
{
    /** @param  list<SurfaceChange>  $changes */
    public function __construct(
        public ChangeFeed $feed,
        public array $changes = [],
        /** The marker these changes are current as of — hand it back next turn. */
        public ?SurfaceRevision $revision = null,
        /**
         * Did the surface claim this answer covers everything that happened?
         *
         * False when it declared the feed partial. Also false when the feed
         * cannot answer at all, because an unanswered question is not a
         * complete one.
         */
        public bool $complete = true,
    ) {}

    /** No feed here. Nothing below this means anything. */
    public static function unavailable(): self
    {
        return new self(ChangeFeed::Unavailable, [], null, false);
    }

    /**
     * Did the surface actually answer the question?
     *
     * **Check this before reading {@see self::$changes}.** An empty list from a
     * surface that has no feed is not evidence of quiet.
     */
    public function answered(): bool
    {
        return $this->feed->isAnswerable();
    }

    /**
     * Is it safe to conclude that nothing changed?
     *
     * True only when the surface could answer, did answer, said nothing
     * changed, and did not warn that its answer is partial. Every other
     * combination returns false, including the one that looks identical on the
     * wire.
     */
    public function nothingChanged(): bool
    {
        return $this->answered() && $this->complete && $this->changes === [];
    }

    /**
     * Can this surface tell one hand from another at all?
     *
     * The question to ask before keying any behaviour on an actor. A surface
     * whose every write path is an agent tool answers no, and on such a surface
     * every change is {@see ChangeActor::Unknown} for a structural reason
     * rather than a per-change one.
     */
    public function attributes(): bool
    {
        return $this->feed->isProven();
    }

    /**
     * The changes an agent should leave alone rather than correct.
     *
     * **A change is deferred to unless the surface positively said this agent
     * made it.** One rule, and it lands correctly in both worlds:
     *
     * - a surface that cannot attribute reports every change as
     *   {@see ChangeActor::Unknown}, so all of them are deferred to — not
     *   because they are all a person's, but because none can be shown to be
     *   the agent's own, and undoing a person's work is the expensive mistake;
     * - a surface that can attribute gets its answer used, and a change it
     *   marked as the agent's own is excluded.
     *
     * An earlier draft branched on {@see self::attributes()} and deferred to
     * EVERY change on a feed that had not yet proven attribution — which threw
     * away a per-change answer the surface had already given. Ignoring
     * information you were handed is a different mistake from not having it.
     *
     * @return list<SurfaceChange>
     */
    public function deferTo(): array
    {
        if (! $this->answered()) {
            return [];
        }

        return array_values(array_filter(
            $this->changes,
            static fn (SurfaceChange $change): bool => $change->actor->deservesDeference(),
        ));
    }

    /** Every handle that moved, for an agent deciding what to re-read. */
    /** @return list<string> */
    public function handles(): array
    {
        return array_values(array_unique(array_map(
            static fn (SurfaceChange $change): string => $change->handle,
            $this->changes,
        )));
    }

    /**
     * One sentence an agent or an operator can act on.
     *
     * Deliberately says what is NOT known as plainly as what is. A summary that
     * reads "no changes" for an unanswerable feed would be this package's own
     * failure mode written into its logs.
     */
    public function describe(): string
    {
        if (! $this->answered()) {
            return $this->feed->describe();
        }

        $count = count($this->changes);
        $summary = $count === 0
            ? 'The surface reports no changes since the last marker.'
            : sprintf('The surface reports %d change(s) since the last marker.', $count);

        if (! $this->complete) {
            $summary .= ' The surface declared this answer PARTIAL, so some changes are not in it.';
        }

        if (! $this->attributes()) {
            $summary .= ' It has never named an actor, so who made these changes is not known here.';
        }

        return $summary;
    }
}
