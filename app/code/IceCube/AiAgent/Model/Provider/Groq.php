<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\Provider;

use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use IceCube\AiAgent\Model\Config;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Groq through its OpenAI-compatible Chat Completions API (POST /openai/v1/chat/completions).
 */
class Groq implements ProviderInterface
{
    private const RETRY_STATUSES = [408, 429, 500, 502, 503, 504];
    private const MAX_RETRIES = 2;

    public function __construct(
        private ClientFactory $clientFactory,
        private Config $config,
        private Json $json,
        private LoggerInterface $logger
    ) {
    }

    public function getLabel(): string
    {
        return 'Groq';
    }

    public function buildToolDefinitions(array $tools): array
    {
        $definitions = [];
        foreach ($tools as $tool) {
            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getParameters(),
                ],
            ];
        }
        return $definitions;
    }

    public function send(string $system, array $messages, array $toolDefinitions, bool $allowTools): Result
    {
        $body = [
            'model' => $this->config->getModel(Config::PROVIDER_GROQ),
            'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages),
        ];
        if ($toolDefinitions) {
            $body['tools'] = $toolDefinitions;
            $body['tool_choice'] = $allowTools ? 'auto' : 'none';
        }

        $response = $this->request($body);
        $message = $response['choices'][0]['message'] ?? [];

        $calls = [];
        foreach ($message['tool_calls'] ?? [] as $call) {
            $input = json_decode((string)($call['function']['arguments'] ?? '{}'), true);
            $calls[] = [
                'id' => (string)($call['id'] ?? ''),
                'name' => (string)($call['function']['name'] ?? ''),
                'input' => is_array($input) ? $input : [],
            ];
        }

        return new Result(
            trim((string)($message['content'] ?? '')),
            $calls,
            (int)($response['usage']['prompt_tokens'] ?? 0),
            (int)($response['usage']['completion_tokens'] ?? 0),
            false,
            $message
        );
    }

    public function assistantItems(Result $result): array
    {
        $message = (array)$result->raw;
        $item = ['role' => 'assistant', 'content' => $message['content'] ?? null];
        if (!empty($message['tool_calls'])) {
            $item['tool_calls'] = $message['tool_calls'];
        }
        return [$item];
    }

    public function toolResultItems(array $outputs): array
    {
        $items = [];
        foreach ($outputs as $output) {
            $items[] = [
                'role' => 'tool',
                'tool_call_id' => $output['id'],
                'content' => $output['output'],
            ];
        }
        return $items;
    }

    /**
     * @throws LocalizedException
     */
    private function request(array $body): array
    {
        $apiKey = $this->config->getApiKey(Config::PROVIDER_GROQ);
        if ($apiKey === '') {
            throw new LocalizedException(__('The Groq API key is missing. Add it in the AI Agent settings.'));
        }

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            throw new LocalizedException(__('Could not encode the request for Groq.'));
        }

        $baseUrl = $this->config->getBaseUrl(Config::PROVIDER_GROQ);
        $client = $this->clientFactory->create(['config' => [
            'base_uri' => $baseUrl,
            'timeout' => $this->config->getTimeout(),
            'connect_timeout' => 10,
        ]]);

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $response = $client->request('POST', 'chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => $payload,
                    'http_errors' => false,
                ]);
            } catch (GuzzleException $e) {
                if ($attempt <= self::MAX_RETRIES) {
                    sleep($attempt);
                    continue;
                }
                $this->logger->error('IceCube AI Agent: Groq connection failed. ' . $e->getMessage());
                throw new LocalizedException(__('Could not reach Groq. Check the server can connect to %1.', $baseUrl));
            }

            $status = $response->getStatusCode();
            $raw = (string)$response->getBody();

            if (in_array($status, self::RETRY_STATUSES, true) && $attempt <= self::MAX_RETRIES) {
                sleep($attempt * 2);
                continue;
            }

            try {
                $data = $raw !== '' ? $this->json->unserialize($raw) : [];
            } catch (\InvalidArgumentException $e) {
                $data = [];
            }
            $data = is_array($data) ? $data : [];

            if ($status < 200 || $status >= 300) {
                $message = (string)($data['error']['message'] ?? ('HTTP ' . $status));
                $this->logger->error('IceCube AI Agent: Groq error ' . $status . ': ' . $message);
                throw new LocalizedException(__('Groq returned an error: %1', $message));
            }

            return $data;
        }
    }
}
