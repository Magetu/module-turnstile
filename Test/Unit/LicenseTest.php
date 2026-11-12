<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the shipped LICENSE.md is actually the MIT licence composer.json claims, not just a
 * same-named file.
 */
class LicenseTest extends TestCase
{
    public function testLicenseFileIsMit(): void
    {
        $path = \dirname(__DIR__, 2) . '/LICENSE.md';
        $this->assertFileExists($path, 'LICENSE.md must exist.');

        $contents = (string) \file_get_contents($path);

        $this->assertStringContainsString('MIT License', $contents);
        $this->assertStringContainsString(
            'Permission is hereby granted, free of charge, to any person obtaining a copy',
            $contents,
            'The file must carry the actual MIT licence grant text, not just the name "MIT".'
        );
    }
}
