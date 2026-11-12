/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

/**
 * Renders Turnstile inside the native reCAPTCHA Knockout component.
 *
 * Every override here returns `this._super()` untouched unless the component's own settings carry
 * this module's marker, so a store running Google reCAPTCHA sees its stock component. The marker is
 * put there by the UI config providers and travels inside serialised `jsLayout`, which is the only
 * thing the browser gets: no captcha type name reaches this far.
 *
 * What is deliberately NOT done here is joining `Magento_ReCaptchaFrontendUi/js/registry`. The
 * storefront messaging component resets every id in that registry through Google's global whenever an
 * error renders, with no type check, so an entry of ours would throw and abort the loop for the
 * genuine Google widgets behind it.
 */
define([
    'jquery',
    'Magetu_Turnstile/js/turnstile-widget'
], function ($) {
    'use strict';

    var MARKER = 'magetuTurnstile';

    /**
     * The widget render parameters this component was configured with, or null when the configured
     * captcha type is not ours.
     *
     * @param {Object} component
     * @returns {Object|null}
     */
    function widgetOptions(component) {
        var settings = component.settings;

        if (!settings || settings[MARKER] !== true) {
            return null;
        }

        return $.extend({}, settings.rendering);
    }

    /**
     * @param {String} message
     */
    function warn(message) {
        if (window.console && window.console.warn) {
            window.console.warn('Magetu_Turnstile: ' + message);
        }
    }

    return function (target) {
        return target.extend({

            /**
             * Keep Google's script loader out of it.
             *
             * Native injects `google.com/recaptcha/api.js` from here on every render, unconditionally.
             * Letting that run on a Turnstile surface costs a request to a third party that will never
             * be asked to do anything, and arms a global load callback that fires against a component
             * which is no longer waiting for it.
             *
             * @returns {void}
             */
            _loadApi: function () {
                if (!widgetOptions(this)) {
                    this._super();
                }
            },

            /**
             * Start rendering once the container exists.
             *
             * The template calls this from inside a `data-bind` expression, so it runs while the
             * element carrying that binding is still being bound and the child the widget renders into
             * is not in the document yet. Native never notices, because it always waits for a network
             * round trip first. The widget script defers loading the API but resolves its container
             * synchronously, so the wait has to be asked for.
             *
             * @returns {void}
             */
            renderReCaptcha: function () {
                var self = this;

                if (!widgetOptions(this)) {
                    this._super();

                    return;
                }

                window.setTimeout(function () {
                    self.initCaptcha();
                }, 0);
            },

            /**
             * Render the widget and hand the form to whichever subclass is in play.
             *
             * The shape follows native's `initCaptcha()` step for step, because subclasses depend on
             * those steps: `initParentForm()` is where the checkout components register the trigger
             * the place-order flow calls, and the id stamped onto the container is how the place-order
             * mixin finds the active widget in the DOM.
             *
             * @returns {void}
             */
            initCaptcha: function () {
                var self = this,
                    parameters = widgetOptions(this),
                    wrapper,
                    element,
                    form;

                if (!parameters) {
                    this._super();

                    return;
                }

                if (this.captchaInitialized) {
                    return;
                }
                this.captchaInitialized = true;

                wrapper = document.getElementById(this.getReCaptchaId() + '-wrapper');
                element = wrapper ? wrapper.querySelector('.g-recaptcha') : null;

                if (!element) {
                    warn('the container for ' + this.getReCaptchaId() + ' was not found, so this '
                        + 'surface will be rejected on submit');

                    return;
                }

                element.id = this.getReCaptchaId();

                parameters.callback = function (token) {
                    self.reCaptchaCallback(token);
                    self.validateReCaptcha(true);
                };
                parameters['expired-callback'] = function () {
                    self.validateReCaptcha(false);
                };

                // The component id is the instance name: Magento's checkout renders one of these per
                // payment method inside a single form, each with its own id, and without that name
                // the widget script would treat every method after the first as a duplicate of the
                // first and leave its container empty.
                window.MagetuTurnstile.render(element, parameters, this.getReCaptchaId());

                // Native's second argument is Google's widget id. Ours is the container, which is
                // what the subclasses below actually need to read a token back out of, and the only
                // handle that exists at this point: the render is queued, not done.
                form = $(element).closest('form');
                this.initParentForm(form, element);
            }
        });
    };
});
