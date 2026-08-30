<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Tools;

use Prism\HumanPlus\Data\ToolDefinition;
use Prism\HumanPlus\HumanPlusManager;

final readonly class HumanPlusToolset
{
    public function __construct(private HumanPlusManager $humanPlus) {}

    /**
     * Approval decisions are local policy. Remote MCP annotations are not used.
     *
     * @param  list<string>  $approvalTools
     * @return list<SurfaceTool>
     */
    public function forAttachment(string|object $owner, string $attachmentId, array $approvalTools = []): array
    {
        return array_map(
            fn (ToolDefinition $definition): SurfaceTool => new SurfaceTool($this->humanPlus, $owner, $attachmentId, $definition, in_array($definition->name, $approvalTools, true)),
            $this->humanPlus->tools($owner, $attachmentId),
        );
    }
}
