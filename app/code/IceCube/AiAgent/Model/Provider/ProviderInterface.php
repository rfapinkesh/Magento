<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\Provider;

use IceCube\AiAgent\Model\Tool\ToolInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * One AI backend (OpenAI, Claude, Groq). The agent loop only talks to this interface;
 * each provider keeps the conversation in its own native message format.
 */
interface ProviderInterface
{
    /**
     * Name shown in admin messages, e.g. "OpenAI".
     */
    public function getLabel(): string;

    /**
     * @param ToolInterface[] $tools
     * @return array Tool definitions in the provider's format
     */
    public function buildToolDefinitions(array $tools): array;

    /**
     * @param string $system System prompt
     * @param array $messages Conversation so far, in the provider's format
     * @param array $toolDefinitions From buildToolDefinitions()
     * @param bool $allowTools False forces a plain text answer
     * @throws LocalizedException
     */
    public function send(string $system, array $messages, array $toolDefinitions, bool $allowTools): Result;

    /**
     * Items that replay the assistant turn (text, reasoning, tool calls) on the next request.
     */
    public function assistantItems(Result $result): array;

    /**
     * Items that return tool outputs to the model.
     *
     * @param array[] $outputs each: [id, output (JSON string), is_error]
     */
    public function toolResultItems(array $outputs): array;
}
