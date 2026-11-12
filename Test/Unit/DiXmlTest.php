<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the type-registration DI wiring, mirrored from
 * vendor/magento/module-re-captcha-version-3-invisible.
 *
 * The type must resolve under three names: `frontend_magetu_turnstile` and `admin_magetu_turnstile`
 * in the GLOBAL etc/di.xml (the composite CaptchaTypeResolver prefixes the area onto the type in
 * every area except frontend/adminhtml, including webapi_rest, CaptchaTypeResolver.php:31-40), and
 * the bare `magetu_turnstile` in etc/frontend/di.xml and etc/adminhtml/di.xml (the area-specific
 * resolver preferences return the raw config value there). Every provider is registered through its
 * generated \Proxy so ValidationConfigResolver's constructor-time instanceof check
 * (ValidationConfigResolver.php:44-51) never materialises the real provider.
 *
 * etc/di.xml is a SHARED file (tasks 002, 006, 007, 008 all add nodes to it), so these tests query
 * only the nodes task 002 owns and never assert on the file's total node count.
 */
class DiXmlTest extends TestCase
{
    private const GLOBAL_DI = 'etc/di.xml';
    private const FRONTEND_DI = 'etc/frontend/di.xml';
    private const ADMINHTML_DI = 'etc/adminhtml/di.xml';

    private const UI_CONFIG_RESOLVER = 'Magento\ReCaptchaUi\Model\UiConfigResolver';
    private const VALIDATION_CONFIG_RESOLVER = 'Magento\ReCaptchaUi\Model\ValidationConfigResolver';

    private function loadXPath(string $relativePath): DOMXPath
    {
        $path = \dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path, "$relativePath must exist.");

        $dom = new DOMDocument();
        $this->assertTrue($dom->load($path), "$relativePath must be valid XML.");

