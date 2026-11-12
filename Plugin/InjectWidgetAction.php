<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Plugin;

use Magento\ReCaptchaUi\Model\UiConfigResolverInterface;

/**
 * Copies the captcha id into the rendering parameters as the widget's `action`.
 *
 * The twin of {@see InjectExpectedAction}, which puts the same id on the validation side. Both halves
 * are needed for the action check to mean anything: one names the action the widget mints its token
 * for, the other names the action the verification service must report back. They resolve the id the
 * same way, from the key the surface asked for, so the two cannot drift apart.
 *
 * It lives in a plugin rather than in the UI config providers because {@see
 * UiConfigProviderInterface::get()} takes no arguments: a provider cannot see which surface is being
 * rendered, and the resolver is the first place the key and the config exist together.
 *
 * Foreign configs are returned untouched. The marker the providers set is the test, not a captcha
 * type lookup: the resolver is reachable from areas where {@see
 * \Magento\ReCaptchaUi\Model\CaptchaTypeResolverInterface} answers with an area-prefixed name, and a
 * strict comparison against the bare type would silently stop stamping there.
 */
class InjectWidgetAction
{
    /**
     * Stamp the captcha id as the widget action, for our own config only.
     *
     * @param UiConfigResolverInterface $subject
     * @param array<mixed> $result
     * @param string $key The captcha id, e.g. `customer_login`.
     * @return array<mixed>
     */
    public function afterGet(
        UiConfigResolverInterface $subject,
        array $result,
        string $key
    ): array {
        if (($result[SwapWidgetTemplate::UI_CONFIG_MARKER] ?? false) !== true) {
            return $result;
        }

        if (!isset($result['rendering']) || !\is_array($result['rendering'])) {
            $result['rendering'] = [];
        }

        $result['rendering']['action'] = $key;

        return $result;
    }
}
