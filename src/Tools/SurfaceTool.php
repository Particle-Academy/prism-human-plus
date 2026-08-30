<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Tools;

use Prism\HumanPlus\Data\ToolDefinition;
use Prism\HumanPlus\HumanPlusManager;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Tool;

final class SurfaceTool extends Tool
{
    public function __construct(
        private readonly HumanPlusManager $humanPlus,
        private readonly string|object $owner,
        private readonly string $attachmentId,
        private readonly ToolDefinition $definition,
        bool $requiresApproval,
    ) {
        parent::__construct();
        $this->as($definition->name)->for($definition->description)->requiresApproval($requiresApproval);
        $required = $definition->inputSchema['required'] ?? [];
        $properties = $definition->inputSchema['properties'] ?? [];
        if (is_array($properties)) {
            foreach ($properties as $name => $schema) {
                if (is_string($name) && is_array($schema)) {
                    $this->withParameter(new RawSchema($name, $schema), is_array($required) && in_array($name, $required, true));
                }
            }
        }
    }

    public function __invoke(mixed ...$arguments): string
    {
        /** @var array<string, mixed> $arguments */
        return $this->humanPlus->call($this->owner, $this->attachmentId, $this->definition->name, $arguments);
    }
}
