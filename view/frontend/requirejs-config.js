/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

/**
 * Two mixins onto the native reCAPTCHA Knockout components.
 *
 * Not every guarded surface renders through a block. The authentication popup, the inline logins on
 * the checkout page, store-pickup login, coupon, gift card and place order are all injected as
 * `jsLayout` and rendered by these components, which call Google's API with whatever site key the
 * resolved UI config carries. Handing them a Cloudflare key breaks those surfaces; the template swap
 * cannot reach them, because there is no block to swap.
 *
 * Mixins rather than a requirejs `map`: mapping the module id away would take the component from
 * every captcha type at once, and a store running Google reCAPTCHA on one website and Turnstile on
 * another has to keep both working. Each override reads the marker on its own settings and calls
 * `_super()` for anything that is not ours, so a Google-only store gets its unmodified component
 * back.
 */
var config = {
    config: {
        mixins: {
            'Magento_ReCaptchaFrontendUi/js/reCaptcha': {
                'Magetu_Turnstile/js/knockout-mixin': true
            },
            'Magento_ReCaptchaWebapiUi/js/webapiReCaptcha': {
                'Magetu_Turnstile/js/knockout-webapi-mixin': true
            },
            // The checkout coupon component refreshes its captcha through Google's `grecaptcha`
            // global on both retry paths, with no captcha-type check, so a rejected coupon leaves a
            // spent single-use token behind and the retry is refused. It extends webapiReCaptcha, so
            // the mixin above already reaches everything it INHERITS; these calls are its own code
            // and need the component itself wrapped.
            'Magento_ReCaptchaCheckoutSalesRule/js/checkout-sales-rule': {
                'Magetu_Turnstile/js/coupon-mixin': true
            }
        }
    }
};
