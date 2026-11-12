<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\View;

use Magetu\Turnstile\Plugin\SwapWidgetTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Guards the bridge into the native reCAPTCHA Knockout components.
 *
 * Not every guarded surface renders through a block, and the ones that do not (the authentication
 * popup, the inline logins on the checkout page, coupon, gift card, place order) are rendered by
 * Knockout components that call Google's API with whatever site key the resolved UI config carries.
 * Two properties keep that safe, and losing either is silent:
 *
 * 1. Every override falls back to `_super()` when the settings are not ours, so a store running
 *    Google reCAPTCHA gets its unmodified component. Losing this breaks the OTHER captcha type, which
 *    is the failure nobody testing Turnstile would see.
 * 2. The token field name is read from the settings the server sent, never named again here. A second
 *    literal that drifts puts the token under a name the server does not read, which submits
 *    unguarded rather than failing.
 *
 * Source-level assertions: this package's gate runs PHPUnit, not a JavaScript runner. What is pinned
 * is the shape whose loss is invisible, not the behaviour, which belongs to the live checks.
 */
class KnockoutBridgeTest extends TestCase
{
    use WidgetScriptTrait;

    private const BASE = 'view/frontend/web/js/knockout-mixin.js';
    private const WEBAPI = 'view/frontend/web/js/knockout-webapi-mixin.js';

    /**
     * @return array<string, array{string}>
     */
    public static function overriddenMethodProvider(): array
    {
        return [
            'the Google script loader' => ['_loadApi'],
            'the render entry point' => ['renderReCaptcha'],
            'the render itself' => ['initCaptcha'],
        ];
    }

    /**
     * @dataProvider overriddenMethodProvider
     */
    public function testEveryOverrideHandsBackToTheNativeComponentWhenTheTypeIsNotOurs(string $method): void
    {
        $body = $this->methodBody($this->jsSource(self::BASE), $method);

        // Asserting that `_super(` appears somewhere is not enough: inverting the guard keeps it in
        // the file and turns the override into a hijack of every Google component on the store.
        // Three mutations survived that weaker assertion. Pin the NEGATED test instead.
        self::assertMatchesRegularExpression(
            // Either the call inline, or the result assigned first and negated. Both are the same
            // guard. What must not pass is the guard being absent or inverted.
            '/if\s*\(\s*!\s*(widgetOptions\(this\)|parameters)\s*\)/',
            $body,
            sprintf(
                'Without the NEGATED guard, %s() takes over for EVERY captcha type on the store, '
                . 'including Google reCAPTCHA on another website of the same install. That regression '
                . 'is in the other type, so nobody testing this one would see it.',
                $method
            )
        );
        self::assertStringContainsString('_super(', $body);
    }

    public function testTheWebapiTriggerHandsBackToTheNativeComponentWhenTheTypeIsNotOurs(): void
    {
        $body = $this->methodBody($this->jsSource(self::WEBAPI), 'initParentForm');

        self::assertMatchesRegularExpression(
            '/if\s*\(\s*!this\.settings\s*\|\|\s*this\.settings\[MARKER\]\s*!==\s*true\s*\)/',
            $body,
            'Google checkout surfaces register their own trigger through this method; inverting or '
            . 'dropping the negated marker test would steal it and break placing an order on a Google '
            . 'store.'
        );
        self::assertStringContainsString('_super(', $body);
    }

    public function testBothMixinsDispatchOnTheServerSuppliedMarker(): void
    {
        $marker = SwapWidgetTemplate::UI_CONFIG_MARKER;

        foreach ([self::BASE, self::WEBAPI] as $file) {
            self::assertStringContainsString(
                $marker,
                $this->jsSource($file),
                $file . ' has no way to tell whose config it was handed. The captcha type name '
                . 'never reaches the browser; this marker is the only signal.'
            );
        }
    }

    public function testTheMarkerTestItselfIsNegatedAndNotInverted(): void
    {
        // The method guards read `if (!widgetOptions(this))`, so inverting the comparison INSIDE
        // widgetOptions() flips every one of them at once while every method still looks correct.
        // That mutation survived the first version of this file: the mixin then claims every Google
        // component on the store and hands it a Cloudflare key.
        self::assertMatchesRegularExpression(
            '/settings\[MARKER\]\s*!==\s*true/',
            $this->braceBody($this->jsSource(self::BASE), 'function widgetOptions'),
            'widgetOptions() must return null for configs that are NOT ours'
        );
    }

