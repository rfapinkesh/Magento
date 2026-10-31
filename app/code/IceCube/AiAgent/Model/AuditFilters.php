<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model;

use Magento\Framework\App\RequestInterface;

/**
 * Turns the audit log's GET filters into AuditLog filters (dates in the store timezone become UTC).
 */
class AuditFilters
{
    public const PARAMS = ['admin_user_id', 'tool_name', 'date_from', 'date_to', 'search'];

    public function __construct(
        private RequestInterface $request,
        private DataFormatter $formatter
    ) {
    }

    /**
     * Raw values as typed in the filter form.
     *
     * @return array<string, string>
     */
    public function getValues(): array
    {
        $values = [];
        foreach (self::PARAMS as $param) {
            $values[$param] = trim((string)$this->request->getParam($param, ''));
        }
        return $values;
    }

    public function getFilters(): array
    {
        $values = $this->getValues();
        return [
            'admin_user_id' => (int)$values['admin_user_id'] ?: null,
            'tool_name' => $values['tool_name'] ?: null,
            'from' => $values['date_from'] !== '' ? $this->formatter->toUtcDate($values['date_from']) : null,
            'to' => $values['date_to'] !== '' ? $this->formatter->toUtcDate($values['date_to'], true) : null,
            'search' => $values['search'] ?: null,
        ];
    }

    public function getPage(): int
    {
        return max(1, (int)$this->request->getParam('p', 1));
    }
}
