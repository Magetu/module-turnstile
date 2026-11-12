<?php

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\Plugin;

use Magento\ReCaptchaAdminUi\Model\CaptchaTypeResolver as AdminResolver;
use Magento\ReCaptchaFrontendUi\Model\CaptchaTypeResolver as FrontendResolver;
use Magetu\Turnstile\Plugin\NormalizeEmptyCaptchaType;
use PHPUnit\Framework\TestCase;

class NormalizeEmptyCaptchaTypeTest extends TestCase
{
    /**
     * @dataProvider captchaTypes
     */
    public function testNormalizesTheEmptyStringAndZeroToDisabled(?string $value, ?string $expected): void
    {
        $plugin = new NormalizeEmptyCaptchaType();
        foreach ([FrontendResolver::class, AdminResolver::class] as $resolver) {
            $this->assertSame($expected, $plugin->afterGetCaptchaTypeFor($this->createMock($resolver), $value));
        }
    }

    public static function captchaTypes(): array
    {
        return [
            'empty string' => ['', null],
            'disabled' => [null, null],
            'Turnstile' => ['magetu_turnstile', 'magetu_turnstile'],
            'Google' => ['recaptcha_v3', 'recaptcha_v3'],
            'whitespace' => [' ', ' '],
            'zero' => ['0', null],
        ];
    }

    public function testRegistersThePluginOnBothResolvers(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../etc/di.xml');
        foreach ([FrontendResolver::class, AdminResolver::class] as $resolver) {
            $plugins = $xml->xpath('/config/type[@name="' . $resolver . '"]'
                . '/plugin[@type="' . NormalizeEmptyCaptchaType::class . '"]');
            $this->assertCount(1, $plugins);
        }
    }
}
