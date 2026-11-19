# Booking deposits (Razorpay)

## The rules

- **₹500 per pet**, or the service's full price when it costs less (Nail Clipping: ₹250).
  For grooming and boarding.
- **Deducted from the final bill.** Today's Closing shows the deposit and pre-fills only
  what is left to pay; the invoice shows "Less: booking deposit paid".
- **Cancelled 24 hours or more before:** refunded in full. **No-show, or cancelled later:** kept.
  A reschedule keeps the deposit on the booking.
- **Website:** the customer pays in the Razorpay window at checkout. The time is held for
  **15 minutes** while they pay. If they close the window, pressing the button again opens
  the same payment. Unpaid, the time is released.
- **Phone and WhatsApp bookings:** Today's Closing → **+ Add walk-in / phone booking** →
  keep **Send a Razorpay deposit link** ticked. The time is held for **2 hours**. Razorpay
  sends the link by SMS (and email, if one is entered). You can also **Copy link** or
  **Send on WhatsApp**. Once paid, the booking is confirmed: calendar event, and email if
  there is an address.
- **Boarding:** Boarding Requests → **Confirm with deposit link** (₹500 per dog). The nights
  are held for 2 hours; once paid, the stay is **Confirmed** and the customer is emailed.

Nothing changes until the Razorpay keys are on the server. Before that, bookings work
as before (payment at the studio).

## In the admin panel

- Each booking shows its deposit: *Awaiting deposit* (with the link), *Deposit paid*,
  *Deposit refunded*, *Deposit kept*, or *Deposit not paid (released)*.
- **Cancel booking:** when a deposit was paid, choose **Refund** or **Keep**. It is
  pre-selected by the 24-hour rule. Refunds go back through Razorpay (5 to 7 working days).
- **Refund deposit** (Owner / Administrator) refunds a deposit by hand, for example as a goodwill gesture.
- A **No-show** in Today's Closing marks the deposit as kept.

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
   Save. Deposits start straight away.
4. **Try it:** book a grooming time on pawpad.in, pay the deposit, and check that the
   booking shows *Deposit paid* in Grooming Bookings. Then cancel it to see the refund.

To switch deposits off again without removing the keys, add `'deposits_enabled' => false,`.
Other settings (optional): `deposit_amount` (500), `deposit_hold_minutes` (15),
`deposit_link_hours` (2).

## Payment links and part payments

Leena asked whether Razorpay payment links can take a part payment. They can (the
"Accept partial payments" option). Pawpad doesn't need it here, because each link is
made for exactly the deposit amount.

## For the developer

- Code: `api/lib/payments.php`. Table `payments` (schema 12), plus `deposit`,
  `deposit_status` and `payment_ref` on `bookings` and `boarding_requests`.
- A held booking has status `pending`. It counts as busy team time until its payment's
  `hold_until` time. Expired holds are released at the start of every API request.
- Every payment is checked by its Razorpay signature. The server then fetches it from
  Razorpay (amount, order) and captures it if needed. The webhook and the browser can
  both report the same payment safely: it is handled once.
