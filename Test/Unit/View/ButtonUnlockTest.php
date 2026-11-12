<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Guards the submit-button unlock.
 *
 * Magento disables the submit button on a guarded form from PHP, and does it WITHOUT looking at which
 * captcha type is configured: `ReCaptchaUi\Model\ButtonLock::isDisabled()` returns
 * `isCaptchaEnabledFor($id)`. Ten native templates across eight surfaces render `disabled="disabled"`
 * off the back of it. The only code that ever removes the attribute lives inside the native reCAPTCHA
 * JavaScript component, which this module replaces. A captcha type that renders its widget but never
 * unlocks the button leaves the form permanently unsubmittable, worse than fail-closed: the visitor
 * cannot even try.
 *
 * The assertions are structural rather than behavioural. Whether a browser really clears the
 * attribute belongs to the live checks; pinned here is that the unlock exists, targets the same
 * selector native uses, and is reached from the render path, because deleting it is silent and
 * catastrophic.
 */
class ButtonUnlockTest extends TestCase
{
    use WidgetScriptTrait;

    public function testUnlocksTheSubmitButtonUsingTheSameSelectorNativeUses(): void
    {
        self::assertStringContainsString(
            'button:not([type]), [type=submit]',
            $this->widgetScript(),
            'Native unlocks exactly this selector in ReCaptchaFrontendUi/js/reCaptcha.js. Matching it '
            . 'is what keeps every surface that relies on ButtonLock submittable.'
        );
    }

    public function testTheUnlockIsScopedToTheFormAndNotTheWholeDocument(): void
    {
        // Widening the search to the document unlocks every submit button on the page, including the
        // ones other guarded forms are deliberately holding shut. On a checkout carrying several
        // captcha surfaces that quietly unlocks the ones that have not been solved.
        self::assertMatchesRegularExpression(
            '/form\.querySelectorAll\(/',
            $this->functionBody('unlockSubmit'),
            'the unlock belongs to the form whose widget just rendered, and to nothing else'
        );
        self::assertStringNotContainsString(
            'document.querySelectorAll(',
            $this->functionBody('unlockSubmit'),
            'a document-wide unlock reaches other forms\' buttons'
        );
    }

    public function testClearsTheDisabledPropertyRatherThanOnlyTheAttribute(): void
    {
        self::assertMatchesRegularExpression(
            '/disabled\s*=\s*false/',
            $this->widgetScript(),
            'Setting the property is what a browser honours once the element is live.'
        );
    }

    public function testTheUnlockIsCalledFromTheRenderPathAndNotOnlyOnSuccess(): void
    {
        // The unlock must not sit behind a solve callback: the button is disabled from page load, and
        // an interaction-only or invisible widget may never fire a success callback at all. drain()
        // is the render path, the only place a widget id comes into existence.
        $drain = $this->functionBody('drain');

        // Zero-yield guard. If brace matching picked up the wrong span, the assertion below would
        // pass or fail for a reason that has nothing to do with the unlock.
        self::assertStringContainsString(
            'window.turnstile.render(',
            $drain,
            'drain() no longer looks like the render path; this test is reading the wrong function.'
        );

        self::assertMatchesRegularExpression(
            '/unlockSubmit\(\s*entry\.form\s*\)/',
            $drain,
            'declaring unlockSubmit() is not enough, and neither is calling it with anything. It '
            . 'searches its argument with querySelectorAll, so handing it the widget container '
            . 'instead of the form finds no submit button and leaves every guarded form permanently '
            . 'unsubmittable. Two earlier versions of this test missed that: one compared source '
            . 'positions, the next asserted only that the call appeared.'
        );
    }
}
