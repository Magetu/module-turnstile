<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Plugin;

use Magento\ReCaptchaUi\Model\ValidationConfigResolverInterface;
use Magento\ReCaptchaValidationApi\Api\Data\ValidationConfigInterface;
use Magetu\Turnstile\Model\ValidationConfig;

/**
 * Copies the captcha id onto our validation config, so VerifyToken can require the token's Turnstile
 * `action` to match the form it was minted for. Without it, a token from one guarded form replays on
 * every other guarded form.
 *
 * One method covers both enforcement paths: the storefront form handler and the REST plugins all
 * resolve their config through `ValidationConfigResolverInterface::get()`.
 *
 * It mutates the object the resolver just returned, safe only because our providers build a FRESH
 * config on every `get()` call, because a memoised one would write one surface's action onto an instance a
 * different surface is about to validate against. Foreign configs are returned untouched: no other
 * captcha type knows what an expected action is.
 */
class InjectExpectedAction
{
    /**
     * Stamp the resolved captcha id onto our own config, and leave every other type alone.
     *
     * @param ValidationConfigResolverInterface $subject
     * @param ValidationConfigInterface $result
     * @param string $key The captcha id, e.g. `customer_login`. Doubles as the widget's `action`.
     * @return ValidationConfigInterface
     */
    public function afterGet(
        ValidationConfigResolverInterface $subject,
        ValidationConfigInterface $result,
        string $key
    ): ValidationConfigInterface {
        if (!$result instanceof ValidationConfig) {
            return $result;
        }

        return $result->setExpectedAction($key);
    }
}