    public function testTheBridgePassesItsComponentIdAsTheWidgetInstance(): void
    {
        // Magento renders one place-order widget per payment method inside a single form. Without a
        // per-component instance name the widget script treats methods 2..N as duplicates of the
        // first and leaves their containers empty, and each of those still registers a token trigger
        // so choosing any of them posts an empty token and the order is refused. Reproduced live
        // on a ten-method checkout: three containers rendered, seven empty.
        self::assertMatchesRegularExpression(
            '/MagetuTurnstile\.render\(\s*element\s*,\s*parameters\s*,\s*this\.getReCaptchaId\(\)\s*\)/',
            $this->methodBody($this->jsSource(self::BASE), 'initCaptcha'),
            'the component id is the instance name, and dropping it collapses N widgets to one'
        );
    }

    public function testTheTokenFieldNameIsReadFromTheSettingsRatherThanNamedAgain(): void
    {
        self::assertStringNotContainsString(
            SwapWidgetTemplate::RESPONSE_FIELD_NAME,
            $this->jsSource(self::WEBAPI),
            'The server reads the token back under the name the UI config providers chose. A copy of '
            . 'that name here is a rename waiting to go unnoticed, and the failure is an unguarded '
            . 'submit rather than an error.'
        );

        self::assertStringContainsString(
            "'response-field-name'",
            $this->jsSource(self::WEBAPI),
            'it has to read the name from somewhere, and the settings are the only source'
        );
    }

    public function testTheBridgeIsDeclaredAsAMixinRatherThanARemap(): void
    {
        $config = $this->jsSource('view/frontend/requirejs-config.js');

        self::assertStringContainsString('mixins', $config);
        self::assertStringNotContainsString(
            'map:',
            $config,
            'Remapping the module id would take the native component away from every captcha type at '
            . 'once. A store guarding one website with Google reCAPTCHA and another with Turnstile has '
            . 'to keep both working.'
        );

        $components = ['Magento_ReCaptchaFrontendUi/js/reCaptcha', 'Magento_ReCaptchaWebapiUi/js/webapiReCaptcha'];

        foreach ($components as $component) {
            self::assertStringContainsString($component, $config);
        }
    }

    public function testTheComponentIdIsStampedOntoTheContainer(): void
    {
        // Native stamps it and other components read it back off the DOM: the place-order mixin finds
        // the active widget with
        // $('.recaptcha-checkout-place-order:visible .g-recaptcha').last().attr('id') and uses that id
        // to look up the token trigger. Drop the stamp and the lookup misses, so place order sends no
        // token at all, silently, because the registry entry still exists under the real id.
        self::assertMatchesRegularExpression(
            '/element\.id\s*=\s*this\.getReCaptchaId\(\)/',
            $this->methodBody($this->jsSource(self::BASE), 'initCaptcha'),
            'the container id is part of the contract with native, not decoration'
        );
    }

    public function testTheWebapiTriggerReadsTheTokenFromTheInputRatherThanReturningNothing(): void
    {
        // A trigger that resolves with '' is indistinguishable from a working one until an order is
        // placed: the flow proceeds, sends an empty X-ReCaptcha header and is refused server side.
        $body = $this->methodBody($this->jsSource(self::WEBAPI), 'initParentForm');

        self::assertMatchesRegularExpression(
            '/reCaptchaCallback\(\s*field\s*\?\s*field\.value\s*:/',
            $body,
            'the trigger must hand back the value it found in the hidden input'
        );
    }

    public function testTheContainerIsHandedToTheSubclassSoItCanReadATokenBack(): void
    {
        // The webapi trigger has no widget id to ask Turnstile about: the render is queued, not done,
        // when initParentForm runs. It reads the hidden input inside the container instead, so the
        // container is what the base mixin has to pass on.
        $body = $this->methodBody($this->jsSource(self::BASE), 'initCaptcha');

        // Matched against the whole argument list, not a fragment of it. An earlier version of this
        // regex used `[^)]*` before the argument, which stopped at the first `)` in the line and
        // happily matched the `element` inside `$(element).closest(...)`, so replacing the real
        // argument with null left it green.
        self::assertMatchesRegularExpression(
            '/initParentForm\(\s*form\s*,\s*element\s*\)/',
            $body,
            'the second argument is native\'s Google widget id; ours has to be the container'
        );
    }
}
