<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\OpenAi;

use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use IceCube\AiAgent\Model\Config;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Minimal client for the OpenAI Responses API (POST /v1/responses).
 *
 * Requests are always sent with store=false so OpenAI does not keep the conversation.
 */
class Client
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

    /**
     * @param array $body Responses API request body (model, input, tools...)
     * @return array Decoded response
     * @throws LocalizedException
     */
    public function createResponse(array $body): array
    {
        $apiKey = $this->config->getApiKey(Config::PROVIDER_OPENAI);
        if ($apiKey === '') {
            throw new LocalizedException(
                __('The OpenAI API key is missing. Add it in Stores > Configuration > IceCube > AI Agent.')
            );
        }

        $body['store'] = false;
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            throw new LocalizedException(__('Could not encode the request for OpenAI.'));
        }

        $client = $this->clientFactory->create(['config' => [
            'base_uri' => $this->config->getBaseUrl(Config::PROVIDER_OPENAI),
            'timeout' => $this->config->getTimeout(),
            'connect_timeout' => 10,
        ]]);

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $response = $client->request('POST', 'responses', [
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
                $this->logger->error('IceCube AI Agent: OpenAI connection failed. ' . $e->getMessage());
                throw new LocalizedException(
                    __('Could not reach OpenAI. Check the server can connect to %1.', $this->config->getBaseUrl(Config::PROVIDER_OPENAI))
                );
            }

            $status = $response->getStatusCode();
            $raw = (string)$response->getBody();

            if (in_array($status, self::RETRY_STATUSES, true) && $attempt <= self::MAX_RETRIES) {
                sleep($attempt * 2);
                continue;
            }

            $data = $this->decode($raw);

            if ($status < 200 || $status >= 300) {
                $message = (string)($data['error']['message'] ?? ('HTTP ' . $status));
                $this->logger->error('IceCube AI Agent: OpenAI error ' . $status . ': ' . $message);
                throw new LocalizedException(__('OpenAI returned an error: %1', $message));
            }

            if (($data['status'] ?? '') === 'failed') {
                $message = (string)($data['error']['message'] ?? 'unknown error');
                throw new LocalizedException(__('OpenAI could not complete the request: %1', $message));
            }

            return $data;
        }
    }

    private function decode(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        try {
            $data = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException $e) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    /**
     * Joins all output_text parts of assistant message items.
     */
    public static function extractText(array $response): string
    {
        $parts = [];
        foreach ($response['output'] ?? [] as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                    $parts[] = (string)$content['text'];
                }
            }
        }
        return trim(implode("\n\n", $parts));
    }

    /**
     * @return array[] function_call items: [call_id, name, arguments]
     */
    public static function extractFunctionCalls(array $response): array
    {
        $calls = [];
        foreach ($response['output'] ?? [] as $item) {
            if (($item['type'] ?? '') === 'function_call') {
                $calls[] = [
                    'call_id' => (string)($item['call_id'] ?? ''),
                    'name' => (string)($item['name'] ?? ''),
                    'arguments' => (string)($item['arguments'] ?? '{}'),
                ];
            }
        }
        return $calls;
    }

    /**
     * Output items prepared for sending back as input on the next request.
     *
     * With store=false, items cannot be referenced by ID, so IDs are removed from
     * messages and function calls. Reasoning items are only kept when they carry
     * encrypted_content (otherwise OpenAI cannot resolve them).
     */
    public static function replayableOutput(array $response): array
    {
        $items = [];
        foreach ($response['output'] ?? [] as $item) {
            $type = $item['type'] ?? '';
            if ($type === 'reasoning') {
                if (!empty($item['encrypted_content'])) {
                    $items[] = $item;
                }
                continue;
            }
            if ($type === 'function_call') {
                $items[] = [
                    'type' => 'function_call',
                    'call_id' => $item['call_id'] ?? '',
                    'name' => $item['name'] ?? '',
                    'arguments' => $item['arguments'] ?? '{}',
                ];
                continue;
            }
            if ($type === 'message') {
                $text = self::extractText(['output' => [$item]]);
                if ($text !== '') {
                    $items[] = ['role' => 'assistant', 'content' => $text];
                }
            }
        }
        return $items;
    }
}
