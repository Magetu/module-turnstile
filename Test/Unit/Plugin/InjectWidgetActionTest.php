<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\Plugin;

use DOMDocument;
use DOMXPath;
use Magento\ReCaptchaUi\Model\UiConfigResolverInterface;
use Magetu\Turnstile\Plugin\InjectWidgetAction;
use Magetu\Turnstile\Plugin\SwapWidgetTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Guards the render half of the action check.
 *
 * The widget mints its token for a named action and the verification service reports that action
 * back; the module compares it against the captcha id the surface asked for. Without an action on the
 * render side there is nothing to report, and a token minted on one guarded form replays on every
 * other guarded form of the same store.
 *
 * A UI config provider cannot do this itself: `UiConfigProviderInterface::get()` takes no arguments,
 * so it never learns which surface is being rendered. The resolver is the first place the key and the
 * config exist together.
 */
class InjectWidgetActionTest extends TestCase
{
    /**
     * @return UiConfigResolverInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function resolver()
    {
        return $this->getMockBuilder(UiConfigResolverInterface::class)->getMock();
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function ourConfig(array $extra = []): array
    {
        return [
            'rendering' => ['sitekey' => '1x00000000000000000000AA'],
            SwapWidgetTemplate::UI_CONFIG_MARKER => true,
        ] + $extra;
    }

    public function testStampsTheCaptchaIdOntoTheRenderingParametersAsTheAction(): void
    {
        $result = (new InjectWidgetAction())->afterGet($this->resolver(), $this->ourConfig(), 'customer_login');

        self::assertSame(
            'customer_login',
            $result['rendering']['action'],
            'The verification service compares the reported action against this exact captcha id.'
        );
    }

    public function testUsesTheSameIdTheValidationSideExpects(): void
    {
        // The two halves have to name the same thing. Both take it from the key the surface asked its
        // resolver for, which is what makes them impossible to drift apart; this pins that they do.
        $key = 'product_review';

        $result = (new InjectWidgetAction())->afterGet($this->resolver(), $this->ourConfig(), $key);

        self::assertSame($key, $result['rendering']['action']);
    }

    public function testLeavesEveryOtherCaptchaTypeUntouched(): void
    {
        $foreign = ['rendering' => ['sitekey' => 'google-key', 'size' => 'invisible'], 'invisible' => true];

        $result = (new InjectWidgetAction())->afterGet($this->resolver(), $foreign, 'customer_login');

        self::assertSame(
            $foreign,
            $result,
            'An action parameter Google never asked for would travel into grecaptcha.render() on a '
            . 'store that runs both types.'
        );
    }

    public function testSurvivesAConfigThatCarriesNoRenderingArray(): void
    {
        $result = (new InjectWidgetAction())->afterGet(
            $this->resolver(),
            [SwapWidgetTemplate::UI_CONFIG_MARKER => true],
            'contact'
        );

        self::assertSame(['action' => 'contact'], $result['rendering']);
    }

    public function testIsDeclaredOnTheUiConfigResolverInterface(): void
    {
        $dom = new DOMDocument();
        self::assertTrue($dom->load(\dirname(__DIR__, 3) . '/etc/di.xml'));
        $xpath = new DOMXPath($dom);

        $nodes = $xpath->query(
            '/config/type[@name="Magento\ReCaptchaUi\Model\UiConfigResolverInterface"]'
            . '/plugin[@type="Magetu\Turnstile\Plugin\InjectWidgetAction"]'
        );

        self::assertNotFalse($nodes);
        self::assertSame(
            1,
            $nodes->length,
            'Declared on the interface, not on one implementation: the resolver is swapped per area '
            . 'by DI preference, so a plugin on a concrete class would cover only one of them.'
        );
    }
}
