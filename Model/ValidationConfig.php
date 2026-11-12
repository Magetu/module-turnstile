<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Model;

use Magento\ReCaptchaValidationApi\Api\Data\ValidationConfigExtensionInterface;
use Magento\ReCaptchaValidationApi\Api\Data\ValidationConfigInterface;

/**
 * Turnstile's own {@see ValidationConfigInterface} implementation.
 *
 * Its TYPE is the dispatch marker Plugin\ValidateTurnstile keys on: the around-plugin on
 * ValidatorInterface::isValid takes over only when the resolved config `instanceof self`, calling
 * $proceed() untouched for every other captcha type.
 *
 * Dispatching on the config object's type, not on global request state, is deliberate and
 * load-bearing: the shared validation client cannot see which captcha type is being validated, so
 * guessing from the request (a parameter, a header) will eventually verify one vendor's token
 * against another vendor's endpoint, sending that secret to the wrong host. Do not turn this class
 * into a generic data bag shared with anything else.
 *
 * A provider must build a FRESH instance per {@see \Magento\ReCaptchaUi\Model\ValidationConfigResolver::get()}
 * call rather than memoising one: Plugin\InjectExpectedAction mutates the returned
 * instance, and a shared one would let one surface's expected action leak into a validation for a
 * different surface rendered earlier on the same page.
 */
class ValidationConfig implements ValidationConfigInterface
{
    /**
     * The one mutable field. Written after construction by Plugin\InjectExpectedAction,
     * which copies the resolved captcha id onto whichever config instance
     * ValidationConfigResolver::get() just returned. Every other property here stays readonly.
     *
     * @var string
     */
    private string $expectedAction;

    /**
     * @param string $privateKey Turnstile secret key (native ValidationConfigInterface member).
     * @param string $remoteIp Visitor IP for siteverify, or '' when send_remote_ip is disabled
     *        (native ValidationConfigInterface member).
     * @param string $validationFailureMessage Kept only because the interface method is
     *        non-nullable; does NOT control the message the visitor sees (ErrorMessageConfig reads
     *        the section-level failure_messages fields instead).
     * @param bool $validateAction Whether VerifyToken should reject a token whose action
     *        doesn't match $expectedAction.
     * @param string[] $allowedHostnames Non-empty list of lowercase hostnames a siteverify response's
     *        `hostname` must appear in, derived from the configured allowed_hostnames field or a
     *        configured base URL, never the request Host header.
     * @param string $expectedAction The captcha id this token should have been minted for; empty
     *        until Plugin\InjectExpectedAction sets it.
     */
    public function __construct(
        private readonly string $privateKey,
        private readonly string $remoteIp,
        private readonly string $validationFailureMessage,
        private readonly bool $validateAction,
        private readonly array $allowedHostnames,
        string $expectedAction = ''
    ) {
        $this->expectedAction = $expectedAction;
    }

    /**
     * @inheritdoc
     */
    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }

    /**
     * @inheritdoc
     */
    public function getRemoteIp(): string
    {
        return $this->remoteIp;
    }

    /**
     * @inheritdoc
     */
    public function getValidationFailureMessage(): string
    {
        return $this->validationFailureMessage;
    }

    /**
     * @inheritdoc
     */
    public function getExtensionAttributes(): ?ValidationConfigExtensionInterface
    {
        return null;
    }

    /**
     * The captcha id this token's `action` is expected to carry.
     *
     * @return string
     */
    public function getExpectedAction(): string
    {
        return $this->expectedAction;
    }

    /**
     * Set the expected action after construction.
     *
     * Called by Plugin\InjectExpectedAction once the captcha id resolving this config is known.
     *
     * @param string $expectedAction
     * @return ValidationConfigInterface
     */
    public function setExpectedAction(string $expectedAction): ValidationConfigInterface
    {
        $this->expectedAction = $expectedAction;

        return $this;
    }

    /**
     * Whether VerifyToken should reject a token whose `action` does not match getExpectedAction().
     *
     * @return bool
     */
    public function getValidateAction(): bool
    {
        return $this->validateAction;
    }

    /**
     * Lowercase hostnames a siteverify response's `hostname` must appear in to be accepted.
     *
     * @return string[]
     */
    public function getAllowedHostnames(): array
    {
        return $this->allowedHostnames;
    }
}
