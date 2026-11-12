<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pins which of Magento's reCAPTCHA packages this module actually needs.
 *
 * The stack splits in two, and the split is the whole answer to "how much of it can I uninstall?":
 *
 * - **Framework**, four packages. The type registry, the resolvers, the validator interface and the
 *   admin option source. This module is a captcha *type* plugged into them, so they are not optional
 *   and are declared in `composer.json`.
 * - **Surfaces**, everything else. Each one guards its own forms and endpoints for *whatever* type is
 *   configured, this module included. Removing one removes that surface's protection, for every
 *   captcha type, which is a store decision and not this module's business.
 *
 * What this test defends is the first list staying short. A reference to a surface package from
 * inside this module would make it silently un-installable on a store that removed that package, and
 * the failure would be a missing class at runtime rather than a composer error.
 */
class DependencySurfaceTest extends TestCase
{
    /**
     * The only Magento reCAPTCHA namespaces this module's own code may name.
     *
     * @var string[]
     */
    private const ALLOWED_NAMESPACES = [
        'ReCaptchaUi',
        'ReCaptchaValidationApi',
        'ReCaptchaAdminUi',
        'ReCaptchaFrontendUi',
    ];

    /**
     * Composer package for each of those, so the two lists cannot drift.
     *
     * @var array<string, string>
     */
    private const PACKAGE_FOR = [
        'ReCaptchaUi' => 'magento/module-re-captcha-ui',
        'ReCaptchaValidationApi' => 'magento/module-re-captcha-validation-api',
        'ReCaptchaAdminUi' => 'magento/module-re-captcha-admin-ui',
        'ReCaptchaFrontendUi' => 'magento/module-re-captcha-frontend-ui',
    ];

    private function moduleRoot(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * Every shipped PHP and XML file, tests excluded.
     *
     * @return string[]
     */
    private function shippedFiles(): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->moduleRoot(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $path = (string)$file;
            if (\str_contains($path, '/Test/')) {
                continue;
            }
            if (\preg_match('/\.(php|xml)$/', $path) === 1) {
                $found[] = $path;
            }
        }

        \sort($found);

        return $found;
    }

    /**
     * Namespaces named in code, with comments removed first.
     *
     * Stripping is not optional: this module's own docblocks discuss
     * `Magento\ReCaptchaWebapiRest\Plugin\ValidationOverrider` at length, to explain why the plugin
     * sort order is what it is. Matching raw source would read that prose as a dependency and fail on
     * a file that is documented well.
     *
     * @return array<string, string[]> namespace => files naming it
     */
    private function referencedNamespaces(): array
    {
        $referenced = [];

        foreach ($this->shippedFiles() as $path) {
            $source = (string)\file_get_contents($path);
            $code = (string)\preg_replace(
                ['~/\*.*?\*/~s', '~^\s*//.*$~m', '~<!--.*?-->~s'],
                '',
                $source
            );

            \preg_match_all('/Magento\\\\(ReCaptcha[A-Za-z]+)/', $code, $matches);

            foreach ($matches[1] as $namespace) {
                $referenced[$namespace][] = \substr($path, \strlen($this->moduleRoot()) + 1);
            }
        }

        \ksort($referenced);

        return $referenced;
    }

    public function testNamesNoCaptchaPackageBeyondTheFrameworkItRequires(): void
    {
        $referenced = $this->referencedNamespaces();

        // Zero-yield guard: if the scan found nothing, it is broken, not clean.
        self::assertNotEmpty($referenced, 'the scan matched no namespaces at all, so it proves nothing');

        $unexpected = [];
        foreach ($referenced as $namespace => $files) {
            if (!\in_array($namespace, self::ALLOWED_NAMESPACES, true)) {
                $unexpected[] = $namespace . ' (' . \implode(', ', \array_unique($files)) . ')';
            }
        }

        self::assertSame(
            [],
            $unexpected,
            'This module may only name the captcha FRAMEWORK packages. Naming a surface package '
            . '(customer, contact, newsletter, checkout...) makes it break at runtime on a store that '
            . 'uninstalled that surface, and composer would not have warned anyone.'
        );
    }

    public function testRequiresEveryFrameworkPackageItNames(): void
    {
        $composer = \json_decode(
            (string)\file_get_contents($this->moduleRoot() . '/composer.json'),
            true
        );
        self::assertIsArray($composer);
        $require = $composer['require'] ?? [];

        foreach (\array_keys($this->referencedNamespaces()) as $namespace) {
            $package = self::PACKAGE_FOR[$namespace] ?? null;
            self::assertNotNull($package, sprintf('no package mapped for %s', $namespace));
            self::assertArrayHasKey(
                $package,
                $require,
                sprintf('%s is named in the code but %s is not required', $namespace, $package)
            );
        }
    }

    public function testTheFrontendUiPackageIsRequiredAndNotMerelySuggested(): void
    {
        // It reads like a rendering helper, so it looks optional. It is not: it owns the only
        // frontend-area preference for CaptchaTypeResolverInterface, and without it
        // getCaptchaTypeFor() falls back to a composite holding only the ADMIN resolver. Every
        // storefront surface then reports "no captcha configured" and runs unguarded, for Turnstile
        // and for Google alike, with no error anywhere. Composer would not catch it either: no
        // surface package requires it.
        $composer = \json_decode(
            (string)\file_get_contents($this->moduleRoot() . '/composer.json'),
            true
        );
        self::assertIsArray($composer);

        self::assertArrayHasKey('magento/module-re-captcha-frontend-ui', $composer['require'] ?? []);
        self::assertArrayNotHasKey(
            'magento/module-re-captcha-frontend-ui',
            $composer['suggest'] ?? [],
            'listing it as optional is a shipped instruction to disable storefront captcha'
        );
    }

    public function testSuggestsThePackageTheWebapiBridgeExtends(): void
    {
        // This one really is optional. A requirejs mixin for a module that never loads is inert, so
        // its absence costs the REST-guarded checkout steps and breaks nothing else.
        $composer = \json_decode(
            (string)\file_get_contents($this->moduleRoot() . '/composer.json'),
            true
        );
        self::assertIsArray($composer);

        $optional = ['magento/module-re-captcha-webapi-ui'];

        foreach ($optional as $package) {
            self::assertArrayHasKey($package, $composer['suggest'] ?? [], $package . ' is unlisted');
            self::assertArrayNotHasKey(
                $package,
                $composer['require'] ?? [],
                $package . ' must stay optional: requiring it would force a package onto stores that '
                . 'run no Knockout checkout at all'
            );
        }
    }
}
