# Online payments (Razorpay)

Since November 2026 there is **no ₹500 token / deposit**. Customers pay the **full price**
of the service online when they book.

## The rules

- **Grooming:** the full price of each service (as on the website's price list) is paid
  online at booking. Two pets: the two prices together. A customer must choose a service
  to book a time.
- **Extras** decided at the studio (Matted, add-ons, anything not booked online) are paid
  at the studio. Today's Closing shows "₹X paid online" and pre-fills only what is left
  to pay (normally ₹0). The invoice shows "Paid online (Razorpay)" and "Paid at the studio".
- **Boarding:** Boarding Requests → check the **Stay amount** (pre-filled from the
  estimated total; correct it if needed) → **Confirm with payment link (full stay)**.
  The nights are held for 2 hours; once paid, the stay is **Confirmed** and the customer
  is emailed.
- **Cancelled 24 hours or more before** (boarding: before check-in): refunded in full.
- **Cancelled later, or a no-show:** the staff decide each time: **Refund all**,
  **Refund all but ₹500**, or **Keep the whole payment**. The website's Refund Policy says
  a late refund is at Pawpad's discretion (partial or none).
- A reschedule keeps the payment on the booking.
- **Website:** the customer pays in the Razorpay window at checkout. The time is held for
  **15 minutes** while they pay. If they close the window, pressing the button again opens
  the same payment. Unpaid, the time is released.
- **Phone and WhatsApp bookings:** Today's Closing → **+ Add walk-in / phone booking** →
  keep **Send a Razorpay payment link** ticked. The link is for the full price of the
  chosen service (or the amount typed). The time is held for **2 hours**. Razorpay sends the
  link by SMS (and email, if one is entered). You can also **Copy link** or
  **Send on WhatsApp**. Once paid, the booking is confirmed: calendar event, and email if
  there is an address.

Nothing changes until the Razorpay keys are on the server. Without them, bookings work
as before (payment at the studio).

## In the admin panel

- Each booking shows its payment: *Awaiting payment* (with the link), *Paid online*,
  *Refunded*, *Partly refunded*, *Payment kept*, or *Not paid (released)*.
- **Cancel booking:** when the booking was paid online, choose **Refund all**,
  **Refund all but ₹500**, or **Keep the whole payment**. 24 hours or more ahead,
  "Refund all" is pre-selected; later, nothing is pre-selected and you must choose.
  Refunds go back through Razorpay (5 to 7 working days). The customer's email says what
  was refunded.
- **Refund all** / **Refund all but ₹500** buttons (Owner / Administrator) refund a payment
  by hand, for example after a no-show or as a goodwill gesture. You are asked to confirm.
- A **No-show** in Today's Closing marks the payment as kept (it can still be refunded with
  the buttons above).

## Setting up Razorpay (one time)

> Type the keys **only** in cPanel's File Manager, in `config.php`. Never in a chat, email or GitHub.

Start in **Test Mode** (the switch at the top of the Razorpay Dashboard) to try it with
Razorpay's test cards. Then repeat the steps in **Live Mode**.

1. **API keys:** Razorpay Dashboard → **Account & Settings** → **API Keys** → **Generate Key**.
   Keep the window open: the *Key Secret* is shown only once.
2. **Webhook:** Razorpay Dashboard → **Account & Settings** → **Webhooks** → **Add New Webhook**:
   - Webhook URL: `https://api.pawpad.in/index.php?action=razorpay_webhook`
   - Secret: make up a long random phrase (you'll type the same one in `config.php`)
   - Active events: **payment.captured**, **order.paid**, **payment_link.paid**
3. **config.php:** cPanel → File Manager → `public_html/api.pawpad.in/config.php` → Edit.
   Add these lines just before the last `];`, with your values:
   ```php
       'razorpay_key_id'         => 'rzp_live_…',
       'razorpay_key_secret'     => '…',
       'razorpay_webhook_secret' => '…',
   ```
   Save. Online payment starts straight away.
4. **Try it:** book a grooming time on pawpad.in, pay, and check that the booking shows
   *Paid online* in Grooming Bookings. Then cancel it to see the refund.

To switch online payment off without removing the keys, add `'deposits_enabled' => false,`.
Other settings (optional): `deposit_amount` (500: what "Refund all but ₹500" keeps),
`deposit_hold_minutes` (15), `deposit_link_hours` (2).

## For the developer

- Code: `api/lib/payments.php`. Table `payments` (schema 12), plus `deposit`,
  `deposit_status` and `payment_ref` on `bookings` and `boarding_requests`. The names
  still say "deposit" but hold the full amount paid online.
- Prices: the service's price from the CMS when known on the server, else
  `DEPOSIT_KNOWN_PRICES` in `payments.php` (keep it in step with the price list).
  A service with no known price can't be booked online while payments are on.
- A held booking has status `pending`. It counts as busy team time until its payment's
  `hold_until` time. Expired holds are released at the start of every API request.
- Every payment is checked by its Razorpay signature. The server then fetches it from
  Razorpay (amount, order) and captures it if needed. The webhook and the browser can
  both report the same payment safely: it is handled once.
