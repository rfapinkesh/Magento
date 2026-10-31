<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\Tool;

/**
 * A read-only lookup the agent can call.
 *
 * Implementations must never save, delete or otherwise change store data.
 */
interface ToolInterface
{
    /**
     * Function name the model calls, e.g. "search_orders".
     */
    public function getName(): string;

    /**
     * Human label for the admin config screen.
     */
    public function getLabel(): string;

    /**
     * Description the model reads to decide when to use the tool.
     */
    public function getDescription(): string;

    /**
     * JSON Schema (as a PHP array) for the tool arguments.
     */
    public function getParameters(): array;

    /**
     * Magento ACL resource the admin role must have, or null for none beyond the chat itself.
     */
    public function getAclResource(): ?string;

    /**
     * Run the lookup. Returns plain data that will be JSON encoded for the model.
     *
     * @param array $args Arguments decoded from the model's function call.
     * @return array
     */
    public function execute(array $args): array;
}
