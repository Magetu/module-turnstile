/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

/**
 * Cloudflare Turnstile widget bootstrapper, served from one file to the storefront and the admin
 * panel. Public surface is two functions:
 *
 *   window.MagetuTurnstile.render(container, options[, instance])
 *                                                      container is an element or a CSS selector;
 *                                                      instance separates several widgets in one form
 *                                                      from one widget reached twice, and defaults to
 *                                                      the widget action
 *   window.MagetuTurnstile.reset(target)               target is a form, a container, an ancestor,
 *                                                      or nothing at all to reset every widget
 *
 * A plain script rather than an AMD module, assuming no framework: an admin login page and a
 * storefront page share little besides a DOM.
 *
 * ## Native API, never the reCAPTCHA compatibility mode
 *
 * Turnstile can be loaded in a mode that publishes itself under Google's global name and names its
 * hidden input the Google name for free. We don't: two scripts can't both own that name, so a page
 * carrying a Turnstile surface and a real Google surface at once (the normal state during this
 * module's phased migration) would break both. The rename is done instead with the documented
 * `response-field-name` render parameter, which gets the same result and claims nothing.
 *
 * ## Our widget ids are NOT pushed into the native captcha registry, on purpose
 *
 * The native storefront stack's messaging component calls reset() on the *Google* captcha API for
 * every id in that registry, unconditionally, whenever an error renders. Joining it would throw on
 * our entries (no Google global exists), aborting that loop mid-way so genuine Google widgets listed
 * after ours stop resetting too. Staying out is the only choice that can't damage another type.
 *
 * The price is that nothing external resets our widget after a failed submit, and a Turnstile token
 * is single-use with a 300 second lifetime, so a retry carrying a spent one is rejected as a
 * duplicate. This file covers that itself, three ways: (1) the surfaces this module targets answer a
 * rejected submit with a full page load, which re-renders the widget and mints a fresh token; (2)
 * `expired-callback` resets its own widget in place, so an idle visitor still submits a live token;
 * (3) `reset(target)` is exported for any caller that re-submits without a page load.
 */
