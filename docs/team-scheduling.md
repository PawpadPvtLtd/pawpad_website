# Team scheduling: bookings by person

Since November 2026, a grooming booking no longer takes "a slot" for the whole studio.
It reserves the time of the people who do the work: the **bather**, the **groomer**,
and **Leena** (massages). So the studio can take two dogs at once when both team
members are free, and a massage alongside them.

## Who does what, and for how long

| Service | Time | Who |
|---|---|---|
| Puppy Grooming, Short Hair / Long Hair | 1 hour | Whoever is free |
| Dog Grooming, Short Hair | 1 hour | Whoever is free |
| Dog Grooming, Long Hair | 2 hours | Bather 1 hour, then groomer 1 hour |
| Dog Grooming Long Hair with haircut | 2 hours | Bather 1 hour, then groomer 1 hour |
| Breed-Specific Haircut (new, ₹3,500) | 2½ hours | Bather 1 hour, then groomer 1½ hours |
| Hand Stripping (new, ₹4,500) | 2½ hours | Groomer. Customers enquire on WhatsApp first; the team books it |
| Cat Grooming, Short Hair | 1 hour | Groomer |
| Cat Grooming, Long Hair | 1½ hours | Groomer |
| Cat Hair Cut | 1½ hours | Groomer |
| Bath & Brush (dogs, cats, subscription visit) | 1 hour | Whoever is free |
| Hygiene Clip, Nail Clipping | 20 minutes | Groomer |
| Massage | 30 minutes | Leena |
| Matted Dogs | +30 minutes | Groomer, straight after the service. Added by the team only |

"Whoever is free" goes to the bather first, so the groomer stays free for cats and haircuts.
The other extras take no extra time.

## On the website

- Customers see start times every hour from 11 AM to 7 PM (10 AM on Saturday and
  Sunday too), only the ones their service fits, and every service finishes by 8 PM.
- Each time shown has the right people free. With several pets, each pet's times fit
  around the pets chosen before it.
- **Hand Stripping** shows "Enquire on WhatsApp" instead of "Add to cart".
- **Matted Dogs** shows "Added by our team at the studio if needed".
- This can be changed per service: Website Content CMS → Grooming → each package →
  **How customers book**.

## In the admin panel

**Grooming Bookings** shows the day as three columns: Bather, Groomer, Leena.
Each booking also lists who does what ("Bather 11:00 AM–12:00 PM, then Groomer
12:00 PM–1:00 PM"). A booking outlined in red overlaps another one in that column.

- **Matted dog:** tick "Matted dog" on the booking. The groomer gets 30 more minutes
  straight after the service, and the Matted Dogs price is added to the booking. If
  that clashes with the next booking you'll see a warning, but it is still saved, so
  you can decide how to manage it.
- **Leena unavailable:** under the timeline, choose "Leena only (no massages)", then
  all day or from–to, and click **Block**. Massages can't be booked then; groomings can.
- **Block the whole studio:** the same form with "The whole studio". Any event in the
  info@ calendar also blocks the whole studio, as before.
- **Reschedule:** pick a new date, then a start time that fits (★ = also offered on the
  website), or type any time. If it clashes you're asked first, and **Move anyway**
  moves it.
- **Walk-in or phone booking** (Today's Closing → + Add walk-in / phone booking):
  any start time. If it clashes you're asked first, and **Book anyway** saves it.

## Notes

- Bookings made before this change keep their times; their team time was filled in
  automatically from the table above.
- Pawpad's own events in the info@ calendar (bookings, blocks, closures) are not
  counted as "busy" again: they are already in the bookings. If a booking event is
  moved on the phone, move the booking in the admin panel too.
- Studio Closures now include 2 PM and 3 PM.
