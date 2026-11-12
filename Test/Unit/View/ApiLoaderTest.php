<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Pins the one combination Cloudflare forbids when loading its API.
 *
 * `turnstile.ready()` exists for pages that load api.js synchronously and want to queue work before
 * it executes. Calling it when the script tag carries `async` or `defer` throws:
 *
 *   Uncaught TurnstileError: [Cloudflare Turnstile] Remove async/defer from the Turnstile api.js
 *   script tag before using turnstile.ready().
 *
 * The throw happens inside Cloudflare's script, so nothing in this module catches it: no widget is
 * rendered, no token is minted, and every guarded form on the page is rejected while its submit
 * button stays disabled. It is a silent, total outage of the guarded surface, and it is invisible to
 * PHP tests, to static review and to any check that fetches the page with curl. It cost a live
 * browser run to find, so it gets a test.
 *
 * We keep `async`/`defer` (they are the right call for a third-party script on a login page) and
 * drop `ready()`. The script tag's own `onload` fires after `window.turnstile` is populated, which is
 * exactly the signal `ready()` would have provided.
 */
class ApiLoaderTest extends TestCase
{
    /**
     * The script with comments stripped.
     *
     * Asserting over raw source is a trap this test fell into on its first run: the comment that
     * explains why `turnstile.ready()` must not be called contains the literal `turnstile.ready(`,
     * so documenting the hazard tripped the very assertion guarding against it. Anything matching
     * source text has to look at code, not prose.
     *
     * @var string
     */
    private string $code;

    protected function setUp(): void
    {
        $raw = (string)file_get_contents(__DIR__ . '/../../../view/base/web/js/turnstile-widget.js');

        $this->code = (string)preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $raw);
    }

    public function testLoadsTheApiScriptAsynchronously(): void
    {
        self::assertMatchesRegularExpression('/\.async\s*=\s*true/', $this->code);
        self::assertMatchesRegularExpression('/\.defer\s*=\s*true/', $this->code);
    }

    public function testNeverCallsTurnstileReadyBecauseTheScriptIsAsync(): void
    {
        self::assertStringNotContainsString(
            'turnstile.ready(',
            $this->code,
            'Cloudflare throws a TurnstileError when ready() is called on an async/defer script tag, '
            . 'which leaves every guarded form unsubmittable. Use the script tag onload instead.'
        );
    }

    public function testRendersFromTheScriptOnloadHandler(): void
    {
        self::assertMatchesRegularExpression(
            '/\.onload\s*=\s*\S/',
            $this->code,
            'onload is the supported signal that window.turnstile is ready when the tag is async'
        );
    }
}
