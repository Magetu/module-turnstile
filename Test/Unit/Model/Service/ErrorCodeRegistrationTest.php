<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\Model\Service;

use Magetu\Turnstile\Model\Service\VerifyToken;
use PHPUnit\Framework\TestCase;

/**
 * Pins what this module registers in the two SHARED error-message providers.
 *
 * Both providers are consumed by every captcha type installed on the store, so a careless entry here
 * changes behaviour for Google reCAPTCHA on somebody else's shop. The registration is derived from
 * VerifyToken::USER_CAUSED_CODE_MAP rather than restated, so the two cannot drift.
 */
class ErrorCodeRegistrationTest extends TestCase
{
    private const ERROR_MESSAGES_PROVIDER = 'Magento\ReCaptchaValidationApi\Model\ErrorMessagesProvider';
    private const VALIDATION_ERROR_MESSAGES_PROVIDER =
        'Magento\ReCaptchaValidationApi\Model\ValidationErrorMessagesProvider';

    /**
     * Codes Google reCAPTCHA also emits. Registering any of these unprefixed would reclassify or
     * relabel Google failures on every store that installs this module.
     *
     * @var string[]
     */
    private const GOOGLE_SHARED_CODES = [
        'missing-input-secret',
        'invalid-input-secret',
        'missing-input-response',
        'invalid-input-response',
        'bad-request',
        'timeout-or-duplicate',
    ];

    /**
     * @return array<string, string>
     */
    private function registeredItems(string $typeName): array
    {
        $dom = new \DOMDocument();
        $dom->load(__DIR__ . '/../../../../etc/di.xml');
        $xpath = new \DOMXPath($dom);

        $items = [];
        $nodes = $xpath->query(sprintf('//type[@name="%s"]/arguments/argument[@name="errors"]/item', $typeName));
        self::assertNotFalse($nodes);
        foreach ($nodes as $node) {
            /** @var \DOMElement $node */
            $items[$node->getAttribute('name')] = $node->textContent;
        }

        return $items;
    }

    public function testRegistersAHumanLabelForEveryTurnstilePrefixedCodeItCanEmit(): void
    {
        $registered = $this->registeredItems(self::ERROR_MESSAGES_PROVIDER);

        foreach (VerifyToken::USER_CAUSED_CODE_MAP as $ourCode) {
            self::assertArrayHasKey(
                $ourCode,
                $registered,
                sprintf(
                    'ErrorMessagesProvider returns the raw code when a code is unregistered, so a '
                    . 'missing entry shows the operator "%s" instead of a sentence.',
                    $ourCode
                )
            );
            self::assertNotSame('', trim($registered[$ourCode]));
        }
    }

    public function testRegistersEveryEmittableUserCausedCodeAsAValidationError(): void
    {
        $registered = $this->registeredItems(self::VALIDATION_ERROR_MESSAGES_PROVIDER);

        self::assertSame(
            array_values(VerifyToken::USER_CAUSED_CODE_MAP),
            array_keys($registered),
            'The validation-error registration is derived from USER_CAUSED_CODE_MAP; if they differ, '
            . 'either a user-caused failure is showing the technical message or an extra code leaked in.'
        );
    }

    public function testRegistersOnlyTurnstilePrefixedCodesInBothSharedProviders(): void
    {
        foreach ([self::ERROR_MESSAGES_PROVIDER, self::VALIDATION_ERROR_MESSAGES_PROVIDER] as $provider) {
            foreach (array_keys($this->registeredItems($provider)) as $code) {
                self::assertStringStartsWith('turnstile-', $code, $provider . ' must only carry our own codes');
            }
        }
    }

    public function testRegistersNoUnprefixedCodeThatGoogleReCaptchaAlsoEmits(): void
    {
        foreach ([self::ERROR_MESSAGES_PROVIDER, self::VALIDATION_ERROR_MESSAGES_PROVIDER] as $provider) {
            $registered = array_keys($this->registeredItems($provider));
            foreach (self::GOOGLE_SHARED_CODES as $googleCode) {
                self::assertNotContains(
                    $googleCode,
                    $registered,
                    sprintf('"%s" is Google vocabulary too; registering it changes their behaviour.', $googleCode)
                );
            }
        }
    }

    public function testEveryRegisteredMessageHasATranslationRow(): void
    {
        $csv = (string)file_get_contents(__DIR__ . '/../../../../i18n/en_US.csv');
        $sources = [];
        foreach (explode("\n", trim($csv)) as $line) {
            $row = str_getcsv($line);
            if (isset($row[0]) && $row[0] !== '') {
                $sources[] = $row[0];
            }
        }

        foreach ($this->registeredItems(self::ERROR_MESSAGES_PROVIDER) as $code => $message) {
            self::assertContains($message, $sources, sprintf('"%s" (%s) needs an en_US.csv row', $message, $code));
        }
    }
}
