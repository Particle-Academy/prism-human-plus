<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Enums;

/**
 * How much lost-update protection this surface has actually been OBSERVED to
 * have — which is less than "is configured for".
 *
 * ## Why this is not a boolean
 *
 * It was one, and the boolean was wrong in a way that mattered. It answered
 * "does this surface mint revisions", and its documentation claimed "a lost
 * update will be caught". Those are different questions, and the first is the
 * cheap half.
 *
 * The first integrator found it by reading their own surface instead of
 * trusting the contract. They mint a revision on every write result and **do
 * not read an incoming pin anywhere** — no `_meta`, no `If-Match`, no rejection
 * path, no 409. A pinned call is accepted and applied exactly as an unpinned
 * one. So they satisfied the minting half, the detector said `true`, and every
 * update would still have been lost.
 *
 * That is this package's own stated failure mode one level up: a check that
 * reports success because it was asked the question it could answer rather than
 * the one that mattered. So the states now say exactly what was seen, and the
 * one that cannot be proven says so in its name.
 *
 * ## What each state licenses
 *
 * - {@see self::NotObserved} — nothing is known. The surface has not answered.
 * - {@see self::Unavailable} — it has answered and minted nothing. Writes are
 *   unpinned. A concurrent edit WILL be lost, silently. This is the one state
 *   that is a definite negative.
 * - {@see self::Minted} — it mints revisions, so this package pins every call.
 *   **Whether the surface ENFORCES the pin is not observable from here**, and a
 *   surface that mints without enforcing looks identical. Read this as half a
 *   green light.
 * - {@see self::Enforced} — it has actually refused a stale pin. Enforcement is
 *   proven, because it happened.
 *
 * ## Why there is no "require enforcement" mode
 *
 * Tempting and useless. A surface with one writer legitimately never rejects
 * anything, and is indistinguishable from a surface that cannot reject — so a
 * flag demanding proof would refuse every write on a healthy surface until a
 * conflict happened to occur. The only real probe is a deliberately stale call
 * at attach time, which writes nothing if honoured and writes something if not,
 * and doing that automatically is not a thing a library gets to decide.
 *
 * {@see self::Enforced} is therefore evidence when it arrives and never a
 * precondition.
 */
enum ConflictDetection: string
{
    case NotObserved = 'not_observed';
    case Unavailable = 'unavailable';
    case Minted = 'minted';
    case Enforced = 'enforced';

    /** Is a lost update definitely undetectable here? */
    public function isUnprotected(): bool
    {
        return $this === self::Unavailable;
    }

    /** Has this surface been seen to actually refuse a stale pin? */
    public function isProven(): bool
    {
        return $this === self::Enforced;
    }

    /**
     * One sentence saying exactly what is known, for an operator or a log.
     *
     * Here rather than in the consumer so the claim attached to each state is
     * written once. The previous boolean's documentation over-claimed in exactly
     * the gap between two of these.
     */
    public function describe(): string
    {
        return match ($this) {
            self::NotObserved => 'The surface has not answered a call yet, so nothing is known about conflict detection.',
            self::Unavailable => 'The surface mints no revision, so writes are unpinned and a concurrent edit will be lost silently.',
            self::Minted => 'The surface mints revisions and every call is pinned. Whether it ENFORCES the pin is not observable from here.',
            self::Enforced => 'The surface has refused a stale pin, so enforcement is proven rather than assumed.',
        };
    }
}
