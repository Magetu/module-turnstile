<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

/**
 * Asserts etc/config.xml ships the Turnstile defaults, and pins THE TRAP task 004 exists to guard
 * against: without backend_model="Magento\Config\Model\Config\Backend\Encrypted" on the public_key
 * and private_key default nodes, ScopeConfigInterface returns ciphertext instead of plaintext (the
 * attribute is read-time metadata for MetadataConfigTypeProcessor, not a storage instruction), and
 * every siteverify call fail-closed-blocks with invalid-input-secret and no obvious cause. Mirrors
 * module-re-captcha-version-3-invisible/etc/config.xml:13-14,24-25.
 *
 * testDeclaresEncryptedBackendModelOnAllFourKeyNodes is the test that pins this. Proven red by
 * deleting a single backend_model ATTRIBUTE (not the whole node). See task 004 verification notes.
 */
class ConfigXmlTest extends TestCase
{
    private const ENCRYPTED_BACKEND_MODEL = 'Magento\Config\Model\Config\Backend\Encrypted';

    private function loadConfigXml(): \DOMDocument
    {
        $path = \dirname(__DIR__, 3) . '/etc/config.xml';
        $this->assertFileExists($path, 'etc/config.xml must exist.');

        $dom = new \DOMDocument();
        $this->assertTrue($dom->load($path), 'etc/config.xml must be valid XML.');

        return $dom;
    }

    private function xpath(\DOMDocument $dom): \DOMXPath
    {
        return new \DOMXPath($dom);
    }

    public function testValidatesAgainstConfigXsd(): void
    {
        $dom = $this->loadConfigXml();
        $xsd = \dirname(__DIR__, 7) . '/vendor/magento/module-store/etc/config.xsd';
        $this->assertFileExists($xsd, 'config.xsd must be resolvable from vendor/.');

        $this->assertTrue($dom->schemaValidate($xsd), 'etc/config.xml must validate against config.xsd.');
    }

    /**
     * THE TRAP, pinned. Asserts the exact backend_model class string, not merely that the attribute
     * is present, on all four key nodes: public_key and private_key, in both recaptcha_backend and
     * recaptcha_frontend.
     */
    public function testDeclaresEncryptedBackendModelOnAllFourKeyNodes(): void
    {
        $dom = $this->loadConfigXml();
        $xpath = $this->xpath($dom);

        foreach (['recaptcha_backend', 'recaptcha_frontend'] as $sectionId) {
            foreach (['public_key', 'private_key'] as $fieldId) {
                $nodes = $xpath->query("/config/default/$sectionId/type_magetu_turnstile/$fieldId");
                $this->assertSame(
                    1,
                    $nodes->length,
                    "$sectionId/type_magetu_turnstile/$fieldId node must exist exactly once."
                );

                /** @var \DOMElement $node */
                $node = $nodes->item(0);
                $this->assertTrue(
                    $node->hasAttribute('backend_model'),
                    "$sectionId/type_magetu_turnstile/$fieldId must declare a backend_model attribute."
                );
                $this->assertSame(
                    self::ENCRYPTED_BACKEND_MODEL,
                    $node->getAttribute('backend_model'),
                    "$sectionId/type_magetu_turnstile/$fieldId backend_model must be the exact Encrypted class."
                );
            }
        }
    }

    public function testDefaultsSizeFlexibleThemeAutoAppearanceAlways(): void
    {
        $dom = $this->loadConfigXml();
        $xpath = $this->xpath($dom);

        $expected = ['size' => 'flexible', 'theme' => 'auto', 'appearance' => 'always'];

        foreach (['recaptcha_backend', 'recaptcha_frontend'] as $sectionId) {
            foreach ($expected as $fieldId => $default) {
                $nodes = $xpath->query("/config/default/$sectionId/type_magetu_turnstile/$fieldId");
                $this->assertSame(1, $nodes->length, "$sectionId/type_magetu_turnstile/$fieldId must exist.");
                $this->assertSame(
                    $default,
                    $nodes->item(0)->textContent,
                    "$sectionId/type_magetu_turnstile/$fieldId default mismatch."
                );
            }
        }
    }

