<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Shared helpers for tools: dates in the admin timezone, numbers, limits, store and admin URLs.
 */
class DataFormatter
{
    private array $storeNames = [];

    public function __construct(
        private TimezoneInterface $timezone,
        private Config $config,
        private StoreManagerInterface $storeManager,
        private BackendUrl $backendUrl
    ) {
    }

    /**
     * Admin URL (with secret key) for "open in admin" links in answers.
     */
    public function adminUrl(string $route, array $params = []): string
    {
        return $this->backendUrl->getUrl($route, $params);
    }

    /**
     * Resolves a store view code to its ID. Returns null for an empty code.
     *
     * @throws LocalizedException
     */
    public function resolveStoreId(?string $storeCode): ?int
    {
        $storeCode = trim((string)$storeCode);
        if ($storeCode === '') {
            return null;
        }
        try {
            return (int)$this->storeManager->getStore($storeCode)->getId();
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(__('Unknown store view code "%1".', $storeCode));
        }
    }

    public function storeName($storeId): ?string
    {
        if ($storeId === null || $storeId === '') {
            return null;
        }
        $storeId = (int)$storeId;
        if (!array_key_exists($storeId, $this->storeNames)) {
            try {
                $this->storeNames[$storeId] = (string)$this->storeManager->getStore($storeId)->getName();
            } catch (\Exception $e) {
                $this->storeNames[$storeId] = 'Store #' . $storeId;
            }
        }
        return $this->storeNames[$storeId];
    }

    /**
     * Normalises a string-or-array argument into a list of non-empty strings.
     *
     * @return string[]
     */
    public function stringList($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $values = is_array($value) ? $value : explode(',', (string)$value);
        return array_values(array_filter(array_map(fn($v) => trim((string)$v), $values), fn($v) => $v !== ''));
    }

    public function excerpt(?string $html, int $length = 300): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html))) ?? '');
        if (function_exists('mb_strlen') && mb_strlen($text) > $length) {
            return rtrim(mb_substr($text, 0, $length)) . '...';
        }
        return $text;
    }

    /**
     * Converts a UTC database datetime into the configured admin timezone.
     */
    public function toLocalDate(?string $utcDate): ?string
    {
        if ($utcDate === null || $utcDate === '' || str_starts_with($utcDate, '0000')) {
            return null;
        }
        try {
            $date = new \DateTime($utcDate, new \DateTimeZone('UTC'));
            $date->setTimezone(new \DateTimeZone($this->timezone->getConfigTimezone()));
            return $date->format('Y-m-d H:i');
        } catch (\Exception $e) {
            return $utcDate;
        }
    }

    /**
     * Converts a date or datetime given in the admin timezone (as the model sends it) into UTC.
     *
     * @param string $localDate e.g. "2026-10-01" or "2026-10-01 18:30"
     * @param bool $endOfDay When only a date is given, use 23:59:59 instead of 00:00:00
     */
    public function toUtcDate(string $localDate, bool $endOfDay = false): ?string
    {
        $localDate = trim($localDate);
        if ($localDate === '') {
            return null;
        }
        try {
            $hasTime = (bool)preg_match('/\d{1,2}:\d{2}/', $localDate);
            $date = new \DateTime($localDate, new \DateTimeZone($this->timezone->getConfigTimezone()));
            if (!$hasTime) {
                $endOfDay ? $date->setTime(23, 59, 59) : $date->setTime(0, 0, 0);
            }
            $date->setTimezone(new \DateTimeZone('UTC'));
            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * UTC [from, to] for a date range given in the store timezone, defaulting to the last $defaultDays days.
     *
     * @return string[]
     * @throws LocalizedException
     */
    public function dateRange(string $fromArg, string $toArg, int $defaultDays = 30): array
    {
        $today = new \DateTime('now', new \DateTimeZone($this->timezone->getConfigTimezone()));
        $to = $this->toUtcDate(trim($toArg) !== '' ? $toArg : $today->format('Y-m-d'), true);
        $from = $this->toUtcDate(
            trim($fromArg) !== '' ? $fromArg : $today->modify('-' . ($defaultDays - 1) . ' days')->format('Y-m-d')
        );
        if (!$from || !$to) {
            throw new LocalizedException(__('Dates must look like YYYY-MM-DD.'));
        }
        if ($from > $to) {
            throw new LocalizedException(__('date_from must be before date_to.'));
        }
        return [$from, $to];
    }

    public function getTimezoneName(): string
    {
        return $this->timezone->getConfigTimezone();
    }

    public function money($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return round((float)$value, 2);
    }

    public function qty($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $float = (float)$value;
        return floor($float) === $float ? (float)(int)$float : round($float, 4);
    }

    /**
     * Clamp a "limit" argument from the model to the configured maximum.
     */
    public function limit($requested, int $default = 10): int
    {
        $max = $this->config->getMaxResults();
        $limit = is_numeric($requested) ? (int)$requested : $default;
        return max(1, min($max, $limit));
    }

    /**
     * Escape % and _ for SQL LIKE.
     */
    public function likeValue(string $text): string
    {
        return '%' . addcslashes(trim($text), '%_\\') . '%';
    }
}
