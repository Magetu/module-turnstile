<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\View;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * The Hyvä input template.
 *
 * The footer newsletter form is on every page, so its widget must not load the Turnstile API on page
 * view: the template renders it only on first use of the form, for the captcha ids the layout lists in
 * `lazy_form_ids`. And the container reserves the visible widget's whole box, so the widget neither pushes
 * the page down nor wraps a flex sibling when it draws.
 */
class InputTemplateTest extends TestCase
{
    public function testTheLayoutListsOnlyTheNewsletterFormAsLazy(): void
    {
        $dom = new DOMDocument();
        self::assertTrue($dom->load(__DIR__ . '/../../../view/frontend/layout/hyva_default.xml'));
        $items = (new DOMXPath($dom))->query(
            '//block[@name="recaptcha_input_field_magetu_turnstile"]'
            . '/arguments/argument[@name="lazy_form_ids"]/item'
        );

        $values = [];
        foreach ($items as $item) {
            $values[] = trim($item->textContent);
        }
        self::assertSame(['newsletter'], $values);
    }

    public function testOnlyAListedFormWaitsForFirstUse(): void
    {
        self::assertStringContainsString('var init = startOnFirstUse;', $this->render('newsletter'));
        self::assertStringContainsString('var init = start;', $this->render('contact'));
    }

    public function testASubmitAloneStartsALazyWidget(): void
    {
        $html = $this->render('newsletter');

        self::assertMatchesRegularExpression("/var events = \[[^\]]*'submit'[^\]]*\];/", $html);
        self::assertMatchesRegularExpression(
            '/go = function \(\) \{\s*events\.forEach\(function \(name\) \{'
            . '\s*form\.removeEventListener\(name, go, true\);\s*\}\);\s*start\(\);\s*\};/',
            $html
        );
    }

    public function testReservesTheVisibleWidgetBox(): void
    {
        $normal = 'style="min-height: 65px; min-width: min(300px, 100%)"';

        self::assertStringContainsString($normal, $this->render('contact'));
        self::assertStringContainsString($normal, $this->render('newsletter'));
        self::assertStringContainsString($normal, $this->render('contact', ['size' => 'flexible']));
        self::assertStringContainsString($normal, $this->render('contact', ['size' => 'normal']));
        self::assertStringContainsString(
            'style="min-height: 140px; min-width: min(150px, 100%)"',
            $this->render('contact', ['size' => 'compact'])
        );
        self::assertStringContainsString($normal, $this->render('contact', ['appearance' => 'execute']));
    }

    public function testReservesNothingWhileTheWidgetStaysHidden(): void
    {
        foreach ([[], ['size' => 'compact']] as $size) {
            $html = $this->render('contact', ['appearance' => 'interaction-only'] + $size);

            self::assertStringNotContainsString('min-height', $html);
            self::assertStringNotContainsString('min-width', $html);
        }
    }

    private function render(string $formId, array $options = []): string
    {
        $widgetConfig = new class ($options) {
            public function __construct(private array $options)
            {
            }

            public function getContainerId($block): string
            {
                return 'magetu-turnstile-' . $block->getData('form_id');
            }

            public function getWidgetOptions($block): array
            {
                return $this->options + ['action' => $block->getData('form_id')];
            }
        };
        $block = new \Magento\Framework\DataObject([
            'form_id' => $formId,
            'lazy_form_ids' => ['newsletter' => 'newsletter'],
            'widget_config' => $widgetConfig,
        ]);
        $escaper = new class {
            public function escapeJs(string $value): string
            {
                return $value;
            }

            public function escapeHtmlAttr(string $value): string
            {
                return $value;
            }
        };
        $hyvaCsp = new class {
            public function registerInlineScript(): void
            {
            }
        };

        ob_start();
        try {
            (static function () use ($block, $escaper, $hyvaCsp): void {
                include __DIR__ . '/../../../view/frontend/templates/hyva/input.phtml';
            })();
        } finally {
            $html = (string)ob_get_clean();
        }

        return $html;
    }
}
