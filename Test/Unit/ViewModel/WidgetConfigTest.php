<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\ViewModel;

use Magento\Framework\DataObject;
use Magento\ReCaptchaUi\Block\ReCaptcha;
use Magento\ReCaptchaUi\Model\CaptchaTypeResolverInterface;
use Magento\ReCaptchaUi\Model\UiConfigResolverInterface;
use Magetu\Turnstile\Plugin\SwapWidgetTemplate;
use Magetu\Turnstile\ViewModel\WidgetConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the ViewModel the theme-side blocks render from.
 *
 * Two things are worth stating about what is NOT here. The browser half (the token-present guard
 * raising a message, a reset minting a live token on an in-place retry, and inline scripts passing an
 * enforced CSP) needs a real browser and belongs to the live-check task. And no assertion is written
 * over template source text, because such an assertion can only fail when someone rewords a line.
 */
class WidgetConfigTest extends TestCase
{
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
     * @return UiConfigResolverInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function resolver(string $expectedFormId, array $uiConfig)
    {
        $resolver = $this->getMockBuilder(UiConfigResolverInterface::class)->getMock();
        $resolver->expects(self::once())
            ->method('get')
            ->with($expectedFormId)
            ->willReturn($uiConfig);

        return $resolver;
    }

    /**
     * Stands in for the block the theme hands us, which carries form_id and input_element_id.
     *
     * @return DataObject
     */
    private function block(string $formId, string $inputElementId = ''): DataObject
    {
        return new DataObject(['form_id' => $formId, 'input_element_id' => $inputElementId]);
    }

    public function testSourcesTheRenderConfigFromTheUiConfigResolverKeyedByTheFormId(): void
    {
        $subject = new WidgetConfig($this->resolver('customer_login', $this->uiConfig()));

        $options = $subject->getWidgetOptions($this->block('customer_login'));

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

    public function testDependsOnTheUiConfigResolverAloneSoNoConfigPathIsMappedTwice(): void
    {
        $constructor = (new ReflectionClass(WidgetConfig::class))->getConstructor();
        self::assertNotNull($constructor);

        $types = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $types[] = $type instanceof \ReflectionNamedType ? $type->getName() : (string)$type;
        }

        self::assertSame(
            [UiConfigResolverInterface::class],
            $types,
            'Reading the config paths a second time here would duplicate the path/scope/default '
            . 'mapping the UI config providers already own, and nothing would fail when the two drift.'
        );
    }

    public function testTakesTheWidgetActionFromTheFormIdTheThemeSetsOnTheBlock(): void
    {
        $subject = new WidgetConfig($this->resolver('newsletter', $this->uiConfig()));

        $options = $subject->getWidgetOptions($this->block('newsletter'));

        self::assertSame(
            'newsletter',
            $options['action'],
            'The action is compared byte-for-byte against the captcha id server side.'
        );
    }

    public function testNamesTheTokenInputTheParameterNameTheFormPostPathAlreadyReads(): void
    {
        $subject = new WidgetConfig($this->resolver('contact', $this->uiConfig()));

        $options = $subject->getWidgetOptions($this->block('contact'));

        self::assertSame(SwapWidgetTemplate::RESPONSE_FIELD_NAME, $options['response-field-name']);
        self::assertSame(
            $options['response-field-name'],
            (new WidgetConfig($this->getMockBuilder(UiConfigResolverInterface::class)->getMock()))
                ->getTokenFieldName(),
            'The submit-time guard reads the field the widget was told to create; one source, not two.'
        );

        // And the value is carried, not re-chosen: an unrecognisable name has to survive the trip.
        $custom = $this->resolver('contact', ['rendering' => ['response-field-name' => 'x-carried']]);

        self::assertSame(
            'x-carried',
            (new WidgetConfig($custom))->getWidgetOptions($this->block('contact'))['response-field-name']
        );
    }

    public function testOmitsBlankOptionalRenderingValuesInsteadOfSendingEmptyStrings(): void
    {
        $subject = new WidgetConfig($this->resolver('customer_login', [
            'rendering' => [
                'sitekey' => '1x00000000000000000000AA',
                'size' => '',
                'theme' => '',
                'appearance' => '',
                'language' => '',
            ],
        ]));

        self::assertSame(
            [
                'sitekey' => '1x00000000000000000000AA',
                'action' => 'customer_login',
            ],
            $subject->getWidgetOptions($this->block('customer_login')),
            'A blank value must fall through to the widget default rather than be sent as "".'
        );
    }

    /**
     * The seam this test defends is silent: two rendering paths translate one UI config array into
     * one widget parameter map, in two files, and a drift on either side breaks only the other
     * theme, with no error anywhere. Repointing either side turns this red.
     */
    public function testProducesTheSameWidgetParameterMapAsTheBlockRenderingPath(): void
    {
        $captchaId = 'customer_create';

        $viaViewModel = (new WidgetConfig($this->resolver($captchaId, $this->uiConfig())))
            ->getWidgetOptions($this->block($captchaId));

        $captchaBlock = $this->getMockBuilder(ReCaptcha::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCaptchaUiConfig'])
            ->getMock();
        $captchaBlock->method('getCaptchaUiConfig')->willReturn($this->uiConfig());
        $captchaBlock->setData('recaptcha_for', $captchaId);

        $typeResolver = $this->getMockBuilder(CaptchaTypeResolverInterface::class)->getMock();
        $typeResolver->method('getCaptchaTypeFor')->willReturn(SwapWidgetTemplate::CAPTCHA_TYPE);
        (new SwapWidgetTemplate($typeResolver))->beforeToHtml($captchaBlock);

        self::assertSame(
            (array)$captchaBlock->getData(SwapWidgetTemplate::WIDGET_OPTIONS_KEY),
            $viaViewModel,
            'Both rendering paths must hand the widget the same parameters for the same config.'
        );
    }

    public function testDerivesAContainerIdThatIsUniquePerRenderedInstance(): void
    {
        $resolver = $this->getMockBuilder(UiConfigResolverInterface::class)->getMock();
        $subject = new WidgetConfig($resolver);

        $onPage = $subject->getContainerId($this->block('customer_login'));
        $inPopup = $subject->getContainerId($this->block('customer_login', 'auth-popup'));

        self::assertNotSame(
            $onPage,
            $inPopup,
            'One captcha id can render twice on a page; the theme disambiguates with input_element_id.'
        );
        self::assertMatchesRegularExpression('/^[A-Za-z][A-Za-z0-9_-]*$/', $onPage);
        self::assertMatchesRegularExpression('/^[A-Za-z][A-Za-z0-9_-]*$/', $inPopup);
    }

    public function testStripsCharactersThatWouldBreakTheContainerSelector(): void
    {
        $resolver = $this->getMockBuilder(UiConfigResolverInterface::class)->getMock();

        $containerId = (new WidgetConfig($resolver))->getContainerId($this->block('a."b c', ''));

        self::assertMatchesRegularExpression(
            '/^[A-Za-z][A-Za-z0-9_-]*$/',
            $containerId,
            'The id is interpolated into a CSS selector, so anything outside the safe set is dropped.'
        );
    }
}
