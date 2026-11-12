<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the packaging facts composer.json must carry for this module to install cleanly as a
 * standalone Packagist package and to behave correctly at runtime once installed: the package
 * identity, licence, dependency set (and the specific version floor task 006 depends on), the
 * declared compatibility range, and the release/autoload wiring.
 */
class ComposerJsonTest extends TestCase
{
    /** @var array<string, mixed> Decoded composer.json, format documented by each test that reads it */
    private array $composerJson;

    protected function setUp(): void
    {
        $path = \dirname(__DIR__, 2) . '/composer.json';
        $this->assertFileExists($path, 'composer.json must exist.');

        $decoded = \json_decode((string) \file_get_contents($path), true);
        $this->assertIsArray($decoded, 'composer.json must be valid JSON.');

        $this->composerJson = $decoded;
    }

    public function testDeclaresPackageNameAndType(): void
    {
        $this->assertSame('magetu/module-turnstile', $this->composerJson['name'] ?? null);
        $this->assertSame('magento2-module', $this->composerJson['type'] ?? null);
    }

    public function testDeclaresMitLicense(): void
    {
        $this->assertSame('MIT', $this->composerJson['license'] ?? null);
    }

    public function testRequiresOnlyTheCaptchaFrameworkPackagesItDirectlyDependsOn(): void
    {
        $require = $this->composerJson['require'] ?? [];
        $reCaptchaPackages = \array_filter(
            \array_keys($require),
            static fn (string $name): bool => \str_starts_with($name, 'magento/module-re-captcha-')
        );

        $this->assertEqualsCanonicalizing(
            [
                'magento/module-re-captcha-ui',
                'magento/module-re-captcha-admin-ui',
                'magento/module-re-captcha-validation-api',
                'magento/module-re-captcha-validation',
                'magento/module-re-captcha-frontend-ui',
            ],
            \array_values($reCaptchaPackages),
            'This module implements module-re-captcha-ui provider interfaces and plugins its block, '
            . 'extends module-re-captcha-admin-ui\'s OptionSource, and depends on '
            . 'module-re-captcha-validation-api\'s ValidatorInterface/ValidationConfigInterface/error '
            . 'providers. -validation belongs here too, and NOT for the reason its name suggests: it '
            . 'is Google\'s validator, but it also carries the only DI preference for '
            . 'ValidatorInterface in the stack (one declaration across vendor/ and app/ on 2.4.7-p10), '
            . 'so without it the interface this module plugs into cannot be resolved at all. An '
            . 'earlier version of this test asserted the opposite, from reasoning rather than a grep. '
            . '-frontend-ui belongs here for a third reason: it owns the ONLY frontend-area preference for '
            . 'CaptchaTypeResolverInterface, so uninstalling it makes getCaptchaTypeFor() return null '
            . 'on the storefront and silently unguards every surface, for every captcha type. Only '
            . '-webapi-* stays optional.'
        );
    }

    public function testPinsValidationApiAtOrAboveTheVersionThatShipsValidationErrorMessagesProvider(): void
    {
        $constraint = $this->composerJson['require']['magento/module-re-captcha-validation-api'] ?? '';

        // Task 006 hard-depends on ValidationErrorMessagesProvider, back-added in a 1.1.x patch
        // release. A bare "^1.1" can resolve below it and fatal at runtime on a consumer's store
        // instead of failing at composer install time. 1.1.3 is the verified floor (see task 001).
        $this->assertMatchesRegularExpression(
            '/^\^1\.1\.[3-9]\d*$/',
            $constraint,
            'magento/module-re-captcha-validation-api must be pinned to ^1.1.3 or higher, not the '
            . 'bare ^1.1 range that a naive copy of the reference skeleton would use.'
        );
    }

    public function testRequiresCoreDependencies(): void
    {
        $require = $this->composerJson['require'] ?? [];

        $this->assertArrayHasKey('php', $require);
        $this->assertSame('~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0', $require['php']);
        $this->assertArrayHasKey('magento/framework', $require);
        $this->assertSame('^103.0', $require['magento/framework']);
        $this->assertArrayHasKey('magento/module-config', $require);
        $this->assertArrayHasKey('magento/module-store', $require);
    }

