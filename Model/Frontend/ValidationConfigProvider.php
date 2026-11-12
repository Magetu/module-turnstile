<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Model\Frontend;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\UrlInterface;
use Magento\ReCaptchaUi\Model\ValidationConfigProviderInterface;
use Magento\ReCaptchaValidationApi\Api\Data\ValidationConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magetu\Turnstile\Model\ValidationConfigFactory;

/**
 * Storefront validation config for the `magetu_turnstile` type.
 *
 * Derived from module-re-captcha-version-3-invisible/Model/Frontend/ValidationConfigProvider.php.
 * get() always builds a FRESH ValidationConfig via the generated factory: Plugin\InjectExpectedAction
 * mutates the returned instance, so memoising one here would let a validation for one
 * captcha id run against whatever action a different surface injected earlier on the same page.
 */
class ValidationConfigProvider implements ValidationConfigProviderInterface
{
    private const XML_PATH_PRIVATE_KEY = 'recaptcha_frontend/type_magetu_turnstile/private_key';
    private const XML_PATH_VALIDATION_FAILURE = 'recaptcha_frontend/type_magetu_turnstile/validation_failure_message';
    private const XML_PATH_SEND_REMOTE_IP = 'recaptcha_frontend/type_magetu_turnstile/send_remote_ip';
    private const XML_PATH_VALIDATE_ACTION = 'recaptcha_frontend/type_magetu_turnstile/validate_action';
    private const XML_PATH_ALLOWED_HOSTNAMES = 'recaptcha_frontend/type_magetu_turnstile/allowed_hostnames';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param RemoteAddress $remoteAddress Same IP source module-re-captcha-version-3-invisible uses.
     * @param StoreManagerInterface $storeManager Supplies the fallback allowed-hostname host: the
     *        current store's own base URL, never the request Host header.
     * @param ValidationConfigFactory $validationConfigFactory
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly RemoteAddress $remoteAddress,
        private readonly StoreManagerInterface $storeManager,
        private readonly ValidationConfigFactory $validationConfigFactory
    ) {
    }

    /**
     * Turnstile secret key.
     *
     * @return string
     */
    private function getPrivateKey(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_PRIVATE_KEY, ScopeInterface::SCOPE_WEBSITE));
    }

    /**
     * Validation failure message.
     *
     * Kept only because the interface member is non-nullable; see the class doc on
     * Model\ValidationConfig for why it does not control what the visitor sees.
     *
     * @return string
     */
    private function getValidationFailureMessage(): string
    {
        return trim(
            (string)$this->scopeConfig->getValue(self::XML_PATH_VALIDATION_FAILURE, ScopeInterface::SCOPE_STORE)
        );
    }

    /**
     * Visitor IP to send to siteverify, or '' when send_remote_ip is disabled (default).
     *
     * @return string
     */
    private function getRemoteIp(): string
    {
        $sendRemoteIp = $this->scopeConfig->isSetFlag(self::XML_PATH_SEND_REMOTE_IP, ScopeInterface::SCOPE_WEBSITE);

        return $sendRemoteIp ? (string)$this->remoteAddress->getRemoteAddress() : '';
    }

    /**
     * Whether VerifyToken should reject a token whose action does not match the expected one.
     *
     * @return bool
     */
    private function getValidateAction(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_VALIDATE_ACTION, ScopeInterface::SCOPE_WEBSITE);
    }

    /**
     * Allowed hostnames: the configured list when non-empty, else the current store's base-URL host.
     *
     * Never reads the request Host header: a spoofed Host would defeat the check entirely.
     *
     * @return string[]
     */
    private function getAllowedHostnames(): array
    {
        $configured = trim(
            (string)$this->scopeConfig->getValue(self::XML_PATH_ALLOWED_HOSTNAMES, ScopeInterface::SCOPE_WEBSITE)
        );

        // Branch on the PARSED list, not on the raw string. A value of "," or " , " is non-empty but
        // parses to nothing, so testing the raw string took this branch, returned [], and
        // VerifyToken skips the hostname check on an empty allowlist. A typo in an admin field would
        // have silently switched off the defence against a token minted on another site sharing the
        // sitekey. Falling through to the base URL keeps a wrong value strict rather than open.
        $parsed = $this->parseHostnameList($configured);

        if ($parsed !== []) {
            return $parsed;
        }

        // StoreInterface itself doesn't declare getBaseUrl(); it's implemented on the concrete
        // Store model, which is what StoreManagerInterface::getStore() returns in practice.
        /** @var \Magento\Store\Model\Store $store */
        $store = $this->storeManager->getStore();

        // Host extraction from a configured base URL, same use Magento core makes unguarded
        // (module-store/Model/Store.php:1201,1387).
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- the host of a configured base URL, as core Store does
        $host = parse_url($store->getBaseUrl(UrlInterface::URL_TYPE_WEB), PHP_URL_HOST);

        return $host ? [strtolower($host)] : [];
    }

    /**
     * Split, trim and lowercase a comma-separated hostname list, dropping empty entries.
     *
     * @param string $configured
     * @return string[]
     */
    private function parseHostnameList(string $configured): array
    {
        $hosts = array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', $configured)
        );

        return array_values(array_filter($hosts, static fn (string $host): bool => $host !== ''));
    }

    /**
     * @inheritdoc
     */
    public function get(): ValidationConfigInterface
    {
        /** @var ValidationConfigInterface $validationConfig */
        $validationConfig = $this->validationConfigFactory->create(
            [
                'privateKey' => $this->getPrivateKey(),
                'remoteIp' => $this->getRemoteIp(),
                'validationFailureMessage' => $this->getValidationFailureMessage(),
                'validateAction' => $this->getValidateAction(),
                'allowedHostnames' => $this->getAllowedHostnames(),
            ]
        );

        return $validationConfig;
    }
}