    public function testDefaultsSendRemoteIpNoAndValidateActionYes(): void
    {
        $dom = $this->loadConfigXml();
        $xpath = $this->xpath($dom);

        foreach (['recaptcha_backend', 'recaptcha_frontend'] as $sectionId) {
            $sendRemoteIp = $xpath->query("/config/default/$sectionId/type_magetu_turnstile/send_remote_ip");
            $this->assertSame(1, $sendRemoteIp->length);
            $this->assertSame(
                '0',
                $sendRemoteIp->item(0)->textContent,
                "$sectionId send_remote_ip must default to No (0)."
            );

            $validateAction = $xpath->query("/config/default/$sectionId/type_magetu_turnstile/validate_action");
            $this->assertSame(1, $validateAction->length);
            $this->assertSame(
                '1',
                $validateAction->item(0)->textContent,
                "$sectionId validate_action must default to Yes (1)."
            );
        }
    }

    public function testShipsANonEmptyDefaultValidationFailureMessageInBothSections(): void
    {
        $dom = $this->loadConfigXml();
        $xpath = $this->xpath($dom);

        foreach (['recaptcha_backend', 'recaptcha_frontend'] as $sectionId) {
            $nodes = $xpath->query("/config/default/$sectionId/type_magetu_turnstile/validation_failure_message");
            $this->assertSame(1, $nodes->length, "$sectionId must declare a validation_failure_message default.");
            $this->assertNotSame(
                '',
                \trim($nodes->item(0)->textContent),
                'validation_failure_message must be non-empty: getValidationFailureMessage() is a non-nullable string.'
            );
        }
    }

    public function testOverridesTheFrontendFailureMessagesSoVisitorsDoNotReadRecaptcha(): void
    {
        $xpath = $this->xpath($this->loadConfigXml());

        foreach (['validation_failure_message', 'technical_failure_message'] as $field) {
            $nodes = $xpath->query("/config/default/recaptcha_frontend/failure_messages/$field");
            $this->assertSame(1, $nodes->length, "recaptcha_frontend/failure_messages/$field must be declared.");
            $text = \trim($nodes->item(0)->textContent);
            $this->assertNotSame('', $text, "$field must be non-empty.");
            $this->assertStringNotContainsStringIgnoringCase(
                'recaptcha',
                $text,
                "$field is shown under a Turnstile widget."
            );
        }
    }

    public function testLoadsAfterReCaptchaFrontendUiSoItsFailureMessagesWinTheConfigMerge(): void
    {
        $dom = new \DOMDocument();
        $this->assertTrue($dom->load(\dirname(__DIR__, 3) . '/etc/module.xml'));
        $sequence = (new \DOMXPath($dom))->query(
            "/config/module[@name='Magetu_Turnstile']/sequence/module[@name='Magento_ReCaptchaFrontendUi']"
        );

        $this->assertSame(1, $sequence->length);
    }

    public function testDeclaresEmptyAllowedHostnamesAndLangByDefault(): void
    {
        $dom = $this->loadConfigXml();
        $xpath = $this->xpath($dom);

        foreach (['recaptcha_backend', 'recaptcha_frontend'] as $sectionId) {
            foreach (['lang', 'allowed_hostnames'] as $fieldId) {
                $nodes = $xpath->query("/config/default/$sectionId/type_magetu_turnstile/$fieldId");
                $this->assertSame(1, $nodes->length, "$sectionId/type_magetu_turnstile/$fieldId must exist.");
                $this->assertSame(
                    '',
                    \trim($nodes->item(0)->textContent),
                    "$sectionId/type_magetu_turnstile/$fieldId must default empty."
                );
            }
        }
    }
}
