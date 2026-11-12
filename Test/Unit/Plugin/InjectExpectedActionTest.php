<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\Plugin;

use Magento\ReCaptchaUi\Model\ValidationConfigResolverInterface;
use Magento\ReCaptchaValidationApi\Api\Data\ValidationConfigInterface;
use Magetu\Turnstile\Model\ValidationConfig;
use Magetu\Turnstile\Plugin\InjectExpectedAction;
use PHPUnit\Framework\TestCase;

/**
 * Carries the captcha id from the resolver to the config, so VerifyToken can reject a token minted
 * for a different form. Both enforcement paths resolve config through this one method, so this is
 * the only place the injection needs to happen.
 */
class InjectExpectedActionTest extends TestCase
{
    /**
     * @var InjectExpectedAction
     */
    private InjectExpectedAction $plugin;

    protected function setUp(): void
    {
        $this->plugin = new InjectExpectedAction();
    }

    /**
     * @return ValidationConfigResolverInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function subject()
    {
        return $this->getMockBuilder(ValidationConfigResolverInterface::class)->getMock();
    }

    public function testInjectsTheCaptchaIdAsTheExpectedActionIntoOurConfig(): void
    {
        $config = new ValidationConfig('secret', '', 'msg', true, ['example.com']);
        self::assertSame('', $config->getExpectedAction(), 'precondition: no action before the plugin runs');

        $returned = $this->plugin->afterGet($this->subject(), $config, 'customer_login');

        self::assertSame('customer_login', $config->getExpectedAction());
        self::assertSame($config, $returned);
    }

    public function testPassesAForeignValidationConfigThroughUnmodified(): void
    {
        $foreign = $this->getMockBuilder(ValidationConfigInterface::class)->getMock();

        $returned = $this->plugin->afterGet($this->subject(), $foreign, 'customer_login');

        self::assertSame($foreign, $returned, 'a Google config must come back untouched');
    }

    public function testOverwritesAnyPreviouslySetActionSoAReusedInstanceCannotLeakOne(): void
    {
        $config = new ValidationConfig('secret', '', 'msg', true, ['example.com'], 'newsletter');

        $this->plugin->afterGet($this->subject(), $config, 'customer_login');

        self::assertSame('customer_login', $config->getExpectedAction());
    }

    public function testDeclaresTheInjectorPluginOnTheResolverInterface(): void
    {
        $dom = new \DOMDocument();
        $dom->load(__DIR__ . '/../../../etc/di.xml');
        $xpath = new \DOMXPath($dom);

        $nodes = $xpath->query(
            '//type[@name="Magento\ReCaptchaUi\Model\ValidationConfigResolverInterface"]'
            . '/plugin[contains(@type, "InjectExpectedAction")]'
        );
        self::assertNotFalse($nodes);
        self::assertSame(1, $nodes->length);
    }
}
