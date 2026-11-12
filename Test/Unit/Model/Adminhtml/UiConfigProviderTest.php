<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\Model\Adminhtml;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magetu\Turnstile\Model\Adminhtml\UiConfigProvider;
use Magetu\Turnstile\Plugin\SwapWidgetTemplate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Admin-side half of Shared contract 1 (_plan.md). etc/adminhtml/system.xml declares every
 * recaptcha_backend/type_magetu_turnstile field showInWebsite="0" showInStore="0", so unlike the
 * storefront provider this one reads every path at DEFAULT scope (no scope argument), matching
 * module-re-captcha-version-3-invisible/Model/Adminhtml/UiConfigProvider.php.
 */
class UiConfigProviderTest extends TestCase
{
    private const PATH_PUBLIC_KEY = 'recaptcha_backend/type_magetu_turnstile/public_key';
    private const PATH_SIZE = 'recaptcha_backend/type_magetu_turnstile/size';
    private const PATH_THEME = 'recaptcha_backend/type_magetu_turnstile/theme';
    private const PATH_APPEARANCE = 'recaptcha_backend/type_magetu_turnstile/appearance';
    private const PATH_LANG = 'recaptcha_backend/type_magetu_turnstile/lang';

    /** @var ScopeConfigInterface&MockObject */
    private ScopeConfigInterface $scopeConfig;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
    }

    /**
     * Stub getValue() so it returns $value only when called for $path with NO scope argument
     * (default scope), and fails the test outright if any path is read with an explicit scope.
     *
     * @param array<string, string> $fields
     * @return void
     */
    private function configure(array $fields): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            function (string $path, string $scopeType = 'default') use ($fields): string {
                self::assertSame(
                    'default',
                    $scopeType,
                    "$path must be read at default scope: every adminhtml field is showInWebsite=0/showInStore=0."
                );

                return $fields[$path] ?? '';
            }
        );
    }

    /**
     * it reads the adminhtml config paths from the recaptcha_backend section (site-key half; the
     * secret-key half is Adminhtml\ValidationConfigProviderTest)
     */
    public function testReadsSiteKeyFromTheRecaptchaBackendSection(): void
    {
        $this->configure([
            self::PATH_PUBLIC_KEY => 'admin-site-key',
            self::PATH_SIZE => 'flexible',
            self::PATH_THEME => 'auto',
            self::PATH_APPEARANCE => 'always',
            self::PATH_LANG => '',
        ]);

        $provider = new UiConfigProvider($this->scopeConfig);

        $this->assertSame('admin-site-key', $provider->get()['rendering']['sitekey']);
    }

    /**
     * it exposes the site key under the rendering sitekey key that IsCaptchaEnabled requires
     */
    public function testExposesSiteKeyUnderRenderingSitekeyKey(): void
    {
        $this->configure([
            self::PATH_PUBLIC_KEY => 'admin-key',
            self::PATH_SIZE => 'flexible',
            self::PATH_THEME => 'auto',
            self::PATH_APPEARANCE => 'always',
            self::PATH_LANG => '',
        ]);

        $config = (new UiConfigProvider($this->scopeConfig))->get();

        $this->assertArrayHasKey('rendering', $config);
        $this->assertArrayHasKey('sitekey', $config['rendering']);
        $this->assertSame('admin-key', $config['rendering']['sitekey']);
    }

    /**
     * it returns the ui config array with exactly the keys the shared contract pins
     */
    public function testReturnsUiConfigArrayWithExactlyThePinnedKeys(): void
    {
        $this->configure([
            self::PATH_PUBLIC_KEY => 'k',
            self::PATH_SIZE => 'compact',
            self::PATH_THEME => 'dark',
            self::PATH_APPEARANCE => 'execute',
            self::PATH_LANG => 'de_DE',
        ]);

        $config = (new UiConfigProvider($this->scopeConfig))->get();

        $this->assertSame(['rendering', 'magetuTurnstile'], array_keys($config));
        $this->assertSame(
            ['sitekey', 'response-field-name', 'size', 'theme', 'appearance', 'language'],
            array_keys($config['rendering'])
        );
        $this->assertSame(
            [
                'sitekey' => 'k',
                'response-field-name' => SwapWidgetTemplate::RESPONSE_FIELD_NAME,
                'size' => 'compact',
                'theme' => 'dark',
                'appearance' => 'execute',
                'language' => 'de_DE',
            ],
            $config['rendering']
        );
        $this->assertTrue(
            $config[SwapWidgetTemplate::UI_CONFIG_MARKER],
            'The marker is how a consumer with no access to the captcha type resolver (the Knockout '
            . 'bridge reads this array out of serialised jsLayout in the browser) knows the config '
            . 'is ours. Drop it and that bridge hands a Cloudflare key to Google.'
        );
    }
}
