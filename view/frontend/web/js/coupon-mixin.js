/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

/**
 * Lets the checkout coupon form recover from a rejected code without a page reload.
 *
 * The defect. `Magento_ReCaptchaCheckoutSalesRule/js/checkout-sales-rule` refreshes the captcha on
 * its two retry paths by calling `grecaptcha.reset(widgetId)`, Google's global, with no check of
 * which captcha type is configured. On a Turnstile page that global is not Google's and may not
 * exist at all, so the call throws, the widget is never reset, and the spent single-use token stays
 * in the field. Applying a correct code first time works; correcting a mistyped one is then refused
 * as a duplicate token until the page is reloaded.
 *
 * Why a shim rather than a rewrite. Everything else in that component is wanted: the header
 * modifier, the trigger listener, the show and hide of the container, the success path. Only the
 * `grecaptcha` calls are wrong for us. Re-implementing `initParentForm` to change two lines would
 * copy about forty lines of vendor logic into this package and drift from it on the next Magento
 * release, and the vendor registers its retry callbacks itself, so simply adding our own would leave
 * the throwing ones in front of them. `grecaptcha` is read as a free global and cannot be shadowed
 * locally, so the narrowest correct intervention is to make that global mean the right thing.
 *
 * How ours are told apart, and why not by id. The reset target is decided by the ARGUMENT TYPE, not
 * by a registry of ids. Native passes Google's numeric widget handle; on this module's path the same
 * position carries the widget's container Element, because that is what the base mixin hands on.
 * Testing for an Element is therefore both the accurate test and the one that cannot fall out of
 * step with an id bookkeeping table.
 *
 * What protects a mixed-type store. A real Google API is never replaced: whatever `window.grecaptcha`
 * already was is captured and delegated to for every call this shim does not own. A store running
 * Google reCAPTCHA on one website and Turnstile on another keeps both working, which is the rule the
 * other two mixins follow.
 *
 * No AMD dependency on the widget script. `Magetu_Turnstile/js/turnstile-widget` is a plain IIFE that
 * publishes `window.MagetuTurnstile`; it exports nothing through `define`, so requiring it would bind
 * `undefined`. It is loaded by the page that renders a widget, and every use below is guarded.
 */
define([], function () {
    'use strict';

    var MARKER = 'magetuTurnstile';

    /**
     * A DOM element, as opposed to Google's numeric widget handle.
     *
     * @param {*} candidate
     * @returns {Boolean}
     */
    function isElement(candidate) {
        return Boolean(candidate) && typeof candidate === 'object' && candidate.nodeType === 1;
    }

    /**
     * @returns {Object|undefined}
     */
    function api() {
        return window.MagetuTurnstile;
    }

    /**
     * Install, once, a `grecaptcha` global that resets our widgets and delegates everything else.
     *
     * @returns {void}
     */
    function installShim() {
        var previous = window.grecaptcha;

        if (previous && previous.magetuTurnstileShim) {
            return;
        }

        window.grecaptcha = {
            magetuTurnstileShim: true,

            /**
             * @param {HTMLElement|Number} handle
             * @returns {*}
             */
            reset: function (handle) {
                if (isElement(handle)) {
                    if (api()) {
                        api().reset(handle);
                    }

                    return undefined;
                }

                return previous && previous.reset ? previous.reset(handle) : undefined;
            },

            /**
             * Turnstile mints its token during render, so there is no separate execute step, and this
             * module's webapi mixin already reports the component as the non-invisible kind so the
             * vendor should not reach here for our widgets. It delegates rather than throwing so a
             * Google widget on the same page is untouched if it ever does.
             *
             * @param {HTMLElement|Number} handle
             * @returns {*}
             */
            execute: function (handle) {
                if (isElement(handle)) {
                    return undefined;
                }

                return previous && previous.execute ? previous.execute(handle) : undefined;
            },

            /**
             * @returns {*}
             */
            render: function () {
                return previous && previous.render
                    ? previous.render.apply(previous, arguments)
                    : undefined;
            },

            /**
             * @param {HTMLElement|Number} handle
             * @returns {String}
             */
            getResponse: function (handle) {
                if (isElement(handle)) {
                    var field = handle.querySelector('[name="g-recaptcha-response"]');

                    return field ? field.value : '';
                }

                return previous && previous.getResponse ? previous.getResponse(handle) : '';
            }
        };
    }

    return function (target) {
        return target.extend({

            /**
             * Install the shim before the vendor registers its retry callbacks, so the very first
             * rejected coupon is already recoverable. Anything that is not ours is left untouched.
             *
             * @param {Object} parentForm
             * @param {HTMLElement|Number} widgetId
             * @returns {void}
             */
            initParentForm: function (parentForm, widgetId) {
                if (this.settings && this.settings[MARKER] === true) {
                    installShim();
                }

                this._super(parentForm, widgetId);
            }
        });
    };
});
