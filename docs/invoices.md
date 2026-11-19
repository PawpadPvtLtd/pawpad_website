# Invoices: staff guide

Every grooming visit gets an invoice: a PDF on the Pawpad letterhead (logo, paw
trail, studio details at the bottom). There is no GST on it, because Pawpad is not
registered for GST.

## How an invoice is made

In **Today's Closing**, for each pet:

1. Check the **Amount** (what the customer paid for that pet).
2. Choose the **Payment mode** (UPI, cash, card, bank transfer, or Not paid).
3. Set the **Status** to **Completed**.

The order doesn't matter. Once every pet of the same booking has a status, and the
completed ones have a payment mode, the invoice is made by itself:

- **Customer with an email address:** the PDF is emailed from info@pawpad.in (info@ gets a copy).
- **No email address** (most walk-ins): the invoice is still made. Click **PDF** to
  download it and share it on WhatsApp.

Two dogs on one booking get one invoice with a line for each dog. A Matted Dogs extra
gets its own line. The invoice number and its status ("Emailed", "No email: share the
PDF") show in the **Invoice** column.

If an invoice wasn't made (for example the status was set before this feature), click
**Make invoice** on the row.

## Change an invoice

Click **Edit** (in Today's Closing or the **Invoices** tab) to:

- correct an amount, a description or the customer's details (for example to add an email address);
- **add an item**: an extra such as "Hot oil & coat mask", another service, or anything typed in;
- remove an item.

Saving keeps the same number and marks the invoice **Revised**. Tick **Email the
invoice** to send the corrected PDF.

## The Invoices tab

Every invoice, newest first. Search by invoice number, name, phone or booking
reference. Each one has **PDF**, **Email again** and **Edit**.

## Numbers

`INV/2026-27/0001`, `INV/2026-27/0002`, … The numbering starts again at 0001 each
financial year (1 April).

## For the developer

- Code: `api/lib/invoices.php`. Tables `invoices` and `invoice_bookings` (schema 11,
  created by themselves when the server is updated).
- PDF: tFPDF with the Carlito font (it has the ₹ sign and matches the letterhead's
  Calibri). Both are in `api/lib/tFPDF` (licences in `api/lib/tFPDF/LICENSE.md`).
  Letterhead images: `api/lib/invoice/`.
- The studio details on the invoice are at the top of `api/lib/invoices.php`.
- The booking deposit (PR6) will show as "Less: booking deposit paid" on the invoice.
