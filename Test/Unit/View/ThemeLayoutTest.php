<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\View;

use DOMDocument;
use DOMXPath;
use Magetu\Turnstile\ViewModel\WidgetConfig;
use PHPUnit\Framework\TestCase;

/**
 * Pins the layout declarations the theme-side rendering path depends on.
 *
 * The theme that ships these blocks dispatches to them by NAME: it looks up
 * `recaptcha_input_field_<type>` and `recaptcha_validation_<type>` and renders whichever it finds.
 * Nothing throws when a name is wrong; the surface simply renders no widget and every submit is
 * rejected server side. So the names, the layout handle they are declared on, and the container the
 * loader sits in are each asserted here rather than left to a live check.
 */
class ThemeLayoutTest extends TestCase
{
    private const INPUT_BLOCK = 'recaptcha_input_field_magetu_turnstile';
    private const VALIDATION_BLOCK = 'recaptcha_validation_magetu_turnstile';
    private const HANDLE_FILE = 'hyva_default.xml';

    private function moduleRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    private function xpath(string $file): DOMXPath
    {
        $dom = new DOMDocument();
        self::assertTrue($dom->load($file), 'Layout file must be well-formed XML: ' . $file);

        return new DOMXPath($dom);
    }

    private function handleXpath(): DOMXPath
    {
        $file = $this->moduleRoot() . '/view/frontend/layout/' . self::HANDLE_FILE;
        self::assertFileExists($file);

        return $this->xpath($file);
    }

    /**
     * @param string $blockName
     * @param string $template
     * @return void
     */
    private function assertConventionBlock(string $blockName, string $template): void
    {
        $nodes = $this->handleXpath()->query('//block[@name="' . $blockName . '"]');
        self::assertNotFalse($nodes);
        self::assertSame(1, $nodes->length, 'Exactly one block may claim the name ' . $blockName . '.');

        $block = $nodes->item(0);
        self::assertNotNull($block);
        self::assertSame(
            $template,
            $block->attributes->getNamedItem('template')->nodeValue,
            'The block is dispatched to by name, so it has to carry the template itself.'
        );
        self::assertSame(
            WidgetConfig::class,
            $this->handleXpath()->query(
                '//block[@name="' . $blockName . '"]/arguments/argument[@name="widget_config"]'
            )->item(0)->nodeValue,
            'Without this argument the template has no way to reach the render config.'
        );
    }

    public function testDeclaresTheInputFieldBlockUnderTheConventionName(): void
    {
        $this->assertConventionBlock(self::INPUT_BLOCK, 'Magetu_Turnstile::hyva/input.phtml');
    }

    public function testDeclaresTheValidationBlockUnderTheConventionName(): void
    {
        $this->assertConventionBlock(self::VALIDATION_BLOCK, 'Magetu_Turnstile::hyva/script_token.phtml');
    }

    public function testDeclaresTheConventionBlocksOnOneLayoutHandleOnly(): void
    {
        $declaring = [];
        foreach ((array)\glob($this->moduleRoot() . '/view/*/layout/*.xml') as $file) {
            $nodes = $this->xpath((string)$file)->query(
                '//block[@name="' . self::INPUT_BLOCK . '" or @name="' . self::VALIDATION_BLOCK . '"]'
            );
            if ($nodes !== false && $nodes->length > 0) {
                $declaring[] = \basename((string)$file);
            }
        }

        self::assertSame(
            [self::HANDLE_FILE],
            $declaring,
            'This handle only loads when a theme that uses the block-name convention is active. '
            . 'Declaring the same blocks on the plain handle would add them to every other theme too, '
            . 'where nothing looks them up and the rendering path is the block plugin instead.'
        );
    }

    public function testDeclaresTheApiLoaderBlockInTheBeforeBodyEndContainer(): void
    {
        $nodes = $this->handleXpath()->query(
            '//referenceContainer[@name="before.body.end"]'
            . '/block[@template="Magetu_Turnstile::hyva/script_loader.phtml"]'
        );

        self::assertNotFalse($nodes);
        self::assertSame(
            1,
            $nodes->length,
            'One loader per page, at the end of the body, so the widget script is defined by the time '
            . 'the per-widget init runs and no second copy is ever requested.'
        );
    }

    public function testShipsEveryTemplateTheHandleReferences(): void
    {
        $root = $this->moduleRoot();
        $templates = $this->handleXpath()->query('//block[@template]');
        self::assertNotFalse($templates);
        self::assertGreaterThan(0, $templates->length);

        foreach ($templates as $block) {
            $template = (string)$block->attributes->getNamedItem('template')->nodeValue;
            self::assertStringStartsWith('Magetu_Turnstile::', $template);
            self::assertFileExists(
                $root . '/view/frontend/templates/' . \substr($template, \strlen('Magetu_Turnstile::')),
                'A layout reference to a missing template is a fatal on every page carrying it.'
            );
        }
    }

    public function testShipsNoSecondCopyOfTheWidgetScript(): void
    {
        $found = [];
        foreach ((array)\glob($this->moduleRoot() . '/view/*/web/js/*.js') as $file) {
            $found[] = \basename((string)$file);
        }
        \sort($found);

        self::assertSame(
            ['turnstile-widget.js'],
            \array_values(\array_filter($found, static fn (string $n): bool => $n === 'turnstile-widget.js')),
            'Every rendering path loads the one script, which owns the API loading and the widget '
            . 'registry; a second copy in another area would mean a second registry and a reset that '
            . 'misses. Counting matters here, because de-duplicating the names first would hide exactly the '
            . 'duplicate this is looking for.'
        );

        self::assertSame(
            [
                'coupon-mixin.js',
                'knockout-mixin.js',
                'knockout-webapi-mixin.js',
                'turnstile-widget.js',
            ],
            $found,
            'Declared inventory: a new script under view/*/web/js is a new thing loaded into a '
            . 'storefront, so it belongs in a review rather than arriving quietly.'
        );
    }
}
