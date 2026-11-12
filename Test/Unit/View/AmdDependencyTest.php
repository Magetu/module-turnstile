<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Every AMD id this module names has to resolve to a real file.
 *
 * A mistyped module id is invisible to every other kind of test and catastrophic at runtime. RequireJS
 * answers a missing dependency with a 404, `mixins.js` never calls `onLoad`, and the module that was
 * being mixed into never resolves either. Because a mixin is attached by module id, that happens
 * BEFORE any captcha-type check runs, so a typo here takes the wrapped component down for **every**
 * captcha type on the store, Google included. It is the one failure this module's whole
 * mixin-rather-than-remap design exists to avoid, and it arrives without a line of this module's own
 * code executing.
 *
 * It happened: a mixin shipped requiring `Magento_Checkout/js/action/set-coupon-code`, which does not
 * exist. The real id is `Magento_SalesRule/js/action/set-coupon-code`, which is what the component it
 * wrapped already required. 153 unit tests were green.
 *
 * Ids that resolve inside this module, in Magento's `lib/web`, or in any installed module's
 * `view/{area}/web` are accepted. An id naming a module that is not installed here is reported rather
 * than passed: this package must install on stock Magento, so a dependency on something absent is a
 * defect even when the local tree happens to lack the package for an unrelated reason.
 */
class AmdDependencyTest extends TestCase
{
    use WidgetScriptTrait;

    /**
     * Ids RequireJS resolves itself; they map to no file on disk.
     *
     * @var string[]
     */
    private const VIRTUAL = ['jquery', 'ko', 'underscore', 'uiComponent', 'mage/translate'];

    private function repoRoot(): string
    {
        // .../app/code/Magetu/Turnstile/Test/Unit/View -> seven levels up is the install root.
        return \dirname(__DIR__, 7);
    }

    /**
     * Every `define([...])` id across this module's shipped JavaScript.
     *
     * @return array<string, string[]> file => ids
     */
    private function declaredDependencies(): array
    {
        $found = [];

        foreach ((array)\glob(\dirname(__DIR__, 3) . '/view/*/web/js/*.js') as $file) {
            $source = $this->jsSource(
                \substr((string)$file, \strlen(\dirname(__DIR__, 3)) + 1)
            );

            if (\preg_match('/define\(\s*\[(.*?)\]/s', $source, $block) !== 1) {
                continue;
            }

            \preg_match_all("/'([^']+)'/", $block[1], $ids);
            $found[\basename((string)$file)] = $ids[1];
        }

        return $found;
    }

    /**
     * Where an id would have to live to resolve.
     *
     * @param string $id
     * @return string[]
     */
    private function candidatePaths(string $id): array
    {
        $root = $this->repoRoot();
        $parts = \explode('/', $id, 2);

        if (\count($parts) !== 2 || \strpos($parts[0], '_') === false) {
            // A bare path resolves against Magento's own lib/web.
            return [$root . '/lib/web/' . $id . '.js'];
        }

        [$module, $path] = $parts;
        $candidates = [];

        foreach (['frontend', 'adminhtml', 'base'] as $area) {
            $candidates[] = \sprintf(
                '%s/app/code/%s/view/%s/web/%s.js',
                $root,
                \str_replace('_', '/', $module),
                $area,
                $path
            );
        }

        // Installed packages: the directory name is not derivable from the module name, so glob it.
        foreach (['frontend', 'adminhtml', 'base'] as $area) {
            $candidates[] = \sprintf('%s/vendor/*/*/view/%s/web/%s.js', $root, $area, $path);
            $candidates[] = \sprintf('%s/vendor/*/*/src/view/%s/web/%s.js', $root, $area, $path);
        }

        return $candidates;
    }

    private function resolves(string $id): bool
    {
        foreach ($this->candidatePaths($id) as $candidate) {
            if (\strpos($candidate, '*') === false) {
                if (\is_file($candidate)) {
                    return true;
                }

                continue;
            }

            foreach ((array)\glob($candidate) as $hit) {
                if (\is_file((string)$hit)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function testEveryDeclaredAmdDependencyResolvesToARealFile(): void
    {
        $declared = $this->declaredDependencies();

        // Zero-yield guard: an empty scan would pass silently and prove nothing.
        self::assertNotEmpty($declared, 'no define() blocks were parsed, so this test proves nothing');

        $unresolved = [];

        foreach ($declared as $file => $ids) {
            foreach ($ids as $id) {
                if (\in_array($id, self::VIRTUAL, true) || $this->resolves($id)) {
                    continue;
                }

                $unresolved[] = $file . ' requires ' . $id;
            }
        }

        self::assertSame(
            [],
            $unresolved,
            'A dependency id that 404s stops the module it is mixed into from resolving at all, for '
            . 'every captcha type on the store. Check the id against the component you are wrapping: '
            . 'it usually already requires the same thing.'
        );
    }

    public function testTheScanActuallySeesTheIdsTheBridgeDependsOn(): void
    {
        // Guards the parser rather than the code: if the regex stopped matching, the test above would
        // pass on an empty set.
        $ids = \array_merge(...\array_values($this->declaredDependencies()));

        self::assertContains('Magento_ReCaptchaWebapiUi/js/webapiReCaptchaRegistry', $ids);
        self::assertContains('Magetu_Turnstile/js/turnstile-widget', $ids);
    }
}
