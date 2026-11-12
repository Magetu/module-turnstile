<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

/**
 * Asserts etc/adminhtml/system.xml adds the Turnstile fields inside the EXISTING recaptcha_frontend
 * and recaptcha_backend sections only (task 004: "no new section, no acl.xml"), because those sections
 * already carry Magento_ReCaptchaUi::config under the Security tab in
 * module-re-captcha-admin-ui/etc/adminhtml/system.xml), and that the key fields are wired to the
 * Encrypted backend model and the select fields point at task 002's virtualTypes.
 */
class SystemXmlTest extends TestCase
{
    private const ENCRYPTED_BACKEND_MODEL = 'Magento\Config\Model\Config\Backend\Encrypted';

    private function loadSystemXml(): \DOMDocument
    {
        $path = \dirname(__DIR__, 3) . '/etc/adminhtml/system.xml';
        $this->assertFileExists($path, 'etc/adminhtml/system.xml must exist.');

        $dom = new \DOMDocument();
        $this->assertTrue($dom->load($path), 'etc/adminhtml/system.xml must be valid XML.');

        return $dom;
    }

    private function xpath(\DOMDocument $dom): \DOMXPath
    {
        return new \DOMXPath($dom);
    }

    public function testValidatesAgainstSystemFileXsd(): void
    {
        $dom = $this->loadSystemXml();
        $xsd = \dirname(__DIR__, 7) . '/vendor/magento/module-config/etc/system_file.xsd';
        $this->assertFileExists($xsd, 'system_file.xsd must be resolvable from vendor/.');

        $this->assertTrue(
            $dom->schemaValidate($xsd),
            'etc/adminhtml/system.xml must validate against system_file.xsd.'
        );
    }

    public function testDeclaresTheTurnstileGroupInsideBothExistingSections(): void
    {
        $dom = $this->loadSystemXml();
        $xpath = $this->xpath($dom);

        $sectionIds = [];
        foreach ($xpath->query('/config/system/section') as $section) {
            $sectionIds[] = $section->getAttribute('id');
        }
        \sort($sectionIds);

        $this->assertSame(
            ['recaptcha_backend', 'recaptcha_frontend'],
            $sectionIds,
            'Only the existing recaptcha_backend and recaptcha_frontend sections may appear. No new section.'
        );

        foreach (['recaptcha_backend', 'recaptcha_frontend'] as $sectionId) {
            $groups = $xpath->query("/config/system/section[@id='$sectionId']/group[@id='type_magetu_turnstile']");
            $this->assertSame(
                1,
                $groups->length,
                "Section $sectionId must carry exactly one type_magetu_turnstile group."
            );
        }
    }

    public function testAddsNoNewAclResourceOrTab(): void
    {
        $dom = $this->loadSystemXml();
        $xpath = $this->xpath($dom);

        $this->assertSame(0, $xpath->query('//tab')->length, 'No new admin-config tab may be declared.');
        $this->assertSame(0, $xpath->query('//resource')->length, 'No new ACL resource may be declared.');

        foreach ($xpath->query('/config/system/section') as $section) {
            $this->assertSame(
                ['id'],
                \iterator_to_array((function () use ($section) {
                    foreach ($section->attributes as $attribute) {
                        yield $attribute->nodeName;
                    }
                })()),
                'The <section> merge tag must carry only "id", with no redeclared label/tab/resource metadata.'
            );
        }
    }

    public function testDeclaresBothKeyFieldsAsObscureWithEncryptedBackendModelInBothSections(): void
    {
        $dom = $this->loadSystemXml();
        $xpath = $this->xpath($dom);

        foreach (['recaptcha_backend', 'recaptcha_frontend'] as $sectionId) {
            foreach (['public_key', 'private_key'] as $fieldId) {
                $fields = $xpath->query(
                    "/config/system/section[@id='$sectionId']/group[@id='type_magetu_turnstile']/field[@id='$fieldId']"
                );
                $this->assertSame(1, $fields->length, "$sectionId/$fieldId field must exist exactly once.");

                /** @var \DOMElement $field */
                $field = $fields->item(0);
                $this->assertSame(
                    'obscure',
                    $field->getAttribute('type'),
                    "$sectionId/$fieldId must be type=\"obscure\"."
                );

                $backendModel = $xpath->query('backend_model', $field);
                $this->assertSame(1, $backendModel->length, "$sectionId/$fieldId must declare a backend_model.");
                $this->assertSame(
                    self::ENCRYPTED_BACKEND_MODEL,
                    $backendModel->item(0)->textContent,
                    "$sectionId/$fieldId backend_model must be the Encrypted backend model."
                );
            }
        }
    }

    public function testPointsSizeThemeAppearanceAtTask002sVirtualTypes(): void
    {
        $dom = $this->loadSystemXml();
        $xpath = $this->xpath($dom);

        $expected = [
            'size' => 'Magetu\Turnstile\Model\OptionSource\Size',
            'theme' => 'Magetu\Turnstile\Model\OptionSource\Theme',
            'appearance' => 'Magetu\Turnstile\Model\OptionSource\Appearance',
        ];

        foreach (['recaptcha_backend', 'recaptcha_frontend'] as $sectionId) {
            foreach ($expected as $fieldId => $virtualTypeName) {
                $sourceModels = $xpath->query(
                    "/config/system/section[@id='$sectionId']/group[@id='type_magetu_turnstile']"
                    . "/field[@id='$fieldId']/source_model"
                );
                $this->assertSame(1, $sourceModels->length, "$sectionId/$fieldId must declare a source_model.");
                $this->assertSame(
                    $virtualTypeName,
                    $sourceModels->item(0)->textContent,
                    "$sectionId/$fieldId source_model must be task 002's $virtualTypeName virtualType."
                );
            }
        }
    }
}
