<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Enums;

/**
 * What kind of change happened to a handle.
 *
 * Coarse on purpose. This package does not model the surface's data, so it
 * cannot describe a change in the surface's own terms and should not pretend
 * to. What it can carry is the shape of the event, which is enough for an agent
 * to decide whether to re-read before writing.
 *
 * {@see self::Moved} earns its place separately from {@see self::Updated}
 * because it is the silent one. The first outside consumer predicted reordering
 * would bite before any content conflict: a human reorders a surface, every
 * handle stays valid, every position is now wrong, and nothing errors. An agent
 * told only "updated" has no reason to re-read positions it believes it set.
 */
enum ChangeKind: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    /** Reordered or re-parented. The handle is still valid and means something else. */
    case Moved = 'moved';
    /** The surface reported a change and did not say what kind. */
    case Unknown = 'unknown';

    /**
     * Map whatever the surface called it onto a case, without guessing.
     *
     * Unrecognised is {@see self::Unknown}, never a default case — the same
     * rule as {@see ChangeActor::parse()}, for the same reason.
     */
    public static function parse(mixed $value): self
    {
        if (! is_string($value)) {
            return self::Unknown;
        }

        return match (strtolower(trim($value))) {
            'created', 'create', 'added', 'add', 'inserted' => self::Created,
            'updated', 'update', 'changed', 'edited', 'modified' => self::Updated,
            'deleted', 'delete', 'removed', 'remove' => self::Deleted,
            'moved', 'move', 'reordered', 'reorder', 'reparented' => self::Moved,
            default => self::Unknown,
        };
    }

    /** Is the handle still usable after this change? */
    public function leavesHandleValid(): bool
    {
        return $this !== self::Deleted;
    }
}
