# Managers, studio closures and the daily report

This update adds three things to the admin panel:

1. A new **Manager** role for front-desk staff.
2. **Studio Closures**: close the studio for some dates, either all day or only at some times.
3. **Today's Closing**: the day's bookings with status and payment, walk-ins, and a **daily report email**.

Every rule below is checked **on the server** (api.pawpad.in), not only by
hiding buttons. If a Manager tries a blocked action another way, the server
answers "Not allowed".

---

## Step 1 — Update the server (once)

1. Merge the pull request on GitHub.
2. Update the server the usual way (cPanel → **Terminal**, paste the deploy
   block, look for **DEPLOY OK**). See [Updating the Pawpad server](updating-the-server.md).
3. Open **https://api.pawpad.in/index.php?action=health**. It should show
   `"ok":true`.

**You do not need to create anything in the database.** On the first visit
after the update, the server creates four new tables by itself:
`course_payments`, `closures`, `booking_closing` and `daily_reports`. It also
adds two columns to `bookings`. Nothing new goes in `config.php`: the daily
report is sent from info@pawpad.in with the password that is already there.

---

## Who can do what

| | Owner | Administrator | Manager |
|---|:-:|:-:|:-:|
| Upcoming Grooming, Grooming Bookings | ✓ | ✓ | ✓ |
| Cancel or reschedule a booking | ✓ | ✓ | ✓ |
| Block / unblock a time or a whole day, Tidy calendar | ✓ | ✓ | — |
| Studio Closures | ✓ | ✓ | — |
| Course Applications: see, add notes, schedule interview (with email) | ✓ | ✓ | ✓ |
| Record a course payment, then mark **Enrolled** | ✓ | ✓ | ✓ |
| Approve, decline or reopen an application | ✓ | ✓ | — |
| Delete candidates, remove a payment | ✓ | ✓ | — |
| Today's Closing, walk-ins, send the daily report | ✓ | ✓ | ✓ |
| Daily Reports (all sent reports) | ✓ | ✓ | — |
| Website Content, Media, Dashboard, Backups | ✓ | ✓ | — |
| Add or remove team members | ✓ | — | — |
| Change own password | ✓ | ✓ | ✓ |

The person's role is shown at the top right of the admin panel ("Signed in as Manager").

Every action is written in the history with the person's email: cancel and
reschedule in the booking's history; interview, payment and enrolment in the
application's **Staff Notes & Admissions Log**.

---

## How the owner adds a Manager

1. Sign in to the admin panel as the **Owner**.
2. Click **Settings & Backups** in the left menu.
3. Under **Authorized Administrator Team**:
   - type the person's email,
   - type a **starting password** (at least 10 characters),
   - in the drop-down, choose **Manager (bookings, applications, daily closing)**,
   - click **Add Team Member**.
4. Give the person the starting password **in person** (not by WhatsApp or email).
5. When they sign in, they should open **My Account** and change the password.

To remove a person, click **Remove** next to their email. They are signed out
straight away. Only the Owner can add or remove people.

---

## Course payments and enrolment

A candidate can be marked **Enrolled** only after a payment is recorded.

1. Open **Course Applications** and click a candidate.
2. Under **Payments**, fill in the amount, the date, how it was paid
   (UPI / Cash / Card / Bank transfer) and the reference number (UTR or receipt).
3. Click **Record Payment**.
4. The **Confirm Enrolled** button now works. Click it.

If a payment was typed wrongly, an Owner or Administrator can click **Remove**
next to it and record it again.

---

## Studio Closures (Owner and Administrator)

Use this for holidays, training days or a closed afternoon. **Block whole day**
still works as before for single days.

1. Click **Studio Closures** in the left menu.
2. Choose **From** and **To** (both days are included).
3. Keep **All day** ticked, or untick it and tick only the times to close
   (10:00 exists only on Saturday and Sunday).
4. Optionally type a reason, e.g. "Diwali holiday".
5. Click **Check bookings & continue**. The panel lists every booking already
   made inside the closure. **These bookings are not cancelled.** Call the
   customers, or reschedule/cancel them under Grooming Bookings.
6. Click **Save closure**.

The closed times disappear from the booking page straight away, and the closure
appears in the info@ calendar ("Studio closed: …").

To re-open, find it under **Upcoming closures** and click **Remove (re-open)**.
The times become bookable again and the calendar event is deleted.

---

## Today's Closing (every evening)

1. Click **Today's Closing**. It shows today's bookings in time order
   (use **← Previous day** to finish an earlier day).
2. For each booking choose:
   - **Status**: Completed / No-show / Cancelled,
   - **Amount**: filled in with the service price; change it if needed,
   - **Payment mode**: UPI / Cash / Card / Bank transfer / Not paid,
   - optionally a **Reference** and a short **Note**.

   Everything saves by itself ("Saved ✓" appears on the right).
3. **Walk-in or phone booking**: click **+ Add walk-in / phone booking**, fill
   in the time, name, phone, pet, service, amount and payment mode, then
   **Add booking**. If that time is free, it is taken on the website and added to
   the info@ calendar. If it isn't free, the booking is only recorded.
4. Course payments recorded that day (under Course Applications) are listed at
   the bottom and included in the report.
5. Click **Send daily report**. A preview opens.
   - If some bookings have no status yet, they are listed in red. Click
     **Back to closing** and fill them in, or click **Send anyway**.
   - Click **Send report**. It is emailed from info@pawpad.in to every Owner
     and Administrator (not to Managers).

The email shows the date and who sent it, the table of bookings, totals
(completed / no-shows / cancelled, total collected, total per payment mode,
the Not-paid list) and the day's course payments.

**Made a mistake after sending?** Correct it on Today's Closing and click
**Send daily report** again. The new email is marked **"Corrected report"**.

## Daily Reports (Owner and Administrator)

Click **Daily Reports** to see every report that was sent: the day, when, by
whom, and whether it was a correction. Click **View** to read it.

---

## If something doesn't work

| What you see | What to do |
|---|---|
| "Not allowed: a Manager cannot do this." | That action is for the Owner or an Administrator |
| "Record the payment first…" | Record the payment under Payments, then enrol |
| "The report could not be emailed…" | Check the health page: `"bookingEmail":true` means the info@ password in config.php is right |
| A closure doesn't show in the phone calendar | Pull down to refresh the calendar app; the slots are closed on the website either way |
| Anything else | Send me a screenshot of the message, but **never** of `config.php` |
