<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Block\Adminhtml;

use IceCube\AiAgent\Model\Config;
use IceCube\AiAgent\Model\DataFormatter;
use IceCube\AiAgent\Model\UsageReport;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Usage and cost dashboard for the AI Assistant.
 */
class Usage extends Template
{
    public const PERIODS = [7, 30, 90, 365];

    private ?array $range = null;

    public function __construct(
        Context $context,
        private UsageReport $report,
        private DataFormatter $formatter,
        private Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getDays(): int
    {
        $days = (int)$this->getRequest()->getParam('days', 30);
        return in_array($days, self::PERIODS, true) ? $days : 30;
    }

    public function getPeriodUrl(int $days): string
    {
        return $this->getUrl('*/*/*', ['days' => $days]);
    }

    public function getSummary(): array
    {
        return $this->report->getSummary(...$this->getRange());
    }

    /**
     * Every day of the period, including days without questions.
     *
     * @return array[]
     */
    public function getDaily(): array
    {
        [$from, $to] = $this->getRange();
        $zone = new \DateTimeZone($this->formatter->getTimezoneName());
        $offset = $zone->getOffset(new \DateTime($to, new \DateTimeZone('UTC')));
        $rows = [];
        foreach ($this->report->getByDay($from, $to, $offset) as $row) {
            $rows[$row['day']] = $row;
        }

        $days = [];
        $day = new \DateTime($this->formatter->toLocalDate($from) ?? 'now', $zone);
        for ($i = 0; $i < $this->getDays(); $i++) {
            $key = $day->format('Y-m-d');
            $days[] = $rows[$key] ?? ['day' => $key, 'questions' => 0, 'tool_calls' => 0,
                'input_tokens' => 0, 'output_tokens' => 0];
            $day->modify('+1 day');
        }
        return $days;
    }

    public function getByAdmin(): array
    {
        return $this->report->getByAdmin(...$this->getRange());
    }

    public function getByTool(): array
    {
        return $this->report->getByTool(...$this->getRange());
    }

    public function getByModel(): array
    {
        return $this->report->getByModel(...$this->getRange());
    }

    public function getActiveProviderLabel(): string
    {
        return $this->config->getProviderLabel() . ' / ' . $this->config->getModel();
    }

    public function localDate(?string $utcDate): string
    {
        return (string)$this->formatter->toLocalDate($utcDate);
    }

    public function formatNumber(int|float $value): string
    {
        return number_format((float)$value, 0, '.', ',');
    }

    public function formatCost(?float $cost): string
    {
        return $cost === null ? '-' : '$' . number_format($cost, $cost < 1 ? 4 : 2, '.', ',');
    }

    public function getSettingsUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'icecube_aiagent']);
    }

    public function getAuditUrl(array $params = []): string
    {
        return $this->getUrl('icecube_aiagent/audit/index', $params);
    }

    /**
     * @return string[] UTC [from, to]
     */
    private function getRange(): array
    {
        if ($this->range === null) {
            $this->range = $this->formatter->dateRange('', '', $this->getDays());
        }
        return $this->range;
    }
}