    public function testDeclaresASupportedMagentoVersionRange(): void
    {
        // No single composer.json field is the canonical place for this; magento/framework's own
        // constraint IS the version-range declaration composer resolves against, and it must be
        // present and non-wildcard so consumers get a real compatibility check, not "*".
        $frameworkConstraint = $this->composerJson['require']['magento/framework'] ?? '';
        $this->assertNotSame(
            '*',
            $frameworkConstraint,
            'magento/framework must declare a real version range, not the wildcard native packages use.'
        );
        $this->assertMatchesRegularExpression('/^\^\d+\.\d+$/', $frameworkConstraint);

        // The range actually verified end-to-end (task 001) is narrower than what the framework
        // constraint alone implies (task 011 owns widening it with a compile-and-smoke matrix),
        // that verified scope is recorded explicitly so it isn't confused with the wider claim.
        $this->assertArrayHasKey('extra', $this->composerJson);
        $this->assertArrayHasKey('magento-versions-verified', $this->composerJson['extra']);
        $this->assertNotSame('', \trim((string) $this->composerJson['extra']['magento-versions-verified']));
    }

    /**
     * 2.4.9 raised the floor to PHP 8.3 and ships magento/framework 103.0.9 with
     * module-re-captcha-ui/-admin-ui 1.1.6 and -validation-api 1.1.5 (read from the vendor composer
     * repository, 2026-08-08). Every one of those falls inside the constraints below, so a resolver
     * will install this package on 2.4.9. Narrowing any of them silently drops that support, which
     * is why the arithmetic is asserted rather than described in the README.
     */
    public function testTheDeclaredConstraintsStillResolveOnTheLatestMagentoRelease(): void
    {
        $require = $this->composerJson['require'];

        $this->assertStringContainsString('~8.5.0', $require['php'], '2.4.9 runs on PHP 8.3-8.5');
        $this->assertStringContainsString('~8.3.0', $require['php']);

        // ^103.0 admits 103.0.9; a bump to ^104.0 or a pin like 103.0.7 would not.
        $this->assertSame('^103.0', $require['magento/framework']);

        // ^1.1 admits 1.1.6, ^1.1.3 admits 1.1.5.
        $this->assertSame('^1.1', $require['magento/module-re-captcha-ui']);
        $this->assertSame('^1.1', $require['magento/module-re-captcha-admin-ui']);
        $this->assertSame('^1.1.3', $require['magento/module-re-captcha-validation-api']);
    }

    public function testOmitsAHardcodedVersionFieldSoTheGitTagDrivesTheRelease(): void
    {
        $this->assertArrayNotHasKey(
            'version',
            $this->composerJson,
            'No "version" field: the git tag drives the release. A hardcoded version drifts stale '
            . 'against its own tag (see the box22 reference package).'
        );
    }

    public function testAutoloadsRegistrationFileAndThePsr4Namespace(): void
    {
        $autoload = $this->composerJson['autoload'] ?? [];

        $this->assertSame(['registration.php'], $autoload['files'] ?? null);
        $this->assertSame(
            ['Magetu\\Turnstile\\' => ''],
            $autoload['psr-4'] ?? null
        );
    }

    public function testDeclaresRequireDevPhpunit(): void
    {
        $requireDev = $this->composerJson['require-dev'] ?? [];

        $this->assertArrayHasKey('phpunit/phpunit', $requireDev);
        $this->assertSame('^9.5 || ^10.5 || ^12.0', $requireDev['phpunit/phpunit']);
    }

    public function testDeclaresKeywordsAndAuthors(): void
    {
        $this->assertNotEmpty($this->composerJson['keywords'] ?? []);
        $this->assertContains('turnstile', $this->composerJson['keywords']);
        $this->assertNotEmpty($this->composerJson['authors'] ?? []);
    }

    public function testRequiresTheCustomerModuleItsKeepFormInputPluginNames(): void
    {
        $this->assertArrayHasKey('magento/module-customer', $this->composerJson['require'] ?? []);
    }
}
