<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Guards the checkout coupon recovery path.
 *
 * The defect being closed: `Magento_ReCaptchaCheckoutSalesRule/js/checkout-sales-rule` refreshes its
 * captcha on both retry paths through Google's `grecaptcha` global with no captcha-type check, so on
 * a Turnstile page a rejected coupon left a spent single-use token in the field and the retry was
 * refused as a duplicate.
 *
 * Structural assertions on source text, because this package's gate runs no JavaScript. What is
 * pinned is the set of properties whose loss is silent, and every one of them has already been got
 * wrong once on this module:
 *
 *  - the mixin is REGISTERED, and against the real component id. An id that resolves to nothing is a
 *    404 on a mixin dependency, which stops the WRAPPED component loading for every captcha type,
 *    not just this one. `AmdDependencyTest` covers ids inside `define()`; a mixin TARGET lives in
 *    requirejs-config.js and is not a define() dependency, so it needs its own check.
 *  - a real Google API is never replaced outright, or a store running both types loses Google.
 *  - ours are told apart by ARGUMENT TYPE rather than an id table that can drift.
 *  - the shim is installed before `_super()`, or the vendor's throwing callbacks are registered in
 *    front of it and the first rejected coupon is still unrecoverable.
 */
class CouponRecoveryTest extends TestCase
{
    private const COMPONENT_ID = 'Magento_ReCaptchaCheckoutSalesRule/js/checkout-sales-rule';
    private const MIXIN_ID = 'Magetu_Turnstile/js/coupon-mixin';

    private function moduleRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    private function mixinSource(): string
    {
        $path = $this->moduleRoot() . '/view/frontend/web/js/coupon-mixin.js';
        self::assertFileExists($path, 'the coupon mixin must exist');

        return (string)\file_get_contents($path);
    }

    private function requireJsConfig(): string
    {
        $path = $this->moduleRoot() . '/view/frontend/requirejs-config.js';
        self::assertFileExists($path);

        return (string)\file_get_contents($path);
    }

    public function testTheMixinIsRegisteredAgainstTheCouponComponent(): void
    {
        $config = $this->requireJsConfig();

        self::assertStringContainsString(
            self::COMPONENT_ID,
            $config,
            'the coupon component must be named as a mixin target, or none of this runs at all'
        );
        self::assertStringContainsString(
            self::MIXIN_ID,
            $config,
            'the mixin must be attached to that target'
        );
    }

    /**
     * The id that broke this module before was one that did not exist. A mixin target is resolved by
     * RequireJS at load time and answered with a 404 when wrong, which stops the wrapped component
     * resolving for EVERY captcha type. Nothing in PHPUnit resolves it, so resolve it here: the
     * component id must correspond to a real file in an installed Magento module.
     */
    public function testTheCouponComponentIdResolvesToARealFile(): void
    {
        [$module, $path] = \explode('/', self::COMPONENT_ID, 2);
        // Magento_ReCaptchaCheckoutSalesRule -> module-re-captcha-checkout-sales-rule
        $name = \substr($module, \strlen('Magento_'));
        $dir = \strtolower((string)\preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $name));
        $relative = 'vendor/magento/module-' . $dir . '/view/frontend/web/' . $path . '.js';

        // Walk up rather than counting directory levels: this package sits at app/code/Magetu/Turnstile
        // while it is developed and at vendor/magetu/module-turnstile once it is installed by
        // composer, so a fixed dirname() depth is right in exactly one of the two layouts.
        $candidate = null;
        $dirCursor = $this->moduleRoot();
        for ($i = 0; $i < 8; $i++) {
            $dirCursor = \dirname($dirCursor);
            if (\is_file($dirCursor . '/' . $relative)) {
                $candidate = $dirCursor . '/' . $relative;
                break;
            }
        }

        if ($candidate === null) {
            self::markTestSkipped(
                'Magento_ReCaptchaCheckoutSalesRule is not installed in this tree, so the mixin '
                . 'target cannot be resolved here. It is a suggest-level dependency: the coupon '
                . 'surface simply does not exist without it.'
            );
        }

        self::assertFileExists(
            (string)$candidate,
            'The mixin target must resolve to a real file. A 404 on a mixin dependency stops the '
            . 'wrapped component loading for every captcha type on the store, which is how this '
            . 'module previously shipped a break far wider than the surface it was fixing.'
        );
    }

    public function testAnExistingGoogleApiIsDelegatedToAndNeverDiscarded(): void
    {
        $source = $this->mixinSource();

        self::assertMatchesRegularExpression(
            '/var\s+previous\s*=\s*window\.grecaptcha/',
            $source,
            'the shim must capture whatever grecaptcha already was'
        );
        foreach (['reset', 'execute', 'getResponse'] as $method) {
            self::assertMatchesRegularExpression(
                '/previous\s*&&\s*previous\.' . $method . '\s*\?\s*previous\.' . $method . '\(/',
                $source,
                sprintf(
                    'grecaptcha.%s must fall through to the real Google API for anything this shim '
                    . 'does not own, or a store running Google on one website and Turnstile on '
                    . 'another loses Google.',
                    $method
                )
            );
        }
    }

    public function testOursAreIdentifiedByArgumentTypeRatherThanAnIdTable(): void
    {
        $source = $this->mixinSource();

        self::assertMatchesRegularExpression(
            '/nodeType\s*===\s*1/',
            $source,
            'the reset target is decided by whether the handle is a DOM Element: native passes '
            . "Google's numeric widget handle, and this module's path passes the container element."
        );
        self::assertMatchesRegularExpression(
            '/isElement\(\s*handle\s*\)/',
            $source,
            'reset/execute must branch on that test'
        );
    }

    public function testTheShimIsInstalledBeforeTheVendorRegistersItsCallbacks(): void
    {
        $source = $this->mixinSource();

        $install = \strpos($source, 'installShim();');
        $super = \strpos($source, '_super(parentForm, widgetId)');

        self::assertNotFalse($install, 'the mixin must install the shim');
        self::assertNotFalse($super, 'the mixin must still call _super');
        self::assertLessThan(
            $super,
            $install,
            'installShim() must run BEFORE _super(). The vendor registers its throwing retry '
            . 'callbacks inside the call this wraps, so installing afterwards leaves the first '
            . 'rejected coupon exactly as broken as it was.'
        );
    }

    public function testTheMixinOnlyActsForThisModulesOwnCaptchaType(): void
    {
        $source = $this->mixinSource();

        self::assertMatchesRegularExpression(
            '/this\.settings\s*&&\s*this\.settings\[MARKER\]\s*===\s*true/',
            $source,
            'a Google-only store must reach _super() with nothing installed'
        );
    }

    /**
     * The widget script is a plain IIFE publishing `window.MagetuTurnstile`; it exports nothing
     * through define(). Requiring it would bind undefined and the reset would throw at the moment it
     * is needed most. Pin that it is not required, and that every use is guarded.
     */
    public function testTheWidgetGlobalIsUsedGuardedAndNotRequiredAsAnAmdModule(): void
    {
        $source = $this->mixinSource();

        self::assertStringNotContainsString(
            "'Magetu_Turnstile/js/turnstile-widget'",
            $source,
            'turnstile-widget.js is not an AMD module, so requiring it binds undefined'
        );
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*api\(\)\s*\)\s*\{/',
            $source,
            'the widget global must be guarded before use'
        );
    }
}
