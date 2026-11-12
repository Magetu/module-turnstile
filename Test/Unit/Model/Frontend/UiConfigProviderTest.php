<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\Model\Frontend;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magetu\Turnstile\Model\Frontend\UiConfigProvider;
use Magetu\Turnstile\Plugin\SwapWidgetTemplate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins Shared contract 1 (_plan.md): the exact UI config array shape tasks 008/009 render from, and
 * the 'rendering'.'sitekey' key name Magento\ReCaptchaUi\Model\IsCaptchaEnabled requires. Get that
 * key wrong and the surface runs unguarded with a green dashboard (architecture risk R2).
 */
class UiConfigProviderTest extends TestCase
{
    private const PATH_PUBLIC_KEY = 'recaptcha_frontend/type_magetu_turnstile/public_key';
    private const PATH_SIZE = 'recaptcha_frontend/type_magetu_turnstile/size';
    private const PATH_THEME = 'recaptcha_frontend/type_magetu_turnstile/theme';
    private const PATH_APPEARANCE = 'recaptcha_frontend/type_magetu_turnstile/appearance';
    private const PATH_LANG = 'recaptcha_frontend/type_magetu_turnstile/lang';

    /** @var ScopeConfigInterface&MockObject */
    private ScopeConfigInterface $scopeConfig;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
    }

    /**
     * Stub getValue() so it returns $value only when called with $path at $expectedScope, and
     * fails the test outright if that path is ever read at a different scope.
     *
     * @param array<string, array{scope: string, value: string}> $fields
     * @return void
     */
    private function configure(array $fields): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            function (string $path, string $scopeType = 'default') use ($fields): string {
                if (!isset($fields[$path])) {
                    return '';
                }
                self::assertSame(
                    $fields[$path]['scope'],
                    $scopeType,
                    "$path must be read at {$fields[$path]['scope']} scope, got $scopeType."
                );

                return $fields[$path]['value'];
            }
        );
    }

    /**
     * it reads the site key and secret key from the frontend config paths at website scope
     * (site-key half; the secret-key half is Frontend\ValidationConfigProviderTest)
     */
    public function testReadsSiteKeyFromFrontendConfigPathAtWebsiteScope(): void
    {
        $this->configure([
            self::PATH_PUBLIC_KEY => ['scope' => ScopeInterface::SCOPE_WEBSITE, 'value' => '  site-key-abc  '],
            self::PATH_SIZE => ['scope' => ScopeInterface::SCOPE_WEBSITE, 'value' => 'flexible'],
            self::PATH_THEME => ['scope' => ScopeInterface::SCOPE_STORE, 'value' => 'auto'],
            self::PATH_APPEARANCE => ['scope' => ScopeInterface::SCOPE_WEBSITE, 'value' => 'always'],
            self::PATH_LANG => ['scope' => ScopeInterface::SCOPE_STORE, 'value' => ''],
        ]);
        $provider = new UiConfigProvider($this->scopeConfig);

        $this->assertSame('site-key-abc', $provider->get()['rendering']['sitekey']);
    }

    /**
     * it exposes the site key under the rendering sitekey key that IsCaptchaEnabled requires
     */
    public function testExposesSiteKeyUnderRenderingSitekeyKey(): void
    {
        $this->configure([
            self::PATH_PUBLIC_KEY => ['scope' => ScopeInterface::SCOPE_WEBSITE, 'value' => 'the-site-key'],
            self::PATH_SIZE => ['scope' => ScopeInterface::SCOPE_WEBSITE, 'value' => 'flexible'],
            self::PATH_THEME => ['scope' => ScopeInterface::SCOPE_STORE, 'value' => 'auto'],
            self::PATH_APPEARANCE => ['scope' => ScopeInterface::SCOPE_WEBSITE, 'value' => 'always'],
            self::PATH_LANG => ['scope' => ScopeInterface::SCOPE_STORE, 'value' => ''],
        ]);
        $provider = new UiConfigProvider($this->scopeConfig);

        $config = $provider->get();

        $this->assertArrayHasKey('rendering', $config);
        $this->assertArrayHasKey('sitekey', $config['rendering']);
        $this->assertSame('the-site-key', $config['rendering']['sitekey']);
    }

    /**
     * it returns the ui config array with exactly the keys the shared contract pins
     */
    public function testReturnsUiConfigArrayWithExactlyThePinnedKeys(): void
    {
        $this->configure([
            self::PATH_PUBLIC_KEY => ['scope' => ScopeInterface::SCOPE_WEBSITE, 'value' => 'k'],
            self::PATH_SIZE => ['scope' => ScopeInterface::SCOPE_WEBSITE, 'value' => 'flexible'],
            self::PATH_THEME => ['scope' => ScopeInterface::SCOPE_STORE, 'value' => 'auto'],
            self::PATH_APPEARANCE => ['scope' => ScopeInterface::SCOPE_WEBSITE, 'value' => 'always'],
            self::PATH_LANG => ['scope' => ScopeInterface::SCOPE_STORE, 'value' => 'en_US'],
        ]);
        $provider = new UiConfigProvider($this->scopeConfig);

        $config = $provider->get();

        $this->assertSame(['rendering', 'magetuTurnstile'], array_keys($config));
        $this->assertSame(
            ['sitekey', 'response-field-name', 'size', 'theme', 'appearance', 'language'],
            array_keys($config['rendering'])
        );
        $this->assertSame(
            [
                'sitekey' => 'k',
                'response-field-name' => SwapWidgetTemplate::RESPONSE_FIELD_NAME,
                'size' => 'flexible',
                'theme' => 'auto',
                'appearance' => 'always',
                'language' => 'en_US',
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

    /**
     * it reads the failure message and language at store scope (language half; the failure-message
     * half is Frontend\ValidationConfigProviderTest)
     */
    public function testReadsLanguageAtStoreScope(): void
    {
        $this->configure([
            self::PATH_PUBLIC_KEY => ['scope' => ScopeInterface::SCOPE_WEBSITE, 'value' => 'k'],
            self::PATH_SIZE => ['scope' => ScopeInterface::SCOPE_WEBSITE, 'value' => 'flexible'],
            self::PATH_THEME => ['scope' => ScopeInterface::SCOPE_STORE, 'value' => 'auto'],
            self::PATH_APPEARANCE => ['scope' => ScopeInterface::SCOPE_WEBSITE, 'value' => 'always'],
            self::PATH_LANG => ['scope' => ScopeInterface::SCOPE_STORE, 'value' => 'fr_FR'],
        ]);
        $provider = new UiConfigProvider($this->scopeConfig);

        $this->assertSame('fr_FR', $provider->get()['rendering']['language']);
    }
}
