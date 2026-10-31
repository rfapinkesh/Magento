<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Controller\Adminhtml\Audit;

use IceCube\AiAgent\Model\AuditFilters;
use IceCube\AiAgent\Model\AuditLog;
use IceCube\AiAgent\Model\DataFormatter;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Filesystem;

/**
 * Streams the filtered audit log as CSV (same filters as the grid).
 */
class Export extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'IceCube_AiAgent::audit';

    public function __construct(
        Context $context,
        private AuditLog $auditLog,
        private AuditFilters $auditFilters,
        private DataFormatter $formatter,
        private FileFactory $fileFactory,
        private Filesystem $filesystem
    ) {
        parent::__construct($context);
    }

    public function execute(): ResponseInterface
    {
        $filters = $this->auditFilters->getFilters();
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $path = 'export/icecube_aiagent_audit_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.csv';
        $stream = $directory->openFile($path, 'w+');
        $stream->lock();
        $stream->writeCsv(['Time', 'Admin user', 'Question', 'Tool', 'Arguments', 'Result (masked)', 'Chat ID']);
        foreach ($this->auditLog->iterate($filters) as $row) {
            $stream->writeCsv([
                $this->formatter->toLocalDate($row['created_at']),
                $row['username'] ?? ('#' . $row['admin_user_id']),
                $this->safeCell((string)$row['question']),
                $row['tool_name'],
                $this->safeCell((string)$row['tool_args']),
                $this->safeCell((string)$row['content']),
                $row['conversation_id'],
            ]);
        }
        $stream->unlock();
        $stream->close();

        return $this->fileFactory->create(
            basename($path),
            ['type' => 'filename', 'value' => $path, 'rm' => true],
            DirectoryList::VAR_DIR,
            'text/csv'
        );
    }

    /**
     * Stops spreadsheet apps from running cell text that starts like a formula.
     */
    private function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
