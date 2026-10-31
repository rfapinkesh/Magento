<?php
namespace OneDev\Ai\Block\Html;

use Magento\Store\Model\ScopeInterface;

class Header extends \Magento\Theme\Block\Html\Header
{
    /**
     * Return the store name configured in the backend.
     *
     * @param string|null $storeCode optional store code, or null for current
     * @return string
     */
    public function getStoreName(?string $storeCode = null): string
    {
        return (string) $this->_scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE,
            $storeCode
        );
    }

    /**
     * Convenience method that returns a translatable phrase mentioning
     * the shop by name.
     *
     * The template should escape the output if it is rendered directly.
     *
     * @param string|null $storeCode
     * @return \Magento\Framework\Phrase
     */
    public function mention(?string $storeCode = null): \Magento\Framework\Phrase
    {
        return __('This shop is called %1.', $this->getStoreName($storeCode));
    }
}