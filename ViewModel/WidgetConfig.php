<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\ViewModel;

use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\ReCaptchaUi\Model\UiConfigResolverInterface;
use Magetu\Turnstile\Plugin\SwapWidgetTemplate;

/**
 * Render config for the theme-side templates, taken from the block the theme hands them.
 *
 * Consumers do not call this directly. It is attached as a layout argument to the two
 * convention-named blocks a Hyvä theme dispatches to, and read back inside their templates as
 * `$block->getData('widget_config')`.
 *
 * Two design points a future edit should not undo:
 *
 * 1. **Config arrives through UiConfigResolverInterface, never a second config read.** Re-reading
 *    `recaptcha_frontend/type_magetu_turnstile/*` here would rebuild the same path, scope and
 *    default mapping in a second file, and nothing would fail when the two drifted: the widget
 *    would just render with different parameters in one theme than the other.
 * 2. **The captcha id comes from the block, not anywhere else.** The theme sets `form_id` (and,
 *    where one page renders the same form twice, `input_element_id`) before rendering; that id
 *    doubles as the widget action, compared byte for byte by the verification service, so it's
 *    passed through untouched.
 */
class WidgetConfig implements ArgumentInterface
{
    /**
     * Namespaced so a container id can never collide with an element another module rendered.
     *
     * @var string
     */
    private const CONTAINER_ID_PREFIX = 'magetu-turnstile-';

    /**
     * Everything outside this set is dropped from a container id, which is written into markup as an
     * id and into script as a CSS selector.
     *
     * @var string
     */
    private const CONTAINER_ID_UNSAFE = '/[^A-Za-z0-9_-]/';

    /**
     * @param UiConfigResolverInterface $uiConfigResolver Area-correct by DI preference.
     */
    public function __construct(
        private readonly UiConfigResolverInterface $uiConfigResolver
    ) {
    }

    /**
     * Widget render parameters for the surface this block was rendered for.
     *
     * Blank values are dropped rather than passed through, so an unset option falls back to the
     * widget's own default instead of being rendered as an empty string it would reject.
     *
     * @param DataObject $block The block the theme set `form_id` on before rendering it.
     * @return array<string, string>
     * @throws \Magento\Framework\Exception\InputException If no UI config is registered for the type.
     */
    public function getWidgetOptions(DataObject $block): array
    {
        $captchaId = $this->getCaptchaId($block);
        $uiConfig = $this->uiConfigResolver->get($captchaId);

        $options = [];
        if (isset($uiConfig['rendering']) && \is_array($uiConfig['rendering'])) {
            $options = $uiConfig['rendering'];
        }

        $options['action'] = $captchaId;

        return \array_filter($options, static fn ($value): bool => (string)$value !== '');
    }

    /**
     * Name of the hidden input the widget creates inside the enclosing form.
     *
     * The submit-time guard reads the same name, so both ends of that handshake come from here
     * rather than two independently chosen literals. It's also the name the theme assumes by
     * default, so no extra theme configuration is needed to line the two up.
     *
     * @return string
     */
    public function getTokenFieldName(): string
    {
        return SwapWidgetTemplate::RESPONSE_FIELD_NAME;
    }

    /**
     * Id of the element the widget is rendered into.
     *
     * One captcha id can appear twice on a page (a login form plus a login popup, say), so the
     * theme's own per-instance id wins when it set one.
     *
     * @param DataObject $block
     * @return string
     */
    public function getContainerId(DataObject $block): string
    {
        $instance = (string)$block->getData('input_element_id');
        if ($instance === '') {
            $instance = $this->getCaptchaId($block);
        }
        $instance = (string)\preg_replace(self::CONTAINER_ID_UNSAFE, '', $instance);

        return self::CONTAINER_ID_PREFIX . ($instance === '' ? 'widget' : $instance);
    }

    /**
     * Captcha id of the surface being rendered, which doubles as the widget action.
     *
     * @param DataObject $block
     * @return string
     */
    private function getCaptchaId(DataObject $block): string
    {
        return (string)$block->getData('form_id');
    }
}
