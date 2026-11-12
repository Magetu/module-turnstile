<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Asserts etc/module.xml declares the module name. Etc\ConfigXmlTest covers its <sequence>.
 */
class ModuleXmlTest extends TestCase
{
    private function loadModuleXml(): \SimpleXMLElement
    {
        $path = \dirname(__DIR__, 2) . '/etc/module.xml';
        $this->assertFileExists($path, 'etc/module.xml must exist.');

        $xml = \simplexml_load_file($path);
        $this->assertNotFalse($xml, 'etc/module.xml must be valid XML.');

        return $xml;
    }

    public function testDeclaresTheModuleName(): void
    {
        $xml = $this->loadModuleXml();

        $this->assertSame('Magetu_Turnstile', (string) $xml->module['name']);
    }
}
