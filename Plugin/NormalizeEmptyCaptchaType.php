<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Plugin;

use Magento\ReCaptchaUi\Model\CaptchaTypeResolverInterface;

/**
 * Treat an empty or '0' configured type as disabled in both area resolvers, as the composite resolver does.
 */
class NormalizeEmptyCaptchaType
{
    /**
     * Normalize falsy values ('' and '0') to null; every other resolver result passes through unchanged.
     *
     * @param CaptchaTypeResolverInterface $subject
     * @param string|null $result
     * @return string|null
     */
    public function afterGetCaptchaTypeFor(CaptchaTypeResolverInterface $subject, ?string $result): ?string
    {
        return $result ?: null;
    }
}
