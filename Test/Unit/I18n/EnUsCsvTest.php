<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;

/**
 * Asserts i18n/en_US.csv exists and every row is a well-formed two-column source,translation pair.
 * This file is shared and append-only (tasks 004/006/008/009 add rows later), so this test does not
 * assert on the row count or on any specific row surviving future appends, only on the CSV shape.
 */
class EnUsCsvTest extends TestCase
{
    private function csvPath(): string
    {
        return \dirname(__DIR__, 3) . '/i18n/en_US.csv';
    }

    public function testShipsAnEnUsTranslationFileWhoseEveryRowIsATwoColumnPair(): void
    {
        $path = $this->csvPath();
        $this->assertFileExists($path, 'i18n/en_US.csv must exist.');

        $handle = \fopen($path, 'r');
        $this->assertNotFalse($handle);

        $rowCount = 0;
        while (($row = \fgetcsv($handle)) !== false) {
            $rowCount++;
            $this->assertCount(
                2,
                $row,
                'Every row must be exactly a source,translation pair (row ' . $rowCount . ').'
            );
            $this->assertNotSame('', $row[0], 'The source column must not be empty (row ' . $rowCount . ').');
            $this->assertNotSame('', $row[1], 'The translation column must not be empty (row ' . $rowCount . ').');
        }
        \fclose($handle);

        $this->assertGreaterThan(0, $rowCount, 'The file must ship at least one seed row.');
    }
}
