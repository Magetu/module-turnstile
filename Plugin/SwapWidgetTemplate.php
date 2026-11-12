<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Plugin;

use Magento\ReCaptchaUi\Block\ReCaptcha;
use Magento\ReCaptchaUi\Model\CaptchaTypeResolverInterface;

/**
 * Points the shared captcha block at our own template, and hands it the widget parameters.
 *
 * Consumers never call this. It runs on every render of `Magento\ReCaptchaUi\Block\ReCaptcha`, the
 * single block class every Luma-stack storefront surface AND both admin pages insert, so one global
 * declaration covers all of them; every other captcha type keeps its own template, and the swap
 * happens only when the resolved type is ours.
 *
 * Admin coverage matters here: the type appears in the backend `type_for` dropdown whether or not
 * this class supports it (frontend and backend share one option source), and "selectable but
 * non-functional for user_login" is an admin lockout.
 *
 * `getCaptchaTypeFor()` is declared as throwing, and this class deliberately doesn't catch: the next
 * thing the block does is call `isCaptchaEnabledFor()` on the same key, resolving the same type
 * through the same object, so swallowing the throw here would only change which line the stack
 * trace points at.
 */
class SwapWidgetTemplate
{
    /**
     * The value stored in `recaptcha_{frontend,backend}/type_for/<surface>` for this type.
     *
     * @var string
     */
    public const CAPTCHA_TYPE = 'magetu_turnstile';

    /**
     * Resolved from `view/frontend/templates` or `view/adminhtml/templates` by the area in play, so
     * one identifier serves both.
     *
     * @var string
     */
    public const TEMPLATE = 'Magetu_Turnstile::turnstile.phtml';

    /**
     * Block data key the two templates read the widget parameters back out of.
     *
     * @var string
     */
    public const WIDGET_OPTIONS_KEY = 'magetu_turnstile_widget_options';

    /**
     * The widget names its hidden input this instead of its own default.
     *
     * That one parameter is the entire token rename and covers the FORM POST path: the input lands
     * in the enclosing form under the name `CaptchaResponseResolver::resolve()` already reads with
     * `getParam()`, so no server-side rename is needed. It says nothing about REST, where the token
     * never travels as a form field: a REST caller puts it in the `X-ReCaptcha` header instead,
     * same as for a Google type.
     *
     * @var string
     */
    public const RESPONSE_FIELD_NAME = 'g-recaptcha-response';

    /**
     * Top-level UI config key marking a resolved config as this type's.
     *
     * For consumers that cannot resolve the captcha type themselves. The Knockout bridge is the one
     * that matters: it reads its settings out of serialised jsLayout in the browser, where no
     * resolver exists and the type name appears nowhere else.
     *
     * @var string
     */
    public const UI_CONFIG_MARKER = 'magetuTurnstile';

    /**
     * @param CaptchaTypeResolverInterface $captchaTypeResolver Area-correct by DI preference.
     */
    public function __construct(
        private readonly CaptchaTypeResolverInterface $captchaTypeResolver
    ) {
    }

    /**
     * Swap the template and stage the widget parameters, for our type only.
     *
     * @param ReCaptcha $subject
     * @return void
     * @throws \Magento\Framework\Exception\InputException
     */
    public function beforeToHtml(ReCaptcha $subject): void
    {
        $captchaId = (string)$subject->getData('recaptcha_for');
        if ($captchaId === '' || $this->captchaTypeResolver->getCaptchaTypeFor($captchaId) !== self::CAPTCHA_TYPE) {
            return;
        }

        $subject->setTemplate(self::TEMPLATE);
        $subject->setData(
            self::WIDGET_OPTIONS_KEY,
            $this->buildWidgetOptions($subject->getCaptchaUiConfig(), $captchaId)
        );
    }

    /**
     * Read the widget's render parameters out of the resolved UI config.
     *
     * The UI config providers already emit widget parameter names with blanks dropped, so there is
     * nothing to translate here. Keeping a second translation in this file is what would let the two
     * drift, and the drift that hurts most is `response-field-name`: a token under a name the
     * server does not read makes every guarded submit on the surface fail.
     *
     * @param array<mixed> $uiConfig Shape: ['rendering' => <widget parameters>].
     * @param string $captchaId Doubles as the widget action; see below.
     * @return array<string, string>
     */
    private function buildWidgetOptions(array $uiConfig, string $captchaId): array
    {
        $options = [];
        if (isset($uiConfig['rendering']) && \is_array($uiConfig['rendering'])) {
            $options = $uiConfig['rendering'];
        }

        // Passed through verbatim, never sanitised: the verification service compares the action
        // reported back by the provider against this exact captcha id, so any normalisation here
        // would guarantee a mismatch and fail every submit closed.
        $options['action'] = $captchaId;

        return \array_filter($options, static fn ($value): bool => (string)$value !== '');
    }
}
