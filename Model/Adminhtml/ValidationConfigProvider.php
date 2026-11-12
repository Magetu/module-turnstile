<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Model\Adminhtml;

use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\ReCaptchaUi\Model\ValidationConfigProviderInterface;
use Magento\ReCaptchaValidationApi\Api\Data\ValidationConfigInterface;
use Magetu\Turnstile\Model\ValidationConfigFactory;

/**
 * Admin login/forgot-password validation config for the `magetu_turnstile` type.
 *
 * Derived from module-re-captcha-version-3-invisible/Model/Adminhtml/ValidationConfigProvider.php.
 * get() always builds a fresh instance via the generated factory, for the same reason the
 * storefront provider does (see the class doc there).
 *
 * The hostname fallback is the trap this class exists to avoid: it MUST derive from the admin
 * BACKEND base URL (Magento\Backend\Model\UrlInterface::getBaseUrl(), globally preferenced to
 * Magento\Backend\Model\Url regardless of area, module-backend/etc/di.xml:14), never the
 * storefront's. The admin login widget is served from the admin host, Cloudflare reports that host
 * in siteverify's `hostname`, and a storefront-derived allowlist would fail every admin token,
 * total admin lockout (architecture risk R1). Never derive from the request Host header either: a
 * spoofed Host makes the check worthless.
 */
class ValidationConfigProvider implements ValidationConfigProviderInterface
{
    private const XML_PATH_PRIVATE_KEY = 'recaptcha_backend/type_magetu_turnstile/private_key';
    private const XML_PATH_VALIDATION_FAILURE = 'recaptcha_backend/type_magetu_turnstile/validation_failure_message';
    private const XML_PATH_SEND_REMOTE_IP = 'recaptcha_backend/type_magetu_turnstile/send_remote_ip';
    private const XML_PATH_VALIDATE_ACTION = 'recaptcha_backend/type_magetu_turnstile/validate_action';
    private const XML_PATH_ALLOWED_HOSTNAMES = 'recaptcha_backend/type_magetu_turnstile/allowed_hostnames';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param RemoteAddress $remoteAddress Same IP source module-re-captcha-version-3-invisible uses.
     * @param BackendUrlInterface $backendUrl Supplies the fallback allowed-hostname host: the
     *        configured admin base URL, never the request Host header.
     * @param ValidationConfigFactory $validationConfigFactory
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly RemoteAddress $remoteAddress,
        private readonly BackendUrlInterface $backendUrl,
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
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_PRIVATE_KEY));
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
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_VALIDATION_FAILURE));
    }

    /**
     * Visitor IP to send to siteverify, or '' when send_remote_ip is disabled (default).
     *
     * @return string
     */
    private function getRemoteIp(): string
    {
        $sendRemoteIp = $this->scopeConfig->isSetFlag(self::XML_PATH_SEND_REMOTE_IP);

        return $sendRemoteIp ? (string)$this->remoteAddress->getRemoteAddress() : '';
    }

    /**
     * Whether VerifyToken should reject a token whose action does not match the expected one.
     *
     * @return bool
     */
    private function getValidateAction(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_VALIDATE_ACTION);
    }

    /**
     * Allowed hostnames: the configured list when non-empty, else the ADMIN base URL host.
     *
     * Never reads the request Host header: a spoofed Host would defeat the check entirely.
     *
     * @return string[]
     */
    private function getAllowedHostnames(): array
    {
        $configured = trim((string)$this->scopeConfig->getValue(self::XML_PATH_ALLOWED_HOSTNAMES));

        // Branch on the PARSED list, not on the raw string. A value of "," or " , " is non-empty but
        // parses to nothing, so testing the raw string took this branch, returned [], and
        // VerifyToken skips the hostname check on an empty allowlist. A typo in an admin field would
        // have silently switched off the defence against a token minted on another site sharing the
        // sitekey. Falling through to the base URL keeps a wrong value strict rather than open.
        $parsed = $this->parseHostnameList($configured);

        if ($parsed !== []) {
            return $parsed;
        }

        // Host extraction from the configured admin base URL, same use Magento core makes
        // unguarded (module-store/Model/Store.php:1201,1387).
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- the host of a configured base URL, as core Store does
        $host = parse_url($this->backendUrl->getBaseUrl(), PHP_URL_HOST);

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
