<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Exceptions;

use Prism\HumanPlus\Contracts\HasErrorCode;
use Prism\HumanPlus\HumanPlusManager;

/**
 * The surface said the revision we pinned a call to is stale.
 *
 * A transport- and protocol-level signal with no tool context, raised where the
 * rejection is actually recognised — an HTTP 409 in the relay, or a JSON-RPC
 * error carrying a precondition code. {@see HumanPlusManager}
 * catches it and re-raises {@see SurfaceChangedUnderYou}, which is the failure
 * written for the agent to read.
 *
 * Two classes rather than one because the two layers know different things. The
 * transport knows a status code and nothing about tools; the manager knows which
 * tool was called and which revision it sent. Collapsing them would mean either
 * threading tool names into the transport or losing them from the message.
 */
final class SurfaceRevisionRejected extends HumanPlusException implements HasErrorCode
{
    public function code(): string
    {
        return 'surface_revision_rejected';
    }
}
