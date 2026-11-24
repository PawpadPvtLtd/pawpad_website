# WhatsApp review request

After each visit, Leena's message goes to the customer on WhatsApp from **+91 91484 43330**:

> Hi **Asha**, it’s Leena from Pawpad 🐾
>
> It was lovely having **Bruno** with us today 🛁✨
>
> Your review helps other pet parents find our grooming studio. If you have a minute, I’d be grateful if you shared how **Bruno**’s visit went ⭐
>
> https://g.page/r/CYzUdstKik9sEAE/review
>
> And if anything wasn’t quite right, please reply and tell me. I read every message myself 💌

The customer's first name and the pet's name come from the booking. Two pets on one
booking read "Bruno and Coco". Replies arrive in the WhatsApp Business app as usual.

## When it is sent

- **Once per visit**, when its invoice is made: the booking is **Completed** with a payment
  mode in Today's Closing.
- **Only with the customer's consent.** WhatsApp (Meta) and Indian data-protection law
  require it, and the tick box is never ticked in advance.
  - **Website bookings:** the customer ticks "Yes, Pawpad may send me WhatsApp messages…" at checkout.
  - **Walk-in and phone bookings:** staff tick "The customer agreed to WhatsApp messages" when they agree.
  - **Forgot to tick?** In the **Invoice** column, click **Send WhatsApp review** → **Yes, send**,
    once the customer has agreed.
- Each invoice shows what happened: *WhatsApp review sent / delivered / read ✓*,
  *No WhatsApp review* (with the reason: no consent, no mobile number), or *failed*
  (with Meta's reason). It is never sent twice for the same visit.

## Setting it up at Meta (one time)

WhatsApp only lets a business start a conversation with a **message template** that
Meta has approved. So the text above is saved once in WhatsApp Manager, and the website
fills in the names.

### 1. Create the template

1. Open WhatsApp Manager: https://business.facebook.com/wa/manage/message-templates/
2. **Create template** → Category **Marketing** (Meta counts review requests as marketing)
   → type **Default** / Custom.
3. **Name:** `pawpad_review_request` **Language:** English (`en`).
4. **Body:** paste exactly this (`{{1}}` = name, `{{2}}` and `{{3}}` = pet's name):

   ```
   Hi {{1}}, it’s Leena from Pawpad 🐾

   It was lovely having {{2}} with us today 🛁✨

   Your review helps other pet parents find our grooming studio. If you have a minute, I’d be grateful if you shared how {{3}}’s visit went ⭐

   https://g.page/r/CYzUdstKik9sEAE/review

   And if anything wasn’t quite right, please reply and tell me. I read every message myself 💌
   ```

5. **Samples** (Meta asks for them): `{{1}}` Asha, `{{2}}` Bruno, `{{3}}` Bruno.
6. **Submit.** Approval usually takes minutes, at most about a day.

> The pet's name is used twice, so it is `{{2}}` and `{{3}}`, both filled with the same
> name. Meta numbers each placeholder once. If Meta accepts `{{2}}` twice instead,
> set `'whatsapp_review_params' => ['name', 'pet']` in config.php.

### 2. A permanent access token

1. https://business.facebook.com/settings → **Users** → **System users** → **Add** (role: Admin).
2. **Assign assets:** your app ("Feedback Automation") with full control, and the WhatsApp
   account "Pawpad" with full control.
3. **Generate new token** → choose the app → expiry **Never** → permissions
   **whatsapp_business_messaging** and **whatsapp_business_management** → copy the token.

### 3. config.php (on the server only)

cPanel → File Manager → `public_html/api.pawpad.in/config.php` → Edit. Add these lines
before the last `];`:

```php
    'whatsapp_token'           => 'the token from step 2',
    'whatsapp_phone_number_id' => '1098575573337061',
```

The Phone Number ID is shown in the app under **WhatsApp → API Setup**. It is the one for
+91 91484 43330 (1098575573337061 at the time of setup).

From then on, review requests are sent. The template's language is taken as
"English (US)" (`en_US`); the server also tries plain "English" (`en`) if Meta refuses it.

### 4. Delivered / read updates (optional)

1. Make up a phrase for the verify token, then add both of these to config.php:
   - `'whatsapp_verify_token' => 'your phrase'`
   - `'whatsapp_app_secret' => '…'`, from the app's **App settings → Basic → App secret**.
2. In the app: **WhatsApp → Configuration → Webhook**:
   - Callback URL: `https://api.pawpad.in/index.php?action=whatsapp_webhook`
   - Verify token: the same phrase → **Verify and save** → subscribe to **messages**.
3. Meta only sends live updates to a **published** app: **Publish** the app. For the
   privacy policy URL, use `https://pawpad.in/policies.html`.

## When it fails

The error shown in Today's Closing includes Meta's code, for example
"API access blocked. (Meta code 200)".

- **Code 200, "API access blocked":** Meta is refusing the app or the token. Check, in order:
  1. Meta Business Suite → **Business settings** → **Security Center** / **Account Quality**:
     any restriction on the business or the WhatsApp account. Business verification may be asked for.
  2. **developers.facebook.com** → your app: a banner saying the app is restricted or disabled.
  3. **Business settings** → **Users** → **System users** → the Pawpad system user →
     **Assigned assets**: the **app** and the **WhatsApp account** must both be there (full control).
  4. Generate a new token for that system user with **whatsapp_business_messaging** and
     **whatsapp_business_management** ticked, and put it in `config.php` (`whatsapp_token`).
- **Code 190:** the token expired or was revoked. Make a new permanent token (step 2 above).
- **Code 131042:** add a payment method in WhatsApp Manager.
- **Code 132001:** the template name or language in `config.php` doesn't match the approved template.

After fixing it, click **Send WhatsApp review** → **Yes, send** on the visit to try again.

## Costs

Meta charges per message sent from a template. The price list is in WhatsApp Manager →
**Billing**. The payment method added during setup pays for it.

## For the developer

- Code: `api/lib/whatsapp.php`. Table `whatsapp_messages` (schema 13), one row per
  invoice. `bookings.whatsapp_optin` holds the consent.
- Sent from `store_invoice()` (new invoices only). Staff send: `send_review_whatsapp`.
  Delivery updates arrive at `whatsapp_webhook`, checked with `X-Hub-Signature-256`.
- Settings: `whatsapp_review_template`, `whatsapp_template_language`,
  `whatsapp_review_params` (what fills each placeholder: `name` or `pet`), and
  `whatsapp_reviews_enabled` (false switches it off).
