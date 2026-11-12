<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\Model;

use Magento\ReCaptchaValidationApi\Api\Data\ValidationConfigInterface;
use Magetu\Turnstile\Model\ValidationConfig;
use PHPUnit\Framework\TestCase;

/**
 * Pins Shared contract 2 (_plan.md): the native ValidationConfigInterface members plus our own typed
 * additions, and that expectedAction is the one field settable after construction.
 */
class ValidationConfigTest extends TestCase
{
    private function makeConfig(string $expectedAction = ''): ValidationConfig
    {
        return new ValidationConfig(
            privateKey: 'secret-123',
            remoteIp: '203.0.113.5',
            validationFailureMessage: 'You failed the security check.',
            validateAction: true,
            allowedHostnames: ['example.test'],
            expectedAction: $expectedAction
        );
    }

    /**
     * it carries expectedAction, validateAction, allowedHostnames and sendRemoteIp as typed fields
     *
     * "sendRemoteIp" surfaces here as getRemoteIp(): string, since the provider (not this class)
     * decides whether the send_remote_ip toggle yields an actual IP or ''.
     */
    public function testCarriesExpectedActionValidateActionAllowedHostnamesAndRemoteIpAsTypedFields(): void
    {
        $config = $this->makeConfig('customer_login');

        $this->assertSame('customer_login', $config->getExpectedAction());
        $this->assertIsString($config->getExpectedAction());

        $this->assertTrue($config->getValidateAction());
        $this->assertIsBool($config->getValidateAction());

        $this->assertSame(['example.test'], $config->getAllowedHostnames());
        $this->assertIsArray($config->getAllowedHostnames());

        $this->assertSame('203.0.113.5', $config->getRemoteIp());
        $this->assertIsString($config->getRemoteIp());

        $this->assertSame('secret-123', $config->getPrivateKey());
        $this->assertSame('You failed the security check.', $config->getValidationFailureMessage());
        $this->assertNull($config->getExtensionAttributes());
    }

    /**
     * it allows expectedAction to be set after construction and returns the interface type
     */
    public function testAllowsExpectedActionToBeSetAfterConstructionAndReturnsTheInterfaceType(): void
    {
        $config = $this->makeConfig();
        $this->assertSame('', $config->getExpectedAction());

        $result = $config->setExpectedAction('newsletter');

        $this->assertSame('newsletter', $config->getExpectedAction());
        $this->assertInstanceOf(ValidationConfigInterface::class, $result);
        $this->assertSame($config, $result, 'setExpectedAction must mutate and return the same instance.');
    }
}
