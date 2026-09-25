# Updating the Pawpad server (api.pawpad.in)

Whenever a pull request that changes the `api/` folder is merged on GitHub, the
server needs to copy the new files. cPanel does the copying for you with two
clicks. Your settings file (`config.php`, with the passwords) and uploaded
photos are never touched.

**Time needed:** about 2 minutes.

> The website pages themselves (the part visitors see) update on their own
> when a pull request is merged. Only the server needs these steps.

---

## Every time: update the server

1. Merge the pull request on GitHub (green **Merge pull request** button → **Confirm merge**).
2. Sign in to **cPanel**.
3. In the search box at the top, type **Git** and open **Git™ Version Control**.
4. You'll see the repository `pawpad_website` (at `/home/pawpad35/repositories/pawpad_website`).
   Click **Manage** next to it.
5. Click the **Pull or Deploy** tab.
6. Click **Update from Remote**. This downloads the latest version from GitHub.
   Wait until the green "success" message appears.
7. Click **Deploy HEAD Commit**. This copies the new `api/` files into
   `/home/pawpad35/public_html/api.pawpad.in`.
   Wait until the green "success" message appears.
8. Check it worked: open **https://api.pawpad.in/index.php?action=health**.
   You should see `{"ok":true,"database":true,"email":true}`.

That's it. If the server needs new database tables, it creates them by itself
on the first visit after the update. You don't need to run `setup.php` again.

## If "Deploy HEAD Commit" is greyed out or fails

| What you see | What to do |
|---|---|
| **Deploy HEAD Commit** is greyed out | Click **Update from Remote** first, then try again |
| "The repository has uncommitted changes" | Someone edited files inside `/home/pawpad35/repositories/pawpad_website` in File Manager. Don't edit files there; send me a screenshot and I'll help |
| "No .cpanel.yml file" | The update isn't merged on GitHub yet. Merge the pull request, then click **Update from Remote** again |
| The health check shows an error about the database | Open `config.php` in File Manager (inside `public_html/api.pawpad.in`) and check the `db_` settings |
| Anything else | Send me a screenshot of the message, but **never** of `config.php` |

## What gets copied

The file `.cpanel.yml` in the repository lists exactly what is copied:
`index.php`, `setup.php`, `config.sample.php`, `.htaccess`, the `lib` folder
and `uploads/.htaccess`. It never copies or deletes `config.php` or your
uploaded photos and PDFs.

## One time only: after the Phase 3 update

Phase 3 moves the website content and admin photos onto the server.

1. Update the server as above.
2. In **config.php**, nothing new is required. You *may* add the upload settings
   from `config.sample.php` (`upload_quota_mb` etc.) if you want different limits;
   otherwise the defaults are used: 150 MB for all uploads together, 1.5 MB per
   photo and 4 MB per PDF.
3. Open the admin panel and sign in with the email and password you created on
   `setup.php`. The old password "2017" no longer works anywhere.
4. If this computer still has website changes you made before the server existed,
   the admin panel asks **"Publish your earlier changes?"**
   - **Yes, publish them**: the changes go live for every visitor. Any photos
     inside them are uploaded to the server first.
   - **No, discard them**: the website keeps its standard content.
5. Test: change a small piece of text in **Website Content CMS**, click **Save**,
   then open the website on your phone. You should see the change within a few
   seconds (pull down to refresh).
6. Add any other staff under **Settings & Backups → Authorized Administrator Team**
   with a starting password, and give them that password in person.

## One time only: after the Phase 4 update (grooming bookings)

After updating the server, follow **Steps 2–5 in
[Phase 4 — grooming slot bookings](phase-4-grooming-bookings.md)**: add the
info@ password to `config.php`, check the calendar address, test a booking, and
add the info@ calendar to Leena's phone.

## If the website pages didn't update after a merge

The public pages and the admin panel are published by **GitHub Pages**
from the `main` branch. After each merge GitHub rebuilds them within a few
minutes. Very occasionally it skips a rebuild, and the site keeps showing the
previous version (for example an old phone number).

To check: on GitHub open the repository → **Actions** → **pages build and
deployment**. The newest run should mention your latest pull request and have
a green tick.

If it doesn't:
1. On GitHub open **Settings** → **Pages**.
2. Under **Build and deployment**, set **Branch** to `none` and click **Save**.
3. Set it back to `main` and `/ (root)` and click **Save**.
4. Wait 2–3 minutes. A new **pages build and deployment** run appears in
   **Actions**. When it has a green tick, reload the website.
