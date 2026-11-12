<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Plugin;

use Magento\Framework\Validation\ValidationResult;
use Magento\ReCaptchaValidationApi\Api\Data\ValidationConfigInterface;
use Magento\ReCaptchaValidationApi\Api\ValidatorInterface;
use Magetu\Turnstile\Model\Service\VerifyToken;
use Magetu\Turnstile\Model\ValidationConfig;

/**
 * Routes Turnstile validations to Cloudflare; every other captcha type passes through unchanged.
 *
 * Dispatch keys on the resolved config object's TYPE, never on request state. The shared validation
 * client cannot see which captcha type is being validated, so inferring it from a request parameter,
 * header, or cookie will eventually verify one vendor's token against another vendor's endpoint,
 * leaking that vendor's secret to the wrong host. For the same reason, this class takes no request
 * dependency, directly or through a wrapping service. A unit test pins that.
 *
 * Declared with `sortOrder` above 0 so it sits INSIDE `Magento\ReCaptchaWebapiRest\Plugin\ValidationOverrider`
 * (undeclared sortOrder is 0, and a lower sortOrder wraps a higher one), keeping integration-token REST
 * calls exempt, as they are today.
 */
class ValidateTurnstile
{
    /**
     * @param VerifyToken $verifyToken
     */
    public function __construct(private readonly VerifyToken $verifyToken)
    {
    }

    /**
     * Take over validation only for our own captcha type.
     *
     * @param ValidatorInterface $subject
     * @param callable $proceed
     * @param string $reCaptchaResponse Token already resolved by native code; see the class docblock.
     * @param ValidationConfigInterface $validationConfig
     * @return ValidationResult
     */
    public function aroundIsValid(
        ValidatorInterface $subject,
        callable $proceed,
        string $reCaptchaResponse,
        ValidationConfigInterface $validationConfig
    ): ValidationResult {
        if (!$validationConfig instanceof ValidationConfig) {
            return $proceed($reCaptchaResponse, $validationConfig);
        }

        return $this->verifyToken->execute($reCaptchaResponse, $validationConfig);
    }
}
