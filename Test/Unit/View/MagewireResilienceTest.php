<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * The `wire:ignore` defence against a DOM-morphing checkout replacing the widget out from under us.
 *
 * Hyvä Checkout runs on Magewire, which reconciles a server-rendered response into the live DOM with
 * morphdom. Our container is rendered EMPTY by PHP and filled in by JavaScript, so a naive morph sees
 * an element with children against one without and reconciles the children away: the iframe and the
 * hidden token input both disappear, and nothing re-mounts them, because the init is a one-shot
 * inline script and morph-inserted scripts do not execute. The surface then fails closed on every
 * submit with nothing in the log.
 *
 * It cannot be exercised here, because Magewire is not installed in this repo and these assertions are
 * structural. They exist so the one attribute that prevents a silent, permanent outage cannot be
 * deleted casually. The recovery half of the defence lives in {@see WidgetDedupTest}.
 */
class MagewireResilienceTest extends TestCase
{
    /**
     * The template's rendered-markup skeleton, with every PHP block removed.
     *
     * Matching raw `.phtml` source is a trap this test fell into twice on its first run: a `<?= ?>`
     * echo contains `->`, whose `>` terminates an `[^>]*` attribute match early, and the docblock
     * explaining why `wire:ignore.self` is wrong contains that literal, satisfying the assertion that
     * it is absent. Stripping PHP leaves markup, which is what these assertions are actually about.
     *
     * @var string
     */
    private string $template;

    protected function setUp(): void
    {
        $this->template = (string)preg_replace(
            '~<\?(php|=).*?\?>~s',
            '',
            (string)file_get_contents(__DIR__ . '/../../../view/frontend/templates/hyva/input.phtml')
        );
    }

    public function testTheThemeContainerIsMarkedSoADomMorphSkipsIt(): void
    {
        self::assertMatchesRegularExpression(
            '/<div[^>]*\bwire:ignore\b/',
            $this->template,
            'wire:ignore on the container is what makes morphdom skip the subtree the widget lives in.'
        );
    }

    public function testTheContainerIsNotMarkedWithTheSelfModifier(): void
    {
        self::assertStringNotContainsString(
            'wire:ignore.self',
            $this->template,
            'wire:ignore.self skips the element but STILL morphs its children, which is precisely '
            . 'where the iframe and the hidden token input live. The bare directive is the correct one.'
        );
    }
}