(function () {
    'use strict';

    if (window.MagetuTurnstile) {
        // The template emits this <script src> once per widget, so a page with two guarded forms
        // executes this file twice. Only the first run may install anything.
        return;
    }

    var API_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
    var IDLE_TIMEOUT_MS = 1500;

    var rendered = [];
    var queued = [];
    var apiRequested = false;
    var loadScheduled = false;

    /**
     * @param {String} message
     */
    function warn(message) {
        if (window.console && window.console.warn) {
            window.console.warn('Magetu_Turnstile: ' + message);
        }
    }

    /**
     * Call a caller-supplied callback, if there is one.
     *
     * @param {Function} [callback]
     * @param {*} [argument]
     * @returns {void}
     */
    function call(callback, argument) {
        if (typeof callback === 'function') {
            callback(argument);
        }
    }

    /**
     * @returns {Boolean}
     */
    function apiReady() {
        return Boolean(window.turnstile && window.turnstile.render);
    }

    /**
     * Render everything queued so far. Safe to call more than once; the queue is drained, not read.
     */
    function drain() {
        var pending = queued.splice(0, queued.length);

        if (!apiReady()) {
            warn('the Turnstile API loaded but did not initialise, so no widget was rendered');

            return;
        }

        pending.forEach(function (entry) {
            var widgetId;
            var parameters = {};
            var callerFailed = entry.options['error-callback'];
            var callerExpired = entry.options['expired-callback'];

            Object.keys(entry.options).forEach(function (name) {
                parameters[name] = entry.options[name];
            });

            // Ours run first and the caller's after, rather than one replacing the other: a caller
            // that hands us a lifecycle callback has its own state to keep in step (a checkout ticks
            // a required checkbox off the back of these), and silently dropping it leaves that state
            // stale with nothing in the log.
            parameters['error-callback'] = function (code) {
                warn('the widget reported error ' + code + '; this submit will be rejected');
                call(callerFailed, code);
            };
            parameters['expired-callback'] = function () {
                if (widgetId !== undefined && apiReady()) {
                    window.turnstile.reset(widgetId);
                }
                call(callerExpired);
            };

            widgetId = window.turnstile.render(entry.element, parameters);

            if (widgetId === undefined || widgetId === null) {
                warn('the widget could not be rendered; check the site key and the widget hostnames');

                return;
            }

            rendered.push({
                element: entry.element,
                form: entry.form,
                id: widgetId,
                instance: entry.instance
            });
            unlockSubmit(entry.form);
        });
    }

    /**
     * Whether a widget is standing in this container right now.
     *
     * Judged from the DOM, not from our own registry: the registry records what we asked for, the
     * DOM what is actually there, and those diverge exactly when it matters: a re-render that
     * discards the container's children, or replaces the container with a detached copy.
     *
     * @param {Element} element
     * @returns {Boolean}
     */
    function widgetIsLive(element) {
        return element.children.length > 0 && document.contains(element);
    }

    /**
     * Whether this form already carries THIS widget, rendered or on its way.
     *
     * Keyed on the form AND an instance name, because one form legitimately holds several widgets
     * while also being reachable twice for the same one, and only the second is a duplicate:
     *
     * - Several. Magento's checkout renders one place-order widget per payment method, all inside a
     *   single `#co-payment-form`. Ten enabled methods means ten containers with ten distinct ids.
     *   Deduplicating on the form alone leaves nine of them empty, and the nine that never rendered
     *   still register a token trigger, so picking any of those methods posts an empty token and the
     *   order is refused.
     * - Twice. On a Hyvä storefront the theme fragment and the native block both target the same
     *   logical field in the same form, and this module's template swap turns the second one from
     *   inert markup into a live widget. Two widgets, two hidden inputs sharing a name, only one
     *   carrying a token, so the surface fails on document order.
     *
     * The instance name separates them: the Knockout path passes its own per-method component id, and
     * the block and theme paths fall back to the captcha id, which those two duplicate renders share.
     *
     * @param {Element} form
     * @param {String} instance
     * @returns {Boolean}
     */
    function formHasInstance(form, instance) {
        var queuedAlready = queued.some(function (entry) {
            return entry.form === form && entry.instance === instance;
        });

        return queuedAlready || rendered.some(function (widget) {
            return widget.form === form
                && widget.instance === instance
                && widgetIsLive(widget.element);
        });
    }

    /**
     * The name that decides whether two renders are the same widget.
     *
     * @param {Object} options
     * @param {String} [instance] Explicit name from the caller; the Knockout bridge passes one.
     * @returns {String}
     */
    function instanceName(options, instance) {
        return instance || (options && options.action) || '';
    }

    /**
     * Drop any registry entry for a container we are about to render into again.
     *
     * Without this the registry grows on every re-render and `reset()` would address widget ids
     * Turnstile has already discarded.
     *
     * @param {Element} element
     * @returns {void}
     */
    function forget(element) {
        var i;

        for (i = rendered.length - 1; i >= 0; i--) {
            if (rendered[i].element === element) {
                rendered.splice(i, 1);
            }
        }
    }

    /**
     * Re-enable the submit button Magento disabled server-side.
     *
     * ButtonLock::isDisabled() locks a guarded form's submit button without looking at which captcha
     * type is configured, and only the native reCAPTCHA JS component, which this module's template
     * replaces, ever removes the attribute. Skip this and a rendered widget still leaves the form
     * permanently unsubmittable, worse than failing closed: the visitor can't even attempt the action.
     *
     * The selector matches the one native uses. Unlock fires after a successful render, not a solve
     * callback: the button is disabled from page load, and an invisible or interaction-only widget
     * may never produce a visible success event at all.
     *
     * @param {Element|null} form
     * @returns {void}
     */
    function unlockSubmit(form) {
        var buttons;

        if (!form || typeof form.querySelectorAll !== 'function') {
            return;
        }

        buttons = form.querySelectorAll('button:not([type]), [type=submit]');

        Array.prototype.forEach.call(buttons, function (button) {
            button.disabled = false;
            button.removeAttribute('disabled');
        });
    }

    /**
     * Inject the API script once, whatever calls this and however often.
     */
    function requestApi() {
        var tag;

        if (apiRequested) {
            return;
        }
        apiRequested = true;

        tag = document.createElement('script');
        tag.src = API_URL;
        tag.async = true;
        tag.defer = true;
        // Deliberately NOT turnstile.ready(): calling it on an async/deferred tag makes Cloudflare
        // throw "Remove async/defer... before using turnstile.ready()" inside their own script,
        // uncatchable here, so no widget renders and every guarded form stays rejected. This tag's
        // own onload already fires after window.turnstile is populated, the same signal ready()
        // would have given us.
        tag.onload = drain;
        tag.onerror = function () {
            warn('the Turnstile API could not be loaded, so guarded forms on this page will be rejected');
        };
        document.head.appendChild(tag);
    }

    /**
     * Hold the third-party script back until the visitor touches the page, with a guaranteed idle
     * fallback.
     *
     * The fallback is not optional. On interaction alone, a form filled and submitted by a password
     * manager never fires one, and the visitor would post no token at all and be rejected, on the
     * admin login page that reads as being locked out.
     */
    function scheduleApiLoad() {
        var events = ['pointerdown', 'keydown', 'focusin'];
        var go;

        if (loadScheduled) {
            return;
        }
        loadScheduled = true;

        go = function () {
            events.forEach(function (name) {
                document.removeEventListener(name, go, true);
            });
            requestApi();
        };

        events.forEach(function (name) {
            document.addEventListener(name, go, true);
        });

        if (window.requestIdleCallback) {
            window.requestIdleCallback(go, {timeout: IDLE_TIMEOUT_MS});
        } else {
            window.setTimeout(go, IDLE_TIMEOUT_MS);
        }
    }

    /**
     * Queue one widget for rendering.
     *
     * @param {HTMLElement|String} container
     * @param {Object} options Turnstile render parameters, already resolved server side.
     * @param {String} [instance] Name separating sibling widgets in one form from a duplicate render
     *                            of the same one. Defaults to the widget action.
     */
    function render(container, options, instance) {
        var element = typeof container === 'string' ? document.querySelector(container) : container;
        var name = instanceName(options, instance);
        var form;

        if (!element) {
            warn('no container element was found, so no widget was rendered');

            return;
        }

        form = element.closest ? element.closest('form') : null;

        // Liveness, never a one-way latch: a DOM-morphing checkout can empty this container while
        // leaving the form in place, and a latch would turn that recoverable state into a permanent
        // one: no widget, no token, every submit rejected. A container with no form of its own can
        // only be deduplicated against itself.
        if (form ? formHasInstance(form, name) : widgetIsLive(element)) {
            return;
        }

        forget(element);

        queued.push({element: element, form: form, options: options || {}, instance: name});

        if (apiReady()) {
            drain();

            return;
        }

        scheduleApiLoad();
    }

    /**
     * Discard the tokens held by the matching widgets and mint fresh ones.
     *
     * @param {HTMLElement} [target] A form, a widget container, or an ancestor of one. Omit to reset
     *                               every widget on the page.
     */
    function reset(target) {
        if (!apiReady()) {
            return;
        }

        rendered.forEach(function (widget) {
            var matches = !target
                || target === widget.form
                || target === widget.element
                || Boolean(target.contains && target.contains(widget.element));

            if (matches) {
                window.turnstile.reset(widget.id);
            }
        });
    }

    window.MagetuTurnstile = {render: render, reset: reset};
})();
