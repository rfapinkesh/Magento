<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Block\Adminhtml;

use IceCube\AiAgent\Model\AuditFilters;
use IceCube\AiAgent\Model\AuditLog;
use IceCube\AiAgent\Model\DataFormatter;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Audit log of every tool call the AI Assistant made, with filters, paging and CSV export.
 */
class Audit extends Template
{
    private ?array $result = null;

    public function __construct(
        Context $context,
        private AuditLog $auditLog,
        private AuditFilters $auditFilters,
        private DataFormatter $formatter,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getValues(): array
    {
        return $this->auditFilters->getValues();
    }

    public function getResult(): array
    {
        if ($this->result === null) {
            $this->result = $this->auditLog->search($this->auditFilters->getFilters(), $this->getPage());
        }
        return $this->result;
    }

    public function getPage(): int
    {
        return $this->auditFilters->getPage();
    }

    public function getPageCount(): int
    {
        return max(1, (int)ceil($this->getResult()['total'] / AuditLog::PAGE_SIZE));
    }

    public function getPageUrl(int $page): string
    {
        return $this->getUrl('*/*/*', ['_query' => array_filter($this->getValues()) + ['p' => $page]]);
    }

    public function getExportUrl(): string
    {
        return $this->getUrl('*/*/export', ['_query' => array_filter($this->getValues())]);
    }

    public function getFilterUrl(): string
    {
        return $this->getUrl('*/*/*');
    }

    public function getToolNames(): array
    {
        return $this->auditLog->getToolNames();
    }

    public function getAdminUsers(): array
    {
        return $this->auditLog->getAdminUsers();
    }

    public function localDate(?string $utcDate): string
    {
        return (string)$this->formatter->toLocalDate($utcDate);
    }

    /**
     * Pretty-printed JSON for the expandable detail cell.
     */
    public function prettyJson(?string $json): string
    {
        $decoded = json_decode((string)$json, true);
        if (!is_array($decoded)) {
            return (string)$json;
        }
        return (string)json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function shorten(?string $text, int $length = 120): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string)$text) ?? '');
        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length)) . '...' : $text;
    }
}
