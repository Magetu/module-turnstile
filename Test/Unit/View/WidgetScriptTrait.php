<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\View;

use RuntimeException;

/**
 * Reads this module's JavaScript for the source-level assertions in this directory.
 *
 * Comments come out first. "The source must contain X" otherwise matches the comment explaining X,
 * so documenting a hazard satisfies the assertion that the guard against it exists, twice already in
 * this module.
 */
trait WidgetScriptTrait
{
    /**
     * One of the module's scripts, with comments removed.
     *
     * @param string $relativePath Below the module root, e.g. `view/base/web/js/turnstile-widget.js`.
     * @return string
     */
    private function jsSource(string $relativePath): string
    {
        $raw = (string)file_get_contents(__DIR__ . '/../../../' . $relativePath);

        return (string)preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $raw);
    }

    /**
     * The widget script, with comments removed.
     *
     * @return string
     */
    private function widgetScript(): string
    {
        return $this->jsSource('view/base/web/js/turnstile-widget.js');
    }

    /**
     * The body of one top-level function in the widget script, brace-matched.
     *
     * Ordering by position cannot tell a call from the declaration it calls: a helper is declared
     * below the render call it is supposed to follow, so "the selector appears after render()" stays
     * true when the only call to it is deleted. Reading the body of the function that must do the
     * calling is what makes deletion visible.
     *
     * @param string $name
     * @return string
     */
    private function functionBody(string $name): string
    {
        return $this->braceBody($this->widgetScript(), 'function ' . $name . '(');
    }

    /**
     * The body of one method in a component or mixin, brace-matched.
     *
     * @param string $code
     * @param string $name
     * @return string
     */
    private function methodBody(string $code, string $name): string
    {
        return $this->braceBody($code, $name . ': function');
    }

    /**
     * The braced block that follows the first occurrence of a needle.
     *
     * @param string $code
     * @param string $needle
     * @return string
     * @throws RuntimeException
     */
    private function braceBody(string $code, string $needle): string
    {
        $start = strpos($code, $needle);

        if ($start === false) {
            throw new RuntimeException(sprintf('"%s" is gone from the source.', $needle));
        }

        $open = strpos($code, '{', $start);

        if ($open === false) {
            throw new RuntimeException(sprintf('"%s" has no body.', $needle));
        }

        $depth = 0;

        for ($i = $open, $length = strlen($code); $i < $length; $i++) {
            if ($code[$i] === '{') {
                $depth++;
            }

            if ($code[$i] === '}' && --$depth === 0) {
                return substr($code, $open + 1, $i - $open - 1);
            }
        }

        throw new RuntimeException(sprintf('"%s" has no matching closing brace.', $needle));
    }
}
