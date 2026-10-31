<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Typed access to Stores > Configuration > IceCube > AI Agent.
 */
class Config
{
    private const PREFIX = 'icecube_aiagent/';

    public const PROVIDER_OPENAI = 'openai';
    public const PROVIDER_CLAUDE = 'anthropic';
    public const PROVIDER_GROQ = 'groq';

    /** Per provider (keyed by its config group): name, default model, default base URL ('' = SDK default). */
    private const PROVIDERS = [
        self::PROVIDER_OPENAI => [
            'label' => 'OpenAI',
            'model' => 'gpt-5.6-sol',
            'base_url' => 'https://api.openai.com/v1',
        ],
        self::PROVIDER_CLAUDE => [
            'label' => 'Claude',
            'model' => 'claude-opus-5-5',
            'base_url' => '',
        ],
        self::PROVIDER_GROQ => [
            'label' => 'Groq',
            'model' => 'llama-3.3-70b-versatile',
            'base_url' => 'https://api.groq.com/openai/v1',
        ],
    ];

    private const CLAUDE_EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public const HARD_MAX_RESULTS = 100;
    public const HARD_MAX_TOOL_CALLS = 25;

    public function __construct(
        private ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'general/enabled');
    }

    /**
     * The selected AI provider code (openai, anthropic or groq).
     */
    public function getProvider(): string
    {
        $provider = (string)$this->getValue('general/provider');
        return isset(self::PROVIDERS[$provider]) ? $provider : self::PROVIDER_OPENAI;
    }

    public function getProviderLabel(?string $provider = null): string
    {
        return self::PROVIDERS[$provider ?? $this->getProvider()]['label'];
    }

    /**
     * The Encrypted backend_model declared in config.xml makes Magento decrypt the value while loading
     * config, so scopeConfig already returns the plain key — decrypting again turns it into binary garbage.
     */
    public function getApiKey(?string $provider = null): string
    {
        return trim((string)$this->getValue(($provider ?? $this->getProvider()) . '/api_key'));
    }

    public function hasApiKey(?string $provider = null): bool
    {
        return $this->getApiKey($provider) !== '';
    }

    public function getModel(?string $provider = null): string
    {
        $provider = $provider ?? $this->getProvider();
        $model = trim((string)$this->getValue($provider . '/model'));
        return $model !== '' ? $model : self::PROVIDERS[$provider]['model'];
    }

    /**
     * OpenAI only. Returns low|medium|high, or an empty string when reasoning is switched off.
     */
    public function getReasoningEffort(): string
    {
        $effort = (string)$this->getValue('openai/reasoning_effort');
        return in_array($effort, ['low', 'medium', 'high'], true) ? $effort : '';
    }

    /**
     * Claude only: low|medium|high|xhigh|max.
     */
    public function getClaudeEffort(): string
    {
        $effort = (string)$this->getValue('anthropic/effort');
        return in_array($effort, self::CLAUDE_EFFORTS, true) ? $effort : 'low';
    }

    /**
     * With a trailing slash for Guzzle base_uri; '' for Claude means the SDK default.
     */
    public function getBaseUrl(?string $provider = null): string
    {
        $provider = $provider ?? $this->getProvider();
        $url = trim((string)$this->getValue($provider . '/base_url'));
        if ($url === '') {
            $url = self::PROVIDERS[$provider]['base_url'];
        }
        return $url === '' ? '' : rtrim($url, '/') . '/';
    }

    /**
     * Prices per million tokens for the usage dashboard's cost estimate, or null when none is set.
     *
     * @return array{input: float, output: float}|null
     */
    public function getPrices(string $provider): ?array
    {
        $input = trim((string)$this->getValue($provider . '/price_input'));
        $output = trim((string)$this->getValue($provider . '/price_output'));
        if (!is_numeric($input) && !is_numeric($output)) {
            return null;
        }
        return ['input' => (float)$input, 'output' => (float)$output];
    }

    public function getTimeout(): int
    {
        return max(5, (int)$this->getValue('general/timeout') ?: 60);
    }

    public function getMaxToolCalls(): int
    {
        $value = (int)$this->getValue('agent/max_tool_calls') ?: 8;
        return max(1, min(self::HARD_MAX_TOOL_CALLS, $value));
    }

    public function getMaxResults(): int
    {
        $value = (int)$this->getValue('agent/max_results') ?: 20;
        return max(1, min(self::HARD_MAX_RESULTS, $value));
    }

    public function getHistoryMessages(): int
    {
        return max(0, min(50, (int)$this->getValue('agent/history_messages')));
    }

    public function getCustomInstructions(): string
    {
        return trim((string)$this->getValue('agent/custom_instructions'));
    }

    /**
     * @return string[]
     */
    public function getEnabledTools(): array
    {
        $value = (string)$this->getValue('tools/enabled_tools');
        if ($value === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    public function isPiiMaskingEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'privacy/mask_pii');
    }

    public function getRateLimitPerHour(): int
    {
        return max(0, (int)$this->getValue('privacy/rate_limit_per_hour'));
    }

    public function getRetentionDays(): int
    {
        return max(0, (int)$this->getValue('privacy/retention_days'));
    }

    /**
     * @return mixed
     */
    private function getValue(string $path)
    {
        return $this->scopeConfig->getValue(self::PREFIX . $path);
    }
}