        return new DOMXPath($dom);
    }

    /**
     * @return string[] text content of every matching <item>, in document order
     */
    private function itemValues(DOMXPath $xpath, string $expression): array
    {
        $nodes = $xpath->query($expression);
        $values = [];
        foreach ($nodes as $node) {
            $values[] = \trim($node->textContent);
        }

        return $values;
    }

    private function providerItemValue(
        DOMXPath $xpath,
        string $typeName,
        string $argumentName,
        string $itemName
    ): ?string {
        $expression = \sprintf(
            '//type[@name="%s"]/arguments/argument[@name="%s"]/item[@name="%s"]',
            $typeName,
            $argumentName,
            $itemName
        );
        $values = $this->itemValues($xpath, $expression);

        return $values[0] ?? null;
    }

    public function testRegistersFrontendPrefixedTypeInTheGlobalDiConfiguration(): void
    {
        $xpath = $this->loadXPath(self::GLOBAL_DI);

        $this->assertSame(
            'Magetu\Turnstile\Model\Frontend\UiConfigProvider\Proxy',
            $this->providerItemValue(
                $xpath,
                self::UI_CONFIG_RESOLVER,
                'uiConfigProviders',
                'frontend_magetu_turnstile'
            ),
            'UiConfigResolver must register frontend_magetu_turnstile globally.'
        );
        $this->assertSame(
            'Magetu\Turnstile\Model\Frontend\ValidationConfigProvider\Proxy',
            $this->providerItemValue(
                $xpath,
                self::VALIDATION_CONFIG_RESOLVER,
                'validationConfigProviders',
                'frontend_magetu_turnstile'
            ),
            'ValidationConfigResolver must register frontend_magetu_turnstile globally.'
        );
    }

    public function testRegistersAdminPrefixedTypeInTheGlobalDiConfiguration(): void
    {
        $xpath = $this->loadXPath(self::GLOBAL_DI);

        $this->assertSame(
            'Magetu\Turnstile\Model\Adminhtml\UiConfigProvider\Proxy',
            $this->providerItemValue($xpath, self::UI_CONFIG_RESOLVER, 'uiConfigProviders', 'admin_magetu_turnstile'),
            'UiConfigResolver must register admin_magetu_turnstile globally.'
        );
        $this->assertSame(
            'Magetu\Turnstile\Model\Adminhtml\ValidationConfigProvider\Proxy',
            $this->providerItemValue(
                $xpath,
                self::VALIDATION_CONFIG_RESOLVER,
                'validationConfigProviders',
                'admin_magetu_turnstile'
            ),
            'ValidationConfigResolver must register admin_magetu_turnstile globally.'
        );
    }

    public function testRegistersTheBareNameInBothTheFrontendAndAdminhtmlAreaDiFiles(): void
    {
        $frontend = $this->loadXPath(self::FRONTEND_DI);
        $this->assertSame(
            'Magetu\Turnstile\Model\Frontend\UiConfigProvider\Proxy',
            $this->providerItemValue($frontend, self::UI_CONFIG_RESOLVER, 'uiConfigProviders', 'magetu_turnstile'),
            'etc/frontend/di.xml must register the bare magetu_turnstile UI config provider.'
        );
        $this->assertSame(
            'Magetu\Turnstile\Model\Frontend\ValidationConfigProvider\Proxy',
            $this->providerItemValue(
                $frontend,
                self::VALIDATION_CONFIG_RESOLVER,
                'validationConfigProviders',
                'magetu_turnstile'
            ),
            'etc/frontend/di.xml must register the bare magetu_turnstile validation config provider.'
        );

        $adminhtml = $this->loadXPath(self::ADMINHTML_DI);
        $this->assertSame(
            'Magetu\Turnstile\Model\Adminhtml\UiConfigProvider\Proxy',
            $this->providerItemValue($adminhtml, self::UI_CONFIG_RESOLVER, 'uiConfigProviders', 'magetu_turnstile'),
            'etc/adminhtml/di.xml must register the bare magetu_turnstile UI config provider.'
        );
        $this->assertSame(
            'Magetu\Turnstile\Model\Adminhtml\ValidationConfigProvider\Proxy',
            $this->providerItemValue(
                $adminhtml,
                self::VALIDATION_CONFIG_RESOLVER,
                'validationConfigProviders',
                'magetu_turnstile'
            ),
            'etc/adminhtml/di.xml must register the bare magetu_turnstile validation config provider.'
        );
    }

    public function testRegistersEveryProviderThroughItsGeneratedProxyRatherThanTheConcreteClass(): void
    {
        $expression = \sprintf(
            '//type[@name="%s" or @name="%s"]/arguments/argument/item[contains(@name, "magetu_turnstile")]',
            self::UI_CONFIG_RESOLVER,
            self::VALIDATION_CONFIG_RESOLVER
        );

        $files = [self::GLOBAL_DI, self::FRONTEND_DI, self::ADMINHTML_DI];
        $checked = 0;
        foreach ($files as $file) {
            $xpath = $this->loadXPath($file);
            foreach ($this->itemValues($xpath, $expression) as $value) {
                $this->assertStringEndsWith(
                    '\Proxy',
                    $value,
                    "$file registers \"$value\" without going through its generated Proxy."
                );
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, 'No magetu_turnstile provider items were found to check.');
    }

    public function testAddsExactlyOneMagetuTurnstileOptionToTheReCaptchaAdminUiTypeVirtualType(): void
    {
        $xpath = $this->loadXPath(self::GLOBAL_DI);

        $items = $xpath->query(
            '//virtualType[@name="Magento\ReCaptchaAdminUi\Model\OptionSource\Type"]'
            . '/arguments/argument[@name="options"]/item'
        );
        $this->assertCount(1, $items, 'Exactly one option item must be added to the Type virtualType.');

        $item = $items->item(0);
        $this->assertSame('magetu_turnstile', $item->getAttribute('name'));

        $valueNodes = $xpath->query('item[@name="value"]', $item);
        $this->assertSame('magetu_turnstile', \trim($valueNodes->item(0)->textContent));

        $labelNodes = $xpath->query('item[@name="label"]', $item);
        $this->assertSame('Cloudflare Turnstile', \trim($labelNodes->item(0)->textContent));
    }

    public function testMarksAllFourKeyConfigPathsAsBothSensitiveAndEnvironmentInTheTypePool(): void
    {
        $xpath = $this->loadXPath(self::GLOBAL_DI);

        $paths = [
            'recaptcha_backend/type_magetu_turnstile/public_key',
            'recaptcha_backend/type_magetu_turnstile/private_key',
            'recaptcha_frontend/type_magetu_turnstile/public_key',
            'recaptcha_frontend/type_magetu_turnstile/private_key',
        ];

        foreach (['sensitive', 'environment'] as $argumentName) {
            foreach ($paths as $path) {
                $this->assertSame(
                    '1',
                    $this->providerItemValue(
                        $xpath,
                        'Magento\Config\Model\Config\TypePool',
                        $argumentName,
                        $path
                    ),
                    "TypePool argument \"$argumentName\" must mark \"$path\" as 1."
                );
            }
        }
    }

    public function testDeclaresTheThreeSourceModelVirtualTypesUnderTheExactNamesSystemXmlWillReference(): void
    {
        $xpath = $this->loadXPath(self::GLOBAL_DI);

        $expectations = [
            'Magetu\Turnstile\Model\OptionSource\Size' => ['normal', 'flexible', 'compact'],
            'Magetu\Turnstile\Model\OptionSource\Theme' => ['auto', 'light', 'dark'],
            'Magetu\Turnstile\Model\OptionSource\Appearance' => ['always', 'execute', 'interaction-only'],
        ];

        foreach ($expectations as $virtualTypeName => $expectedValues) {
            $nodes = $xpath->query(\sprintf('//virtualType[@name="%s"]', $virtualTypeName));
            $this->assertCount(1, $nodes, "virtualType \"$virtualTypeName\" must be declared exactly once.");
            $this->assertSame(
                'Magento\ReCaptchaAdminUi\Model\OptionSource',
                $nodes->item(0)->getAttribute('type'),
                "virtualType \"$virtualTypeName\" must extend the generic OptionSource class."
            );

            $values = $this->itemValues(
                $xpath,
                \sprintf(
                    '//virtualType[@name="%s"]/arguments/argument[@name="options"]/item/item[@name="value"]',
                    $virtualTypeName
                )
            );
            $this->assertSame($expectedValues, $values, "virtualType \"$virtualTypeName\" option values must match.");
        }
    }

    public function testDisablesNoPlugin(): void
    {
        foreach ([self::GLOBAL_DI, self::FRONTEND_DI, self::ADMINHTML_DI] as $file) {
            $disabled = $this->itemValues($this->loadXPath($file), '//plugin[@disabled="true"]/@name');
            $this->assertSame([], $disabled, "$file must not disable a plugin.");
        }
    }

    private const REQUEST_HANDLER = 'Magento\ReCaptchaUi\Model\RequestHandlerInterface';
    private const KEEP_INPUT_PLUGIN = 'Magetu\Turnstile\Plugin\KeepFormInputOnReject';
    private const KEEP_INPUT_PLUGIN_NAME = 'magetu_turnstile_keep_form_input_on_reject';

    public function testFrontendDiDeclaresExactlyOneKeepFormInputPluginOnTheRequestHandler(): void
    {
        $xpath = $this->loadXPath(self::FRONTEND_DI);

        $plugins = $xpath->query(\sprintf('//type[@name="%s"]/plugin', self::REQUEST_HANDLER));
        $this->assertCount(1, $plugins, 'Exactly one plugin must be declared on the request handler.');
        $this->assertSame(self::KEEP_INPUT_PLUGIN_NAME, $plugins->item(0)->getAttribute('name'));
        $this->assertSame(self::KEEP_INPUT_PLUGIN, $plugins->item(0)->getAttribute('type'));
    }

    public function testFrontendDiPassesTheCustomerSessionProxyToTheKeepFormInputPlugin(): void
    {
        $xpath = $this->loadXPath(self::FRONTEND_DI);

        $this->assertSame(
            'Magento\Customer\Model\Session\Proxy',
            $this->providerItemValueFromArgument($xpath, self::KEEP_INPUT_PLUGIN, 'customerSession'),
            'The plugin must receive the customer session through its generated Proxy.'
        );
    }

    public function testKeepFormInputPluginIsNotWiredOutsideTheFrontendArea(): void
    {
        foreach ([self::GLOBAL_DI, self::ADMINHTML_DI] as $file) {
            $this->assertStringNotContainsString(
                'KeepFormInputOnReject',
                (string) \file_get_contents(\dirname(__DIR__, 2) . '/' . $file),
                "$file must not mention the keep-form-input plugin."
            );
        }
    }

    public function testEveryClassNameInTheKeepFormInputWiringResolves(): void
    {
        $this->assertTrue(\interface_exists(self::REQUEST_HANDLER), self::REQUEST_HANDLER . ' must exist.');
        $this->assertTrue(\class_exists(self::KEEP_INPUT_PLUGIN), self::KEEP_INPUT_PLUGIN . ' must exist.');
        $this->assertTrue(\class_exists(\Magento\Customer\Model\Session::class));

        $proxy = $this->providerItemValueFromArgument(
            $this->loadXPath(self::FRONTEND_DI),
            self::KEEP_INPUT_PLUGIN,
            'customerSession'
        );
        $this->assertSame(\Magento\Customer\Model\Session::class . '\\Proxy', $proxy);
    }

    private function providerItemValueFromArgument(DOMXPath $xpath, string $typeName, string $argumentName): ?string
    {
        $nodes = $xpath->query(
            \sprintf('//type[@name="%s"]/arguments/argument[@name="%s"]', $typeName, $argumentName)
        );

        return $nodes->length > 0 ? \trim($nodes->item(0)->textContent) : null;
    }
}
