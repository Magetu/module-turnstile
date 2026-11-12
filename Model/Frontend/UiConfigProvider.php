<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Model\Frontend;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\ReCaptchaUi\Model\UiConfigProviderInterface;
use Magento\Store\Model\ScopeInterface;
use Magetu\Turnstile\Plugin\SwapWidgetTemplate;

/**
 * Storefront rendering config for the `magetu_turnstile` type.
 *
 * Derived from module-re-captcha-version-3-invisible/Model/Frontend/UiConfigProvider.php.
 * Magento\ReCaptchaUi\Model\IsCaptchaEnabled requires the exact key path 'rendering'.'sitekey',
 * get that key name wrong and the surface runs unguarded with a green dashboard.
 */
class UiConfigProvider implements UiConfigProviderInterface
{
    private const XML_PATH_PUBLIC_KEY = 'recaptcha_frontend/type_magetu_turnstile/public_key';
    private const XML_PATH_SIZE = 'recaptcha_frontend/type_magetu_turnstile/size';
    private const XML_PATH_THEME = 'recaptcha_frontend/type_magetu_turnstile/theme';
    private const XML_PATH_APPEARANCE = 'recaptcha_frontend/type_magetu_turnstile/appearance';
    private const XML_PATH_LANGUAGE_CODE = 'recaptcha_frontend/type_magetu_turnstile/lang';

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
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_PUBLIC_KEY, ScopeInterface::SCOPE_WEBSITE));
    }

    /**
     * Widget size.
     *
     * @return string
     */
    private function getSize(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_SIZE, ScopeInterface::SCOPE_WEBSITE);
    }

    /**
     * Widget theme.
     *
     * Read at store scope: unlike size/appearance, etc/adminhtml/system.xml exposes this field at
     * store level, so reading it any coarser would silently ignore a store override.
     *
     * @return string
     */
    private function getTheme(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_THEME, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Widget appearance mode.
     *
     * @return string
     */
    private function getAppearance(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_APPEARANCE, ScopeInterface::SCOPE_WEBSITE);
    }

    /**
     * Forced language code, '' meaning auto-detect.
     *
     * @return string
     */
    private function getLanguageCode(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_LANGUAGE_CODE, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @inheritdoc
     *
     * Keys are the widget's own render parameter names, not the Google-shaped ones a native provider
     * returns. Three consumers read this array (the block-path plugin, the theme ViewModel and the
     * Knockout bridge), and a parameter renamed on the way to any one of them is a rename nobody
     * checks. `response-field-name` is the sharp case: get it wrong and the token lands under a name
     * the server never reads, so every guarded submit on the surface fails.
     *
     * Blank options are dropped so the widget falls back to its own default instead of rejecting an
     * empty string. `sitekey` stays whatever it is, blank included, because that is what
     * `ReCaptchaUi\Model\IsCaptchaEnabled::areKeysConfigured()` reads to decide the type is
     * configured at all.
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
