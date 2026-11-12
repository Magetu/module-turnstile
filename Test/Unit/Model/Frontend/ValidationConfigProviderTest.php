<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\Model\Frontend;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magetu\Turnstile\Model\Frontend\ValidationConfigProvider;
use Magetu\Turnstile\Model\ValidationConfig;
use Magetu\Turnstile\Model\ValidationConfigFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins Shared contracts 2 and 4 (_plan.md) on the storefront side: a fresh ValidationConfig per
 * get() call, and an allowed-hostname allowlist derived from configured base URLs only.
 */
class ValidationConfigProviderTest extends TestCase
{
    private const PATH_PRIVATE_KEY = 'recaptcha_frontend/type_magetu_turnstile/private_key';
    private const PATH_VALIDATION_FAILURE = 'recaptcha_frontend/type_magetu_turnstile/validation_failure_message';
    private const PATH_SEND_REMOTE_IP = 'recaptcha_frontend/type_magetu_turnstile/send_remote_ip';
    private const PATH_VALIDATE_ACTION = 'recaptcha_frontend/type_magetu_turnstile/validate_action';
    private const PATH_ALLOWED_HOSTNAMES = 'recaptcha_frontend/type_magetu_turnstile/allowed_hostnames';

    /** @var ScopeConfigInterface&MockObject */
    private ScopeConfigInterface $scopeConfig;

    /** @var RemoteAddress&MockObject */
    private RemoteAddress $remoteAddress;

    /** @var StoreManagerInterface&MockObject */
    private StoreManagerInterface $storeManager;

    /** @var ValidationConfigFactory&MockObject */
    private ValidationConfigFactory $validationConfigFactory;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->remoteAddress = $this->createMock(RemoteAddress::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->validationConfigFactory = $this->createMock(ValidationConfigFactory::class);

        // The factory mock builds a real ValidationConfig from whatever the provider passes it, so
        // assertions below can read the result back through ValidationConfig's typed getters instead
        // of reaching into a raw array.
        $this->validationConfigFactory->method('create')->willReturnCallback(
            static fn (array $data): ValidationConfig => new ValidationConfig(
                privateKey: $data['privateKey'] ?? '',
                remoteIp: $data['remoteIp'] ?? '',
                validationFailureMessage: $data['validationFailureMessage'] ?? '',
                validateAction: $data['validateAction'] ?? false,
                allowedHostnames: $data['allowedHostnames'] ?? [],
            )
        );
    }

    /**
     * Stub getValue() so it returns $value only when called with $path at $expectedScope, and
     * fails the test outright if that path is ever read at a different scope. Unlisted paths
     * default to '' at any scope.
     *
     * @param array<string, array{scope: string, value: string}> $fields
     * @return void
     */
    private function configureScopeConfig(array $fields): void
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
     * A complete, valid field set. allowed_hostnames is deliberately non-empty here so tests that
     * are not exercising the hostname-derivation logic never fall through to the StoreManager
     * fallback path and need a Store mock of their own.
     */
    private function baseFields(): array
    {
        return [
            self::PATH_PRIVATE_KEY => ['scope' => ScopeInterface::SCOPE_WEBSITE, 'value' => 'secret-abc'],
            self::PATH_VALIDATION_FAILURE => ['scope' => ScopeInterface::SCOPE_STORE, 'value' => 'Try again.'],
            self::PATH_ALLOWED_HOSTNAMES => ['scope' => ScopeInterface::SCOPE_WEBSITE, 'value' => 'example.test'],
        ];
    }

