<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\Plugin;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Validation\ValidationResult;
use Magento\ReCaptchaValidationApi\Api\Data\ValidationConfigInterface;
use Magento\ReCaptchaValidationApi\Api\ValidatorInterface;
use Magetu\Turnstile\Model\Service\VerifyToken;
use Magetu\Turnstile\Model\ValidationConfig;
use Magetu\Turnstile\Plugin\ValidateTurnstile;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The seam contract.
 *
 * The first two tests exist to go red if anyone ever rewrites dispatch to key on request state
 * instead of on the config object's type. That rewrite is the failure mode this whole design avoids:
 * the shared validation client cannot see which captcha type is being validated, so guessing from
 * the request eventually verifies one vendor's token against another vendor's endpoint and posts
 * that secret to the wrong host.
 */
class ValidateTurnstileTest extends TestCase
{
    /** @var VerifyToken&MockObject */
    private $verifyToken;

    /** @var ValidatorInterface&MockObject */
    private $subject;

    /**
     * @var ValidateTurnstile
     */
    private ValidateTurnstile $plugin;

    protected function setUp(): void
    {
        $this->verifyToken = $this->createMock(VerifyToken::class);
        $this->subject = $this->getMockBuilder(ValidatorInterface::class)->getMock();
        $this->plugin = new ValidateTurnstile($this->verifyToken);
    }

    private function ourConfig(): ValidationConfig
    {
        return new ValidationConfig('secret', '', 'msg', true, ['example.com'], 'customer_login');
    }

    /**
     * @return ValidationConfigInterface&MockObject
     */
    private function foreignConfig()
    {
        return $this->getMockBuilder(ValidationConfigInterface::class)->getMock();
    }

    public function testCallsProceedAndReturnsItsResultUntouchedWhenTheConfigIsANativeGoogleConfig(): void
    {
        $googleResult = new ValidationResult([]);
        $calledWith = null;
        $capture = function (string $token, ValidationConfigInterface $config) use (&$calledWith): void {
            $calledWith = [$token, $config];
        };
        $proceed = static function (...$args) use ($capture, $googleResult): ValidationResult {
            $capture(...$args);

            return $googleResult;
        };

        $this->verifyToken->expects(self::never())->method('execute');

        $foreign = $this->foreignConfig();
        $returned = $this->plugin->aroundIsValid($this->subject, $proceed, 'tok', $foreign);

        self::assertSame($googleResult, $returned, 'the Google path must be returned byte-for-byte');
        self::assertSame(['tok', $foreign], $calledWith, 'proceed must receive the original arguments');
    }

    public function testNeverCallsProceedWhenTheConfigIsOurTurnstileConfig(): void
    {
        $proceedCalled = false;
        $proceed = function () use (&$proceedCalled): ValidationResult {
            $proceedCalled = true;

            return new ValidationResult([]);
        };
        $this->verifyToken->method('execute')->willReturn(new ValidationResult([]));

        $this->plugin->aroundIsValid($this->subject, $proceed, 'tok', $this->ourConfig());

        self::assertFalse(
            $proceedCalled,
            'proceeding would send our token to the shared Google client, which posts our secret to '
            . 'Google siteverify.'
        );
    }

    public function testReturnsTheVerifyTokenVerdictWhenTheConfigIsOurTurnstileConfig(): void
    {
        $verdict = new ValidationResult(['turnstile-timeout-or-duplicate' => 'expired']);
        $config = $this->ourConfig();

        $this->verifyToken->expects(self::once())
            ->method('execute')
            ->with('the-token', $config)
            ->willReturn($verdict);

        $returned = $this->plugin->aroundIsValid($this->subject, static function (): ValidationResult {
            throw new \LogicException('proceed must not be called');
        }, 'the-token', $config);

        self::assertSame($verdict, $returned);
    }

    public function testTakesTheTokenOnlyFromTheIsValidArgumentAndConstructsWithNoRequestDependency(): void
    {
        $constructor = (new \ReflectionClass(ValidateTurnstile::class))->getConstructor();
        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : (string)$type;

            self::assertNotSame(RequestInterface::class, $name);
            self::assertStringNotContainsStringIgnoringCase(
                'request',
                $name,
                'The token is already an argument to isValid(); native code resolved it from the form '
                . 'param or the X-ReCaptcha header before we are called. A request dependency here is '
                . 'the endpoint-guessing bug being rebuilt.'
            );
        }
    }

    public function testDeclaresTheValidatorPluginWithASortOrderAboveTheNativeWebapiValidationOverrider(): void
    {
        $dom = new \DOMDocument();
        $dom->load(__DIR__ . '/../../../etc/di.xml');
        $xpath = new \DOMXPath($dom);

        $nodes = $xpath->query(
            '//type[@name="Magento\ReCaptchaValidationApi\Api\ValidatorInterface"]'
            . '/plugin[contains(@type, "ValidateTurnstile")]'
        );
        self::assertNotFalse($nodes);
        self::assertSame(1, $nodes->length, 'the validator plugin must be declared exactly once');

        /** @var \DOMElement $plugin */
        $plugin = $nodes->item(0);
        $sortOrder = $plugin->getAttribute('sortOrder');

        self::assertNotSame('', $sortOrder, 'an unset sortOrder is 0, which would wrap the native overrider');
        self::assertGreaterThan(
            0,
            (int)$sortOrder,
            'Magento\ReCaptchaWebapiRest\Plugin\ValidationOverrider is declared with no sortOrder (0) and a '
            . 'lower sortOrder wraps a higher one. Ours must sit INSIDE it so integration-token REST '
            . 'calls stay exempt.'
        );
    }
}
