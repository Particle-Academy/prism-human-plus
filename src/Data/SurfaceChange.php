<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Data;

use Prism\HumanPlus\Enums\ChangeActor;
use Prism\HumanPlus\Enums\ChangeKind;

/**
 * One thing that happened to the surface since a marker.
 *
 * Four fields, and the restraint is the design. This package does not model the
 * surface's data, so it cannot say what a screen IS or how it differs — only
 * that a handle the agent knows about was created, updated, moved or deleted,
 * and by whom. That is enough for an agent to decide whether to re-read before
 * writing, which is the decision the whole feed exists to inform.
 *
 * `label` carries the surface's own word for the thing — a component kind, a
 * title — because "screen_7 moved" reads worse than "chart screen_7 moved" and
 * the surface is the only one who knows which word to use. It is untrusted text
 * from a running application and is guarded on the way out like every other
 * string a surface returns.
 */
final readonly class SurfaceChange
{
    public function __construct(
        /** The surface's own id for the thing that changed. Never parsed here. */
        public string $handle,
        public ChangeKind $kind,
        public ChangeActor $actor,
        /** The surface's own label for it, or empty. Untrusted text. */
        public string $label = '',
    ) {}

    /**
     * Read one change out of whatever the surface returned.
     *
     * Several key names per field, the same reason
     * {@see SurfaceRevision::fromResult()} accepts several: this half of the
     * wire belongs to the surface, and the first consumer's relay will not be
     * the only one ever bound.
     *
     * **`kind` is read from the CHANGE, not from the thing.** A surface that
     * returns both — the first one asked returns `change: "updated"` beside
     * `kind: "chart"`, meaning the component type — would otherwise have its
     * component type parsed as an event type and land on
     * {@see ChangeKind::Unknown} every time. The change keys are checked first
     * and `kind` is only consulted when nothing better is present.
     *
     * @param  array<string, mixed>  $row
     */
    public static function from(array $row): ?self
    {
        $handle = null;

        foreach (['handle', 'id', 'screen_id', 'screenId', 'node_id', 'nodeId', 'key'] as $key) {
            $value = $row[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $handle = trim($value);
                break;
            }
            if (is_int($value)) {
                $handle = (string) $value;
                break;
            }
        }

        if ($handle === null) {
            // A change nobody can point at is not a change this package can
            // hand to an agent. Dropped rather than invented a handle for.
            return null;
        }

        $kind = ChangeKind::Unknown;

        foreach (['change', 'change_kind', 'changeKind', 'event', 'action', 'op', 'kind'] as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }
            $read = ChangeKind::parse($row[$key]);
            if ($read !== ChangeKind::Unknown) {
                $kind = $read;
                break;
            }
        }

        $actor = ChangeActor::Unknown;

        foreach (['actor_type', 'actorType', 'actor', 'by', 'author', 'changed_by', 'changedBy'] as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }
            $read = ChangeActor::parse($row[$key]);
            if ($read !== ChangeActor::Unknown) {
                $actor = $read;
                break;
            }
        }

        $label = '';

        foreach (['label', 'title', 'name', 'component', 'component_kind'] as $key) {
            $value = $row[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $label = trim($value);
                break;
            }
        }

        return new self($handle, $kind, $actor, $label);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'kind' => $this->kind->value,
            'actor' => $this->actor->value,
            'label' => $this->label,
        ];
    }
}
