<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit;

use Magento\Framework\Component\ComponentRegistrar;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the module is registered with the framework's ComponentRegistrar.
 *
 * Does NOT `require` registration.php: the unit test bootstrap already loads every
 * app/code/*\/*\/registration.php via NonComposerComponentRegistration, and
 * ComponentRegistrar::register() throws \LogicException on a second registration of the same
 * component name. Reading the registrar's resulting state is what actually proves registration
 * happened, and it can't double-register anything.
 */
class RegistrationTest extends TestCase
{
    public function testModuleIsRegisteredAtItsOwnDirectory(): void
    {
        $paths = (new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE);

        $this->assertArrayHasKey(
            'Magetu_Turnstile',
            $paths,
            'Magetu_Turnstile must be registered as a module path in the ComponentRegistrar.'
        );
        $this->assertSame(
            \str_replace('\\', '/', \dirname(__DIR__, 2)),
            $paths['Magetu_Turnstile'],
            'The registered path must be the module root, not some other directory.'
        );
    }
}
