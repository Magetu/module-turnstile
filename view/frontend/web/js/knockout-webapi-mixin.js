/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

/**
 * Supplies the token the REST-guarded checkout surfaces ask for.
 *
 * On those surfaces the token never travels as a form field: the place-order, coupon and gift-card
 * flows call a registered trigger, take what it hands back and put it in the `X-ReCaptcha` header.
 * Native's trigger reads it from Google's global, which does not exist on a Turnstile page, so
 * without this override applying a coupon or placing an order raises a ReferenceError and the flow
 * stops with nothing useful in the log.
 *
 * The token is read from the hidden input the widget writes into its own container, rather than from
 * an API call, because that input is the same value the form-POST surfaces submit: one source, and
 * one that exists whether or not the widget has been asked for its id.
 */
define([
    'Magento_ReCaptchaWebapiUi/js/webapiReCaptchaRegistry'
], function (registry) {
    'use strict';

    var MARKER = 'magetuTurnstile';

    return function (target) {
        return target.extend({

            /**
             * Register the trigger the REST flows call.
             *
             * @param {Object} parentForm
             * @param {Element} container The widget container, passed on by the base mixin.
             * @returns {void}
             */
            initParentForm: function (parentForm, container) {
                var self = this,
                    id = this.getReCaptchaId(),
                    trigger;

                if (!this.settings || this.settings[MARKER] !== true) {
                    this._super(parentForm, container);

                    return;
                }

                trigger = function () {
                    var field = container.querySelector('[name="' + self.getTurnstileFieldName() + '"]');

                    self.reCaptchaCallback(field ? field.value : '');
                };

                // Turnstile has no invisible mode in the sense this flag means (there is no separate
                // execute step to wait for), so the flows are told to treat it as the visible kind and
                // read a token that is already there.
                registry._isInvisibleType[id] = false;

                if (this.autoTrigger) {
                    trigger();
                    registry.triggers[id] = function () {};
                } else {
                    registry.triggers[id] = trigger;
                }

                this.tokenField = null;
            },

            /**
             * Name of the hidden input the widget writes its token into.
             *
             * Taken from the same server-side config the widget was rendered with, never from a
             * literal here: the server reads the token back under that name, and a second copy of it
             * in this file is a rename waiting to go unnoticed.
             *
             * @returns {String}
             */
            getTurnstileFieldName: function () {
                return this.settings
                    && this.settings.rendering
                    && this.settings.rendering['response-field-name'];
            }
        });
    };
});
