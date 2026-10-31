<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\Provider;

/**
 * Provider-neutral view of one model response.
 */
class Result
{
    /**
     * @param string $text Answer text (may be empty when the model only calls tools)
     * @param array[] $toolCalls each: [id, name, input (array)]
     * @param int $inputTokens
     * @param int $outputTokens
     * @param bool $refused The model declined to answer
     * @param mixed $raw Native response, used by the provider to replay the assistant turn
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly bool $refused = false,
        public readonly mixed $raw = null
    ) {
    }
}
