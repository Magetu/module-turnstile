<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

/**
 * Asserts etc/csp_whitelist.xml whitelists challenges.cloudflare.com for script-src and frame-src
 * only (architecture §5.4): no connect-src, no style-src, no other host.
 */
class CspWhitelistXmlTest extends TestCase
{
    private const HOST = 'https://challenges.cloudflare.com';

    private function loadCspWhitelistXml(): \SimpleXMLElement
    {
        $path = \dirname(__DIR__, 3) . '/etc/csp_whitelist.xml';
        $this->assertFileExists($path, 'etc/csp_whitelist.xml must exist.');

        $xml = \simplexml_load_file($path);
        $this->assertNotFalse($xml, 'etc/csp_whitelist.xml must be valid XML.');

        return $xml;
    }

    /**
     * @return string[] host values declared under the given policy id
     */
    private function hostsFor(\SimpleXMLElement $xml, string $policyId): array
    {
        $hosts = [];
        foreach ($xml->policies->policy as $policy) {
            if ((string) $policy['id'] !== $policyId) {
                continue;
            }
            foreach ($policy->values->value as $value) {
                $hosts[] = (string) $value;
            }
        }

        return $hosts;
    }

    public function testWhitelistsChallengesCloudflareComForScriptSrc(): void
    {
        $xml = $this->loadCspWhitelistXml();

        $this->assertContains(self::HOST, $this->hostsFor($xml, 'script-src'));
    }

    public function testWhitelistsChallengesCloudflareComForFrameSrc(): void
    {
        $xml = $this->loadCspWhitelistXml();

        $this->assertContains(self::HOST, $this->hostsFor($xml, 'frame-src'));
    }

    public function testWhitelistsNoHostOtherThanChallengesCloudflareCom(): void
    {
        $xml = $this->loadCspWhitelistXml();

        $allHosts = [];
        foreach ($xml->policies->policy as $policy) {
            foreach ($this->hostsFor($xml, (string) $policy['id']) as $host) {
                $allHosts[] = $host;
            }
        }

        $this->assertNotEmpty($allHosts, 'The whitelist must declare at least one host.');
        foreach ($allHosts as $host) {
            $this->assertSame(self::HOST, $host);
        }
    }

    public function testDeclaresNoDirectiveOtherThanScriptSrcAndFrameSrc(): void
    {
        $xml = $this->loadCspWhitelistXml();

        $policyIds = [];
        foreach ($xml->policies->policy as $policy) {
            $policyIds[] = (string) $policy['id'];
        }
        \sort($policyIds);

        $this->assertSame(['frame-src', 'script-src'], $policyIds);
    }
}
