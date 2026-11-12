<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\Model\Adminhtml;

use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magetu\Turnstile\Model\Adminhtml\ValidationConfigProvider;
use Magetu\Turnstile\Model\ValidationConfig;
use Magetu\Turnstile\Model\ValidationConfigFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Admin-side half of Shared contracts 2 and 4 (_plan.md). The load-bearing case here is the
 * hostname fallback (architecture risk R1): it must come from the BACKEND base URL, never the
 * storefront's and never the request Host header, or every admin login token fails the hostname
 * check: total admin lockout.
 */
class ValidationConfigProviderTest extends TestCase
{
    private const PATH_PRIVATE_KEY = 'recaptcha_backend/type_magetu_turnstile/private_key';
    private const PATH_VALIDATION_FAILURE = 'recaptcha_backend/type_magetu_turnstile/validation_failure_message';
    private const PATH_SEND_REMOTE_IP = 'recaptcha_backend/type_magetu_turnstile/send_remote_ip';
    private const PATH_VALIDATE_ACTION = 'recaptcha_backend/type_magetu_turnstile/validate_action';
    private const PATH_ALLOWED_HOSTNAMES = 'recaptcha_backend/type_magetu_turnstile/allowed_hostnames';

    /** @var ScopeConfigInterface&MockObject */
    private ScopeConfigInterface $scopeConfig;

    /** @var RemoteAddress&MockObject */
    private RemoteAddress $remoteAddress;

    /** @var BackendUrlInterface&MockObject */
    private BackendUrlInterface $backendUrl;

    /** @var ValidationConfigFactory&MockObject */
    private ValidationConfigFactory $validationConfigFactory;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->remoteAddress = $this->createMock(RemoteAddress::class);
        $this->backendUrl = $this->createMock(BackendUrlInterface::class);
        $this->validationConfigFactory = $this->createMock(ValidationConfigFactory::class);

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
     * @param array<string, string> $fields
     * @return void
     */
    private function configureScopeConfig(array $fields): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            function (string $path, string $scopeType = 'default') use ($fields): string {
                self::assertSame('default', $scopeType, "$path must be read at default scope.");

                return $fields[$path] ?? '';
            }
        );
    }

    private function stubFlags(bool $sendRemoteIp, bool $validateAction): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturnCallback(
            function (string $path, string $scopeType = 'default') use ($sendRemoteIp, $validateAction): bool {
                self::assertSame('default', $scopeType, "$path must be read at default scope.");

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
            $this->backendUrl,
            $this->validationConfigFactory
        );
    }

    /**
     * it reads the adminhtml config paths from the recaptcha_backend section (secret-key half; the
     * site-key half is Adminhtml\UiConfigProviderTest)
     */
    public function testReadsPrivateKeyFromTheRecaptchaBackendSectionAtDefaultScope(): void
    {
        $this->configureScopeConfig([
            self::PATH_PRIVATE_KEY => 'admin-secret',
            self::PATH_ALLOWED_HOSTNAMES => 'example.test',
        ]);
        $this->stubFlags(sendRemoteIp: false, validateAction: false);

        $this->assertSame('admin-secret', $this->makeProvider()->get()->getPrivateKey());
    }

    /**
     * it reads validate_action into the config so VerifyToken can skip action checking
     */
    public function testReadsValidateActionIntoTheConfig(): void
    {
        $this->configureScopeConfig([
            self::PATH_VALIDATION_FAILURE => 'msg',
            self::PATH_ALLOWED_HOSTNAMES => 'example.test',
        ]);
        $this->stubFlags(sendRemoteIp: false, validateAction: true);

        $this->assertTrue($this->makeProvider()->get()->getValidateAction());
    }

    /**
     * it uses the configured allowed_hostnames list when that field is non-empty
     */
    public function testUsesTheConfiguredAllowedHostnamesListWhenNonEmpty(): void
    {
        $this->configureScopeConfig([self::PATH_ALLOWED_HOSTNAMES => 'Admin.Example.Test']);
        $this->stubFlags(sendRemoteIp: false, validateAction: false);
        $this->backendUrl->expects($this->never())->method('getBaseUrl');

        $this->assertSame(['admin.example.test'], $this->makeProvider()->get()->getAllowedHostnames());
    }

    /**
     * it falls back to the BACKEND base URL host on the adminhtml provider when the field is empty
     */
    public function testFallsBackToTheBackendBaseUrlHostWhenAllowedHostnamesIsEmpty(): void
    {
        $this->configureScopeConfig([self::PATH_ALLOWED_HOSTNAMES => '']);
        $this->stubFlags(sendRemoteIp: false, validateAction: false);
        $this->backendUrl->method('getBaseUrl')->willReturn('https://admin.example.test/');

        $this->assertSame(
            ['admin.example.test'],
            $this->makeProvider()->get()->getAllowedHostnames(),
            'Must derive from the BACKEND base URL, never the storefront: getting this wrong is '
            . 'total admin lockout (architecture risk R1).'
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
     * it still falls back to the backend base URL host when the configured list parses to nothing
     *
     * @dataProvider unparseableHostnameProvider
     */
    public function testFallsBackWhenTheConfiguredHostnameListParsesToNothing(string $configured): void
    {
        // The branch used to test the RAW string, so "," counted as configured, parsed to an empty
        // list, and an empty allowlist makes VerifyToken skip the hostname check. On the admin
        // provider that is the check standing between a token minted anywhere else and the admin
        // login form. This half of the fix went unpinned when the storefront half was pinned.
        $this->configureScopeConfig([self::PATH_ALLOWED_HOSTNAMES => $configured]);
        $this->stubFlags(sendRemoteIp: false, validateAction: false);
        $this->backendUrl->method('getBaseUrl')->willReturn('https://admin.example.test/');

        $this->assertSame(
            ['admin.example.test'],
            $this->makeProvider()->get()->getAllowedHostnames(),
            'an unparseable list must never yield an empty allowlist'
        );
    }
}
