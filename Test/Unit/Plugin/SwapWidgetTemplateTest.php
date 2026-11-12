<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\Plugin;

use DOMDocument;
use DOMXPath;
use Magento\ReCaptchaUi\Block\ReCaptcha;
use Magento\ReCaptchaUi\Model\CaptchaTypeResolverInterface;
use Magetu\Turnstile\Plugin\SwapWidgetTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Pins the template swap and the widget init payload it hands the template.
 *
 * Only the payload's SHAPE is testable here: everything about how the browser then behaves (the
 * API script loading once for two widgets, the widget-id registry, a reset minting a fresh token)
 * needs a real browser and is verified in the live-check task instead. A PHPUnit assertion that a
 * JavaScript file contains a given substring can only fail when someone rewords it, so none is
 * written; the one JavaScript fact asserted below is that the file exists, because the template
 * references it by path and a missing file is a 404 rather than a reworded string.
 */
class SwapWidgetTemplateTest extends TestCase
{
    private const NATIVE_TEMPLATE = 'Magento_ReCaptchaFrontendUi::recaptcha.phtml';

    /**
     * A fully populated rendering config, using the key names the UI config providers emit.
     *
     * @return array<string, array<string, string>>
     */
    private function uiConfig(): array
    {
        return [
            'rendering' => [
                'sitekey' => '1x00000000000000000000AA',
                'size' => 'compact',
                'theme' => 'dark',
                'appearance' => 'interaction-only',
                'language' => 'fr',
                'response-field-name' => 'g-recaptcha-response',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $uiConfig
     * @return ReCaptcha&\PHPUnit\Framework\MockObject\MockObject
     */
    private function block(string $captchaId, array $uiConfig = [])
    {
        $block = $this->getMockBuilder(ReCaptcha::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCaptchaUiConfig'])
            ->getMock();
        $block->method('getCaptchaUiConfig')->willReturn($uiConfig);
        $block->setData('recaptcha_for', $captchaId);

        return $block;
    }

    /**
     * @return CaptchaTypeResolverInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function resolver(?string $resolvedType)
    {
        $resolver = $this->getMockBuilder(CaptchaTypeResolverInterface::class)->getMock();
        $resolver->method('getCaptchaTypeFor')->willReturn($resolvedType);

        return $resolver;
    }

    /**
     * @param array<string, mixed> $uiConfig
     * @return array<string, string>
     */
    private function widgetOptionsFor(string $captchaId, array $uiConfig): array
    {
        $block = $this->block($captchaId, $uiConfig);
        (new SwapWidgetTemplate($this->resolver(SwapWidgetTemplate::CAPTCHA_TYPE)))->beforeToHtml($block);

        return (array)$block->getData(SwapWidgetTemplate::WIDGET_OPTIONS_KEY);
    }

    public function testSwapsTheBlockTemplateWhenTheResolvedCaptchaTypeIsMagetuTurnstile(): void
    {
        $block = $this->block('customer_login', $this->uiConfig());
        $block->setTemplate(self::NATIVE_TEMPLATE);

        (new SwapWidgetTemplate($this->resolver('magetu_turnstile')))->beforeToHtml($block);

        self::assertSame(
            'Magetu_Turnstile::turnstile.phtml',
            $block->getTemplate(),
            'Our type must render through our own template, not the Google-bound native one.'
        );
    }

    public function testLeavesTheBlockTemplateUntouchedForEveryOtherCaptchaType(): void
    {
        $block = $this->block('customer_login', $this->uiConfig());
        $block->setTemplate(self::NATIVE_TEMPLATE);

        (new SwapWidgetTemplate($this->resolver('recaptcha_v3')))->beforeToHtml($block);

        self::assertSame(self::NATIVE_TEMPLATE, $block->getTemplate());
        self::assertNull(
            $block->getData(SwapWidgetTemplate::WIDGET_OPTIONS_KEY),
            'A foreign type must not be handed our widget options either.'
        );
    }

    public function testLeavesTheBlockTemplateUntouchedWhenTheResolverReturnsNull(): void
    {
        $block = $this->block('customer_login', $this->uiConfig());
        $block->setTemplate(self::NATIVE_TEMPLATE);

        (new SwapWidgetTemplate($this->resolver(null)))->beforeToHtml($block);

        self::assertSame(self::NATIVE_TEMPLATE, $block->getTemplate());
        self::assertNull($block->getData(SwapWidgetTemplate::WIDGET_OPTIONS_KEY));
    }

    public function testDoesNotConsultTheResolverWhenTheBlockCarriesNoCaptchaId(): void
    {
        $block = $this->block('');
        $resolver = $this->getMockBuilder(CaptchaTypeResolverInterface::class)->getMock();
        $resolver->expects(self::never())->method('getCaptchaTypeFor');

        (new SwapWidgetTemplate($resolver))->beforeToHtml($block);

        self::assertNull($block->getTemplate());
    }

    public function testBuildsTheWidgetInitPayloadFromThePinnedUiConfigKeyNames(): void
    {
        $options = $this->widgetOptionsFor('customer_login', $this->uiConfig());

        self::assertSame('1x00000000000000000000AA', $options['sitekey']);
        self::assertSame('compact', $options['size']);
        self::assertSame('dark', $options['theme']);
        self::assertSame('interaction-only', $options['appearance']);
        self::assertSame(
            'fr',
            $options['language'],
            'The UI config providers emit widget parameter names; nothing is translated on the way.'
        );
    }

    public function testCarriesTheResponseFieldNameStraightThroughInsteadOfNamingItAgain(): void
    {
        // The name the token input gets and the name the server reads it back under have to be one
        // value. The UI config providers own it; a second literal here is how the two would drift,
        // and the drift is silent: a token under an unread name submits unguarded rather than
        // failing. Feeding a name this class would never have chosen is what proves it does not
        // choose one.
        $uiConfig = $this->uiConfig();
        $uiConfig['rendering']['response-field-name'] = 'x-not-a-name-this-class-would-pick';

        $options = $this->widgetOptionsFor('customer_login', $uiConfig);

        self::assertSame('x-not-a-name-this-class-would-pick', $options['response-field-name']);
    }

    public function testSetsTheWidgetActionToTheCaptchaIdTakenFromRecaptchaFor(): void
    {
        $options = $this->widgetOptionsFor('newsletter', $this->uiConfig());

        self::assertSame(
            'newsletter',
            $options['action'],
            'The action is compared byte-for-byte against the captcha id server side.'
        );
    }

    public function testOmitsBlankOptionalRenderingValuesInsteadOfSendingEmptyStrings(): void
    {
        $options = $this->widgetOptionsFor('customer_login', [
            'rendering' => [
                'sitekey' => '1x00000000000000000000AA',
                'size' => '',
                'theme' => '',
                'appearance' => '',
                'language' => '',
            ],
        ]);

        self::assertSame(
            [
                'sitekey' => '1x00000000000000000000AA',
                'action' => 'customer_login',
            ],
            $options,
            'A blank value must fall through to the widget default rather than be sent as "".'
        );
    }

    public function testDeclaresTheTemplateSwapPluginOnTheNativeReCaptchaBlock(): void
    {
        $dom = new DOMDocument();
        self::assertTrue($dom->load(\dirname(__DIR__, 3) . '/etc/di.xml'));
        $xpath = new DOMXPath($dom);

        $nodes = $xpath->query(
            '//type[@name="Magento\ReCaptchaUi\Block\ReCaptcha"]/plugin[contains(@type, "SwapWidgetTemplate")]'
        );
        self::assertNotFalse($nodes);
        self::assertSame(
            1,
            $nodes->length,
            'One global declaration covers the storefront and both admin pages.'
        );
    }

    public function testShipsTheSwappedTemplateForBothAreasPlusTheSharedWidgetScript(): void
    {
        $root = \dirname(__DIR__, 3);

        self::assertFileExists($root . '/view/frontend/templates/turnstile.phtml');
        self::assertFileExists(
            $root . '/view/adminhtml/templates/turnstile.phtml',
            'The admin login and forgot-password pages render through the same block, so the type is '
            . 'selectable for them whether or not a template exists. Without this file that selection '
            . 'renders a captcha nobody can solve, i.e. an admin lockout.'
        );
        self::assertFileExists(
            $root . '/view/base/web/js/turnstile-widget.js',
            'Both templates reference this path; a missing file is a 404 and a widget that never renders.'
        );
    }
}
