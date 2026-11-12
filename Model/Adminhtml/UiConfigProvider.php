<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Model\Adminhtml;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\ReCaptchaUi\Model\UiConfigProviderInterface;
use Magetu\Turnstile\Plugin\SwapWidgetTemplate;

/**
 * Admin login/forgot-password rendering config for the `magetu_turnstile` type.
 *
 * Derived from module-re-captcha-version-3-invisible/Model/Adminhtml/UiConfigProvider.php. Reads
 * with no scope argument (default scope): etc/adminhtml/system.xml declares every
 * recaptcha_backend/type_magetu_turnstile field showInWebsite="0" showInStore="0", so there's
 * nothing finer to resolve.
 */
class UiConfigProvider implements UiConfigProviderInterface
{
    private const XML_PATH_PUBLIC_KEY = 'recaptcha_backend/type_magetu_turnstile/public_key';
    private const XML_PATH_SIZE = 'recaptcha_backend/type_magetu_turnstile/size';
    private const XML_PATH_THEME = 'recaptcha_backend/type_magetu_turnstile/theme';
    private const XML_PATH_APPEARANCE = 'recaptcha_backend/type_magetu_turnstile/appearance';
    private const XML_PATH_LANGUAGE_CODE = 'recaptcha_backend/type_magetu_turnstile/lang';

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Turnstile site key.
     *
     * @return string
     */
    private function getPublicKey(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_PUBLIC_KEY));
    }

    /**
     * Widget size.
     *
     * @return string
     */
    private function getSize(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_SIZE);
    }

    /**
     * Widget theme.
     *
     * @return string
     */
    private function getTheme(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_THEME);
    }

    /**
     * Widget appearance mode.
     *
     * @return string
     */
    private function getAppearance(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_APPEARANCE);
    }

    /**
     * Forced language code, '' meaning auto-detect.
     *
     * @return string
     */
    private function getLanguageCode(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_LANGUAGE_CODE);
    }

    /**
     * @inheritdoc
     *
     * Same shape and the same reasoning as the storefront provider: widget parameter names, blanks
     * dropped, `sitekey` always present.
     *
     * @return array<string, mixed> ['rendering' => <widget parameters>, <marker> => true]
     */
    public function get(): array
    {
        $rendering = [
            'sitekey' => $this->getPublicKey(),
            'response-field-name' => SwapWidgetTemplate::RESPONSE_FIELD_NAME,
        ];

        return [
            'rendering' => $rendering + $this->optionalParameters(),
            SwapWidgetTemplate::UI_CONFIG_MARKER => true,
        ];
    }

    /**
     * The render parameters that have a widget-side default, with the unset ones dropped.
     *
     * Sending a blank string where the widget expects one of a fixed set of values is a render
     * error, not a fallback.
     *
     * @return array<string, string>
     */
    private function optionalParameters(): array
    {
        return \array_filter(
            [
                'size' => $this->getSize(),
                'theme' => $this->getTheme(),
                'appearance' => $this->getAppearance(),
                'language' => $this->getLanguageCode(),
            ],
            static fn (string $value): bool => $value !== ''
        );
    }
}
