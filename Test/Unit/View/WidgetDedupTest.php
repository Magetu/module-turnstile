<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Guards the rule that one form gets one widget.
 *
 * Two rendering paths can reach the same form on a Hyvä storefront: the theme renders the input
 * fragment inside the form, and the native `recaptcha` block sits in the `form.additional.info`
 * container one line later, inside the same `<form>`. Nothing removes the native block, because under
 * Hyvä its markup is inert: a Knockout scope binding with no Knockout behind it. This module's
 * template swap makes that dead markup live, so the guard has to be scoped to the form.
 *
 * Two widgets means two hidden inputs sharing `g-recaptcha-response`, only one carrying a token, and
 * PHP keeps the last one: the visitor solves the visible widget and is rejected anyway.
 *
 * Structural assertions on source text. There is no JavaScript runner in this package's gate, so what
 * is pinned is that the guard keys on the form AND the instance, is actually called from render(),
 * and consults liveness: the properties whose loss is silent.
 */
class WidgetDedupTest extends TestCase
{
    use WidgetScriptTrait;

    public function testTheDedupGuardKeysOnTheFormAndTheInstanceTogether(): void
    {
        $guard = $this->functionBody('formHasInstance');

        // Both halves, asserted separately. An earlier version matched `.form === form` anywhere in
        // the body, which the `queued` closure alone satisfied, so the `rendered` comparison could
        // be re-scoped to the container and the suite stayed green with the defect back.
        foreach (['queued', 'rendered'] as $registry) {
            $closure = $this->braceBody($guard, $registry . '.some');

            self::assertMatchesRegularExpression(
                '/\.form\s*===\s*form/',
                $closure,
                sprintf('the %s check must compare the form', $registry)
            );
            self::assertMatchesRegularExpression(
                '/\.instance\s*===\s*instance/',
                $closure,
                sprintf(
                    'the %s check must also compare the instance. Without it, one form legitimately '
                    . 'holding several widgets (Magento renders one place-order widget per payment '
                    . 'method inside a single form) collapses to one, and every other method posts '
                    . 'an empty token.',
                    $registry
                )
            );
        }
    }

    public function testTheGuardIsActuallyCalledFromTheRenderPath(): void
    {
        // Nothing read render() before, so the guard could be bypassed or deleted at the call site
        // while formHasInstance stayed immaculate. Two mutations survived on exactly that.
        $render = $this->functionBody('render');

        self::assertMatchesRegularExpression(
            '/formHasInstance\(\s*form\s*,\s*name\s*\)/',
            $render,
            'render() must consult the guard, keyed on the form and the resolved instance name'
        );
    }

    public function testTheInstanceNameFallsBackToTheWidgetAction(): void
    {
        // The block and theme paths pass no explicit name; they must land on the captcha action, which
        // the two duplicate renders of one logical field share.
        $body = $this->functionBody('instanceName');

        self::assertMatchesRegularExpression('/options\.action/', $body);
    }

    /**
     * The test above pins only the FALLBACK, so deleting the explicit instance from the expression
     * left it green while re-breaking the defect the instance argument exists for. On a checkout
     * every place-order sibling shares one action, so with the instance gone they all resolve to the
     * same name, the dedup guard short-circuits every render after the first, and the remaining
     * containers stay empty. That is the failure that was measured as nine of ten empty before the
     * instance argument was added, so the primary needs pinning as firmly as the fallback.
     */
    public function testTheExplicitInstanceWinsOverTheWidgetAction(): void
    {
        $body = $this->functionBody('instanceName');

        self::assertMatchesRegularExpression(
            '/return\s+instance\s*\|\|/',
            $body,
            'instanceName() must consult the explicit instance FIRST. Falling straight through to '
            . 'options.action collapses every place-order sibling onto one name and the dedup guard '
            . 'then renders only the first of them.'
        );
        self::assertSame(
            1,
            preg_match('/\binstance\b/', $body),
            'The instance argument must actually be read in the expression, not merely accepted.'
        );
    }

    public function testTheDedupGuardRecoversWhenTheContainerWasEmptied(): void
    {
        // Without a liveness condition the guard is a one-way latch: a form that keeps its record
        // while losing the widget could never be rendered into again, turning a recoverable state
        // into a permanent outage.
        $guard = $this->functionBody('formHasInstance');

        self::assertStringContainsString(
            'widgetIsLive(',
            $guard,
            'the guard must ask whether a widget is still standing, not only whether one was asked for'
        );
    }

    public function testLivenessIsReadFromTheDomAndNotFromTheRegistry(): void
    {
        // The registry records what we asked for; a morph that empties or detaches the container
        // leaves the record intact and the widget gone.
        $live = $this->functionBody('widgetIsLive');

        self::assertStringContainsString('children.length', $live);
        self::assertStringContainsString(
            'document.contains(',
            $live,
            'a detached container keeps its children, so counting them alone still reports a widget '
            . 'that is no longer on the page'
        );
    }
}
