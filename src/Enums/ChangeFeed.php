<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Enums;

/**
 * How much this surface has actually been OBSERVED to be able to say about what
 * changed — which, as with {@see ConflictDetection}, is less than "offers a
 * tool for it".
 *
 * ## Why this is not a boolean, for the second time in this package
 *
 * `conflictDetection()` was a boolean once. It answered "does this surface mint
 * revisions" while its documentation claimed "a lost update will be caught",
 * and the first integrator minted without enforcing — so the detector said
 * `true` and every update would still have been lost. The fix was to say
 * exactly what had been seen and to name the state that cannot be proven.
 *
 * A change feed has the same shape of trap twice over:
 *
 * 1. **An empty answer is ambiguous.** "Nothing changed since your marker" and
 *    "I cannot answer that question" are the same empty list on the wire. If
 *    this package returned one value for both, silence would read as calm — and
 *    an agent that reads silence as calm is exactly the agent that reverts a
 *    human's edit believing it is fixing drift.
 * 2. **A feed without attribution cannot prevent the thing it exists for.**
 *    Knowing a handle moved does not tell an agent whether a PERSON moved it
 *    deliberately or whether it is looking at its own last write. Only the
 *    second answer stops the revert.
 *
 * So the states say what was seen, and the one that cannot be proven in advance
 * says so.
 *
 * ## What each state licenses
 *
 * - {@see self::NotObserved} — nothing is known. The surface has not been asked
 *   for its tools yet.
 * - {@see self::Unavailable} — it has answered and offers no change feed. "What
 *   changed since my last turn" is UNANSWERABLE here, and an agent must not
 *   read the absence as quiet. This is the one definite negative.
 * - {@see self::Offered} — a feed exists and can be called. Whether it names
 *   WHO changed something is not observable until something actually changes,
 *   and a feed that never attributes looks identical until then. Half a green
 *   light.
 * - {@see self::Attributed} — a change has come back naming an actor other than
 *   this agent. Attribution is proven, because it happened.
 *
 * ## Why there is no "require attribution" mode
 *
 * The same reason there is no "require enforcement" mode. A surface nobody else
 * is editing legitimately never reports a human change, and is indistinguishable
 * from one that cannot report it — so a flag demanding proof would refuse every
 * turn on a healthy surface until a person happened to touch it.
 *
 * {@see self::Attributed} is evidence when it arrives and never a precondition.
 */
enum ChangeFeed: string
{
    case NotObserved = 'not_observed';
    case Unavailable = 'unavailable';
    case Offered = 'offered';
    case Attributed = 'attributed';

    /**
     * Can this surface answer "what changed since X" at all?
     *
     * The question a caller should ask BEFORE reading an empty change list as
     * good news.
     */
    public function isAnswerable(): bool
    {
        return $this === self::Offered || $this === self::Attributed;
    }

    /** Is "what changed" definitely unanswerable here? */
    public function isUnavailable(): bool
    {
        return $this === self::Unavailable;
    }

    /** Has this surface been seen to actually name someone else as an actor? */
    public function isProven(): bool
    {
        return $this === self::Attributed;
    }

    /**
     * One sentence saying exactly what is known, for an operator or a log.
     *
     * Here rather than in the consumer so the claim attached to each state is
     * written once — the lesson {@see ConflictDetection} learned when a
     * docblock over-claimed in the gap between two of its states.
     */
    public function describe(): string
    {
        return match ($this) {
            self::NotObserved => 'The surface has not listed its tools yet, so nothing is known about a change feed.',
            self::Unavailable => 'The surface offers no change feed, so what a human changed cannot be known here. An empty answer is not evidence that nothing changed.',
            self::Offered => 'The surface offers a change feed. Whether it names WHO made a change is not observable until something changes.',
            self::Attributed => 'The surface has reported a change made by someone other than this agent, so attribution is proven rather than assumed.',
        };
    }
}
