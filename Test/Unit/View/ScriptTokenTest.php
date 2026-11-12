<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * The Hyvä validation fragment. A refusal always reaches the role="alert" messages region. A submit that arrives
 * before the widget's token is held, not refused: the fragment polls the token field for a bounded time and runs
 * the component's submitForm() again once the token is there. Read from the rendered fragment with comments
 * stripped; the storefront spec drives the hold and the refusal in a browser.
 */
class ScriptTokenTest extends TestCase
{
    private const MESSAGE = 'Please complete the security check, then try again.';

    public function testAlwaysAnnouncesARefusalInTheMessagesRegion(): void
    {
        $message = preg_quote(self::MESSAGE, '/');
        self::assertMatchesRegularExpression(
            "/if \(typeof this\.setErrorMessages === 'function'\) \{\s*this\.setErrorMessages\('$message'\);\s*\}"
            . "\s*if \(typeof window\.dispatchMessages === 'function'\) \{"
            . "\s*window\.dispatchMessages\(\[\{type: 'error', text: '$message'\}\], 5000\);/",
            $this->fragment()
        );
    }

    public function testHoldsTheSubmitWhileItWaits(): void
    {
        self::assertMatchesRegularExpression(
            "/\} else if \(typeof this\.submitForm === 'function' && !\\\$form\.magetuTurnstileReleased\) \{"
            . '\s*this\.errors = 1;\s*\$form\.magetuTurnstileWaiting = true;/',
            $this->fragment()
        );
    }

    public function testRunsSubmitFormAgainOnlyOnceTheTokenFieldHasAValue(): void
    {
        $poll = $this->poll();

        self::assertStringContainsString("const field = \$form['g-recaptcha-response'];", $poll);
        self::assertMatchesRegularExpression(
            '/if \(field && field\.value\) \{\s*\$form\.magetuTurnstileWaiting = false;\s*this\.submitForm\(\);\s*\}/',
            $poll
        );
    }

    public function testRefusesOnceTheBoundedWaitRunsOut(): void
    {
        $fragment = $this->fragment();

        self::assertStringContainsString('const magetuTurnstileDeadline = Date.now() + 10000;', $fragment);
        self::assertMatchesRegularExpression(
            '/else if \(Date\.now\(\) < magetuTurnstileDeadline\) \{\s*window\.setTimeout\(magetuTurnstilePoll, 100\);'
            . '\s*\} else \{\s*\$form\.magetuTurnstileWaiting = false;\s*magetuTurnstileRefuse\(\);/',
            $this->poll()
        );
    }

    public function testASubmitWhileOneIsHeldOnlyJoinsIt(): void
    {
        self::assertMatchesRegularExpression(
            '/\s*if \(\$form\.magetuTurnstileWaiting\) \{\s*this\.errors = 1;'
            . '\s*\} else if \(magetuTurnstileToken && magetuTurnstileToken\.value\) \{/',
            $this->fragment()
        );
    }

    public function testAReleasedSubmitResetsTheWidgetAndIsNeverHeldAgain(): void
    {
        self::assertMatchesRegularExpression(
            '/else if \(magetuTurnstileToken && magetuTurnstileToken\.value\) \{\s*this\.errors = 0;'
            . '\s*\$form\.magetuTurnstileReleased = true;'
            . '\s*window\.setTimeout\(\(\) => window\.MagetuTurnstile'
            . ' && window\.MagetuTurnstile\.reset\(\$form\), 0\);'
            . "\s*\} else if \(typeof this\.submitForm === 'function' && !\\\$form\.magetuTurnstileReleased\) \{/",
            $this->fragment()
        );
    }

    public function testRefusesThroughAnArrowFunctionSoThisStaysTheComponent(): void
    {
        self::assertStringContainsString('const magetuTurnstileRefuse = () => {', $this->fragment());
    }

    public function testKeepsTheHoldStateOnTheFormNotOnTheComponent(): void
    {
        self::assertStringNotContainsString('this.magetuTurnstile', $this->fragment());
    }

    public function testAComponentWithoutSubmitFormIsRefusedAtOnce(): void
    {
        self::assertMatchesRegularExpression(
            "/\} else if \(typeof this\.submitForm === 'function' && !\\\$form\.magetuTurnstileReleased\) \{"
            . '.*\} else \{\s*this\.errors = 1;\s*magetuTurnstileRefuse\(\);\s*\}\s*$/s',
            $this->fragment()
        );
    }

    private function poll(): string
    {
        $fragment = $this->fragment();
        $start = strpos($fragment, 'const magetuTurnstilePoll = () => {');
        self::assertNotFalse($start, 'the fragment defines its poll');

        $end = strpos($fragment, 'window.setTimeout(magetuTurnstilePoll, 100);' . "\n    }", $start);

        return substr($fragment, $start, $end - $start);
    }

    private function fragment(): string
    {
        $block = new class {
            public function getData(string $key): ?object
            {
                return $key === 'widget_config' ? new class {
                    public function getTokenFieldName(): string
                    {
                        return 'g-recaptcha-response';
                    }
                } : null;
            }
        };
        $escaper = new class {
            public function escapeJs($value): string
            {
                return (string)$value;
            }
        };

        ob_start();
        try {
            (static function () use ($block, $escaper): void {
                include __DIR__ . '/../../../view/frontend/templates/hyva/script_token.phtml';
            })();
        } finally {
            $output = (string)ob_get_clean();
        }

        return trim((string)preg_replace('~^\s*//.*$~m', '', $output));
    }
}
