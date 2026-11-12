# Magetu_Turnstile

Adds [Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/) to Magento 2, Adobe Commerce and Mage-OS as a native captcha type, `magetu_turnstile`.

After you install it, "Cloudflare Turnstile" is offered for every form in Magento's captcha settings, under **Stores > Configuration > Security**. Pick it for a form, and Magento's own captcha stack checks that form. No page needs extra code.

Turnstile does not send data to Google, and it sets no third-party cookie by default.

## Requirements

- Magento Open Source, Adobe Commerce, Adobe Commerce Cloud, or Mage-OS, 2.4.7 to 2.4.9
- PHP 8.1 to 8.5
- Magento's own reCAPTCHA modules (`magento/module-re-captcha-*`), installed and enabled

## Install

```bash
composer require magetu/module-turnstile
bin/magento setup:upgrade
```

### If your store removed Magento's reCAPTCHA modules

This module needs them. Undo both of these:

1. Remove any `magento/module-re-captcha-*` entries from the `replace` block of your root `composer.json`, run composer, and check that `vendor/magento/module-re-captcha-*` exists.
2. Enable the modules again. They stay disabled in `app/etc/config.php` until you do:

   ```bash
   bin/magento module:status --disabled | grep '^Magento_ReCaptcha' | xargs bin/magento module:enable
   bin/magento setup:upgrade
   ```

If step 2 is missing, saving a form's captcha type fails with `The "recaptcha_frontend/type_for/..." path doesn't exist`.

## Get your keys

1. In the Cloudflare dashboard, open **Turnstile > Add widget**.
2. Add your store's hostnames and choose a widget mode (Managed is Cloudflare's default).
3. Copy the site key and the secret key.

Create a separate widget for each environment: production, staging, and local.

## Configure

Enter the keys in the admin:

- **Storefront:** Stores > Configuration > Security > Google reCAPTCHA Storefront > Cloudflare Turnstile
- **Admin:** Stores > Configuration > Security > Google reCAPTCHA Admin Panel > Cloudflare Turnstile

Or with the CLI:

```bash
bin/magento config:set recaptcha_frontend/type_magetu_turnstile/public_key "<site key>"
bin/magento config:set recaptcha_frontend/type_magetu_turnstile/private_key "<secret key>"
bin/magento cache:clean config
```

Both keys are stored encrypted. Treat the secret key like a database password.

Then, choose **Cloudflare Turnstile** under "Storefront" (or "Admin Panel") for each form you want to protect.

| Field | What it does | Default |
|---|---|---|
| Turnstile Site Key | Public key, sent to the browser | empty |
| Turnstile Secret Key | Private key, used to verify tokens with Cloudflare | empty |
| Size | Normal, Flexible, or Compact | Flexible |
| Theme | Auto, Light, or Dark | Auto |
| Appearance | Always, Execute, or Interaction Only | Always |
| Language Code | Forces a widget language; empty means auto-detect | empty |
| Send Visitor IP to Cloudflare | Sends the visitor's IP with each check | No |
| Validate Action | Rejects a token made for a different form | Yes |
| Allowed Hostnames | Comma-separated hostnames a token must come from; empty means your store's base URL host | empty |

The widget mode (Managed, Non-interactive, or Invisible) is set on the widget in Cloudflare, not here. To change it, change it in Cloudflare.

### Test keys

Cloudflare's public test keys work on any domain, including `localhost`:

| Site key | Behavior |
|---|---|
| `1x00000000000000000000AA` | Always passes (visible) |
| `2x00000000000000000000AB` | Always fails (visible) |
| `3x00000000000000000000FF` | Forces an interactive challenge |

| Secret key | Behavior |
|---|---|
| `1x0000000000000000000000000000000AA` | Always passes |
| `2x0000000000000000000000000000000AA` | Always fails |
| `3x0000000000000000000000000000000AA` | Always reports "token already spent" |

## Supported forms

Every form Magento offers a captcha for: login, register, forgot password, edit account, contact, newsletter, product review, wishlist share, email to a friend, company registration, invitation, coupon and gift card (cart and checkout), the checkout login and authentication popup, place order, and admin login and forgot password.

Hyvä themes are supported through Hyvä's standard reCAPTCHA block names. No theme change is needed.

## Known limits

- **Express and wallet buttons** (Apple Pay, Google Pay, PayPal express, and similar) that place orders without the normal checkout form are not covered. Test each payment method after you turn on "Place order".
- **Custom or one-page checkouts** that move or hide the payment area can leave the widget in a hidden container. An interactive challenge then has nowhere to show. Check that the widget is visible on your checkout, or use an Invisible widget there.
- **Magento's REST place-order guard** checks the `payment-information` call only. Magento does not guard the direct `PUT .../order` REST routes, whichever captcha type you use.
- **Integration-token API calls** skip captcha checks. This is Magento's behavior. Admin-token calls are still checked.
- **Tokens are single use.** If you write your own AJAX submit for a protected form, reset the widget before a retry: `window.MagetuTurnstile.reset(form)`.
- **PayPal Payflow Pro** is wired up but has not been tested on a live store.
- **Admin login:** turn Turnstile on for the admin only after the storefront works. If you are locked out, run:

  ```bash
  bin/magento config:set recaptcha_backend/type_for/user_login "" --scope=default
  bin/magento cache:clean config
  ```

## Uninstall

First, switch every form that uses Cloudflare Turnstile to another type, or to "No". A form that still points at a removed type causes an error on that page.

```bash
bin/magento config:set recaptcha_frontend/type_for/<form> "" --scope=default   # for each form you set
bin/magento cache:clean config
```

Use `config:set` (or the admin) to clear a form's type. Writing an empty string straight into the database causes an error on every page that shows that form.

Then remove the module with [Magetu_ModuleDataCleanUp](https://github.com/Magetu/module-data-cleanup) (`magetu/module-data-cleanup`), the recommended way. It also deletes the keys and settings this module saved, and its `setup_module` record. Add a data patch to one of your own modules, as that module's README shows:

```php
use Magetu\ModuleDataCleanUp\Setup\Patch\AbstractModuleDataCleanUp;

class RemoveMagetuTurnstile extends AbstractModuleDataCleanUp
{
    protected string $moduleName = 'Magetu_Turnstile';

    /** @var string[] */
    protected array $configSectionGroups = [
        'recaptcha_frontend/type_magetu_turnstile/',
        'recaptcha_backend/type_magetu_turnstile/',
    ];
}
```

Then run:

```bash
bin/magento module:disable Magetu_Turnstile
composer remove magetu/module-turnstile
bin/magento setup:upgrade
```

## License

MIT. See [LICENSE.md](LICENSE.md).
