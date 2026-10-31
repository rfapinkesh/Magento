<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model;

/**
 * Masks customer personal data in tool results before they are sent to the AI provider.
 *
 * Masking is key based (email, telephone, street, postcode...) plus a regex pass
 * for email addresses that appear inside free text such as order comments.
 */
class PiiMasker
{
    private const EMAIL_PATTERN = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    public function __construct(
        private Config $config
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isPiiMaskingEnabled();
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    public function mask($data)
    {
        if (!$this->isEnabled()) {
            return $data;
        }
        return $this->maskValue($data, '');
    }

    /**
     * @param mixed $value
     * @param string $key
     * @return mixed
     */
    private function maskValue($value, string $key)
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $childKey => $childValue) {
                $result[$childKey] = $this->maskValue($childValue, is_string($childKey) ? $childKey : $key);
            }
            return $result;
        }
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $lowerKey = strtolower($key);
        if (str_contains($lowerKey, 'email')) {
            return $this->maskEmail($value);
        }
        if (str_contains($lowerKey, 'telephone') || str_contains($lowerKey, 'phone') || $lowerKey === 'fax') {
            return $this->maskPhone($value);
        }
        if (str_contains($lowerKey, 'street')) {
            return '[street hidden]';
        }
        if (str_contains($lowerKey, 'postcode') || str_contains($lowerKey, 'zip')) {
            return $this->maskPostcode($value);
        }
        if (in_array($lowerKey, ['vat_id', 'taxvat', 'remote_ip', 'dob'], true)) {
            return '[hidden]';
        }

        // Free text (comments, notes): mask any email addresses inside it.
        return (string)preg_replace_callback(
            self::EMAIL_PATTERN,
            fn(array $m) => $this->maskEmail($m[0]),
            $value
        );
    }

    public function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '[hidden]';
        }
        $local = $parts[0];
        $first = function_exists('mb_substr') ? mb_substr($local, 0, 1) : substr($local, 0, 1);
        return $first . '***@' . $parts[1];
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) <= 2) {
            return '***';
        }
        return '***' . substr($digits, -2);
    }

    private function maskPostcode(string $postcode): string
    {
        $clean = trim($postcode);
        if (strlen($clean) <= 2) {
            return '***';
        }
        return substr($clean, 0, 2) . '***';
    }
}