    private function stubFlags(bool $sendRemoteIp, bool $validateAction): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturnCallback(
            function (string $path, string $scopeType = 'default') use ($sendRemoteIp, $validateAction): bool {
                self::assertSame(ScopeInterface::SCOPE_WEBSITE, $scopeType, "$path must be read at website scope.");

                return match ($path) {
                    self::PATH_SEND_REMOTE_IP => $sendRemoteIp,
                    self::PATH_VALIDATE_ACTION => $validateAction,
                    default => false,
                };
            }
        );
    }

    private function makeProvider(): ValidationConfigProvider
    {
        return new ValidationConfigProvider(
            $this->scopeConfig,
            $this->remoteAddress,
            $this->storeManager,
            $this->validationConfigFactory
        );
    }

    /**
     * it reads the site key and secret key from the frontend config paths at website scope
     * (secret-key half; the site-key half is Frontend\UiConfigProviderTest)
     */
    public function testReadsPrivateKeyFromFrontendConfigPathAtWebsiteScope(): void
    {
        $this->configureScopeConfig($this->baseFields());
        $this->stubFlags(sendRemoteIp: false, validateAction: false);

        $this->assertSame('secret-abc', $this->makeProvider()->get()->getPrivateKey());
    }

    /**
     * it reads the failure message and language at store scope (failure-message half; the
     * language half is Frontend\UiConfigProviderTest)
     */
    public function testReadsValidationFailureMessageAtStoreScope(): void
    {
        $this->configureScopeConfig($this->baseFields());
        $this->stubFlags(sendRemoteIp: false, validateAction: false);

        $this->assertSame('Try again.', $this->makeProvider()->get()->getValidationFailureMessage());
    }

    /**
     * it omits the remote IP from the validation config when send_remote_ip is disabled
     */
    public function testOmitsRemoteIpWhenSendRemoteIpIsDisabled(): void
    {
        $this->configureScopeConfig($this->baseFields());
        $this->stubFlags(sendRemoteIp: false, validateAction: false);
        $this->remoteAddress->expects($this->never())->method('getRemoteAddress');

        $this->assertSame('', $this->makeProvider()->get()->getRemoteIp());
    }

    /**
     * it includes the remote IP from RemoteAddress when send_remote_ip is enabled
     */
    public function testIncludesRemoteIpFromRemoteAddressWhenSendRemoteIpIsEnabled(): void
    {
        $this->configureScopeConfig($this->baseFields());
        $this->stubFlags(sendRemoteIp: true, validateAction: false);
        $this->remoteAddress->method('getRemoteAddress')->willReturn('198.51.100.7');

        $this->assertSame('198.51.100.7', $this->makeProvider()->get()->getRemoteIp());
    }

    /**
     * it reads validate_action into the config so VerifyToken can skip action checking
     */
    public function testReadsValidateActionIntoTheConfig(): void
    {
        $this->configureScopeConfig($this->baseFields());
        $this->stubFlags(sendRemoteIp: false, validateAction: true);

        $this->assertTrue($this->makeProvider()->get()->getValidateAction());
    }

    /**
     * it reads validate_action into the config so VerifyToken can skip action checking (the
     * disabled side; PHPUnit mock stubs are fixed once configured, so this needs a fresh mock set
     * rather than re-stubbing the one from testReadsValidateActionIntoTheConfig)
     */
    public function testReadsValidateActionDisabledIntoTheConfig(): void
    {
        $this->configureScopeConfig($this->baseFields());
        $this->stubFlags(sendRemoteIp: false, validateAction: false);

        $this->assertFalse($this->makeProvider()->get()->getValidateAction());
    }

    /**
     * it uses the configured allowed_hostnames list when that field is non-empty
     */
    public function testUsesTheConfiguredAllowedHostnamesListWhenNonEmpty(): void
    {
        $fields = $this->baseFields();
        $fields[self::PATH_ALLOWED_HOSTNAMES] = [
            'scope' => ScopeInterface::SCOPE_WEBSITE,
            'value' => ' Example.TEST , store.example.test ,,',
        ];
        $this->configureScopeConfig($fields);
        $this->stubFlags(sendRemoteIp: false, validateAction: false);
        $this->storeManager->expects($this->never())->method('getStore');

        $this->assertSame(
            ['example.test', 'store.example.test'],
            $this->makeProvider()->get()->getAllowedHostnames()
        );
    }

    /**
     * it falls back to the lowercased store web base URL host on the frontend provider when the field is empty
     */
    public function testFallsBackToTheStoreWebBaseUrlHostWhenAllowedHostnamesIsEmpty(): void
    {
        $fields = $this->baseFields();
        $fields[self::PATH_ALLOWED_HOSTNAMES] = ['scope' => ScopeInterface::SCOPE_WEBSITE, 'value' => ''];
        $this->configureScopeConfig($fields);
        $this->stubFlags(sendRemoteIp: false, validateAction: false);

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->with(\Magento\Framework\UrlInterface::URL_TYPE_WEB)
            ->willReturn('https://Storefront.Example.TEST/');
        $this->storeManager->method('getStore')->willReturn($store);

        $this->assertSame(
            ['storefront.example.test'],
            $this->makeProvider()->get()->getAllowedHostnames()
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unparseableHostnameProvider(): array
    {
        return [
            'a lone comma' => [','],
            'commas and spaces' => [' , , '],
            'whitespace only' => ['   '],
        ];
    }

    /**
     * it still falls back to the base URL host when the configured list parses to nothing
     *
     * @dataProvider unparseableHostnameProvider
     */
    public function testFallsBackWhenTheConfiguredHostnameListParsesToNothing(string $configured): void
    {
        // The branch used to test the RAW string, so a value like "," was "configured", parsed to an
        // empty list, and VerifyToken skips the hostname check whenever the allowlist is empty. One
        // stray comma in an admin field silently switched off the defence against a token minted on
        // another site sharing the sitekey. A wrong value must stay strict, not open.
        $fields = $this->baseFields();
        $fields[self::PATH_ALLOWED_HOSTNAMES] = ['scope' => ScopeInterface::SCOPE_WEBSITE, 'value' => $configured];
        $this->configureScopeConfig($fields);
        $this->stubFlags(sendRemoteIp: false, validateAction: false);

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->with(\Magento\Framework\UrlInterface::URL_TYPE_WEB)
            ->willReturn('https://storefront.example.test/');
        $this->storeManager->method('getStore')->willReturn($store);

        $this->assertSame(
            ['storefront.example.test'],
            $this->makeProvider()->get()->getAllowedHostnames(),
            'an unparseable list must never yield an empty allowlist'
        );
    }

    /**
     * it returns a new ValidationConfig instance on every get call rather than a memoised one
     */
    public function testReturnsANewValidationConfigInstanceOnEveryGetCall(): void
    {
        $this->configureScopeConfig($this->baseFields());
        $this->stubFlags(sendRemoteIp: false, validateAction: false);
        $provider = $this->makeProvider();

        $first = $provider->get();
        $second = $provider->get();

        $this->assertNotSame(
            $first,
            $second,
            'get() must build a fresh ValidationConfig every call; ValidationConfigResolver delegates '
            . 'straight through on each call and task 007 mutates the returned instance, so a '
            . 'memoised object leaks one action into a validation for a different captcha id.'
        );
    }
}
