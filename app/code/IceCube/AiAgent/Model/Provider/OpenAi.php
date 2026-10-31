<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\Provider;

use IceCube\AiAgent\Model\Config;
use IceCube\AiAgent\Model\OpenAi\Client;

/**
 * OpenAI Responses API (POST /v1/responses) with function calling.
 */
class OpenAi implements ProviderInterface
{
    public function __construct(
        private Client $client,
        private Config $config
    ) {
    }

    public function getLabel(): string
    {
        return 'OpenAI';
    }

    public function buildToolDefinitions(array $tools): array
    {
        $definitions = [];
        foreach ($tools as $tool) {
            $definitions[] = [
                'type' => 'function',
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => $tool->getParameters(),
                'strict' => false,
            ];
        }
        return $definitions;
    }

    public function send(string $system, array $messages, array $toolDefinitions, bool $allowTools): Result
    {
        $body = [
            'model' => $this->config->getModel(Config::PROVIDER_OPENAI),
            'instructions' => $system,
            'input' => $messages,
        ];
        if ($toolDefinitions) {
            $body['tools'] = $toolDefinitions;
            $body['tool_choice'] = $allowTools ? 'auto' : 'none';
            $body['parallel_tool_calls'] = true;
        }
        $effort = $this->config->getReasoningEffort();
        if ($effort !== '') {
            $body['reasoning'] = ['effort' => $effort];
            $body['include'] = ['reasoning.encrypted_content'];
        }

        $response = $this->client->createResponse($body);

        $calls = [];
        foreach (Client::extractFunctionCalls($response) as $call) {
            $input = json_decode($call['arguments'], true);
            $calls[] = [
                'id' => $call['call_id'],
                'name' => $call['name'],
                'input' => is_array($input) ? $input : [],
            ];
        }

        return new Result(
            Client::extractText($response),
            $calls,
            (int)($response['usage']['input_tokens'] ?? 0),
            (int)($response['usage']['output_tokens'] ?? 0),
            false,
            $response
        );
    }

    public function assistantItems(Result $result): array
    {
        return Client::replayableOutput((array)$result->raw);
    }

    public function toolResultItems(array $outputs): array
    {
        $items = [];
        foreach ($outputs as $output) {
            $items[] = [
                'type' => 'function_call_output',
                'call_id' => $output['id'],
                'output' => $output['output'],
            ];
        }
        return $items;
    }
}
