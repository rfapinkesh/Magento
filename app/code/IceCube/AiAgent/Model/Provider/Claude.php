<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\Provider;

use Anthropic\Client as SdkClient;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\RateLimitException;
use IceCube\AiAgent\Model\Config;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Claude Messages API through the official Anthropic PHP SDK.
 *
 * The SDK retries 408/409/429/5xx and connection errors itself (2 retries by default).
 */
class Claude implements ProviderInterface
{
    // Routes a refused request to the model's server-defined fallback instead of stopping.
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';
    private const MAX_TOKENS = 16000;

    private ?SdkClient $sdk = null;

    public function __construct(
        private Config $config,
        private LoggerInterface $logger
    ) {
    }

    public function getLabel(): string
    {
        return 'Claude';
    }

    public function buildToolDefinitions(array $tools): array
    {
        $definitions = [];
        foreach ($tools as $tool) {
            $definitions[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getParameters(),
            ];
        }
        return $definitions;
    }

    public function send(string $system, array $messages, array $toolDefinitions, bool $allowTools): Result
    {
        $params = [
            'maxTokens' => self::MAX_TOKENS,
            'model' => $this->config->getModel(Config::PROVIDER_CLAUDE),
            'system' => $system,
            'messages' => $messages,
            'outputConfig' => ['effort' => $this->config->getClaudeEffort()],
            'fallbacks' => 'default',
            'betas' => [self::FALLBACK_BETA],
            // Caches tools + system + earlier turns, so each tool round only pays for the new turn.
            'cacheControl' => ['type' => 'ephemeral'],
            'requestOptions' => ['timeout' => (float)$this->config->getTimeout()],
        ];
        if ($toolDefinitions) {
            $params['tools'] = $toolDefinitions;
            $params['toolChoice'] = $allowTools ? ['type' => 'auto'] : ['type' => 'none'];
        }

        try {
            $message = $this->getSdk()->beta->messages->create(...$params);
        } catch (AuthenticationException $e) {
            $this->logger->error('IceCube AI Agent: Claude rejected the API key. ' . $e->getMessage());
            throw new LocalizedException(__('Claude rejected the API key. Check it in the AI Agent settings.'));
        } catch (NotFoundException $e) {
            $this->logger->error('IceCube AI Agent: Claude model not found. ' . $e->getMessage());
            throw new LocalizedException(
                __('The Claude model "%1" was not found. Check the Model setting.', $params['model'])
            );
        } catch (RateLimitException $e) {
            $this->logger->warning('IceCube AI Agent: Claude rate limit. ' . $e->getMessage());
            throw new LocalizedException(__('Claude is busy right now (rate limit). Please try again in a minute.'));
        } catch (APIStatusException $e) {
            $this->logger->error('IceCube AI Agent: Claude error ' . $e->status . '. ' . $e->getMessage());
            $detail = $e->getMessage();
            if (stripos($detail, 'credit balance') !== false) {
                $detail = (string)__('the Anthropic account is out of credits. Add credits at platform.claude.com.');
            }
            throw new LocalizedException(__('Claude returned an error: %1', $detail));
        } catch (APIConnectionException $e) {
            $this->logger->error('IceCube AI Agent: Claude connection failed. ' . $e->getMessage());
            throw new LocalizedException(__('Could not reach Claude. Check the server can connect to api.anthropic.com.'));
        }

        $text = [];
        $calls = [];
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text[] = (string)$block->text;
            } elseif ($block->type === 'tool_use') {
                $calls[] = [
                    'id' => (string)$block->id,
                    'name' => (string)$block->name,
                    'input' => is_array($block->input) ? $block->input : [],
                ];
            }
        }
        if ($message->stopReason !== 'tool_use') {
            $calls = [];
        }

        $usage = $message->usage;
        return new Result(
            trim(implode("\n\n", $text)),
            $calls,
            $usage->inputTokens + (int)$usage->cacheReadInputTokens + (int)$usage->cacheCreationInputTokens,
            $usage->outputTokens,
            $message->stopReason === 'refusal',
            $message
        );
    }

    public function assistantItems(Result $result): array
    {
        // The content goes back unchanged so thinking and tool_use blocks stay valid.
        return [['role' => 'assistant', 'content' => $result->raw->content]];
    }

    public function toolResultItems(array $outputs): array
    {
        // All results of one turn go back in a single user message.
        $content = [];
        foreach ($outputs as $output) {
            $content[] = [
                'type' => 'tool_result',
                'toolUseID' => $output['id'],
                'content' => $output['output'],
                'isError' => $output['is_error'],
            ];
        }
        return [['role' => 'user', 'content' => $content]];
    }

    /**
     * @throws LocalizedException
     */
    private function getSdk(): SdkClient
    {
        if ($this->sdk === null) {
            $apiKey = $this->config->getApiKey(Config::PROVIDER_CLAUDE);
            if ($apiKey === '') {
                throw new LocalizedException(__('The Claude API key is missing. Add it in the AI Agent settings.'));
            }
            $baseUrl = $this->config->getBaseUrl(Config::PROVIDER_CLAUDE);
            $this->sdk = new SdkClient(apiKey: $apiKey, baseUrl: $baseUrl !== '' ? rtrim($baseUrl, '/') : null);
        }
        return $this->sdk;
    }
}
