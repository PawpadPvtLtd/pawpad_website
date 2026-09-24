# Phase 2 — setting up the Pawpad server (api.pawpad.in)

This guide puts the small Pawpad "API" program on your existing cPanel hosting
(InteractiveDNS). Once it's running:

- course applications are saved on your server, not in the applicant's browser;
- every application gets a real, sequential ID (PCGEC - 001, PCGEC - 002, ...);
- the admin panel shows every application, from any computer;
- interview and approval emails reach the candidate, sent from **courses@pawpad.in**;
- the old admin password "2017" stops working. You sign in with your own email and password.

Until you finish these steps, the website keeps working exactly as it does today.

**Time needed:** about 30–45 minutes.
**You need:** your cPanel login, and the `pawpad-api.zip` file.

> **Passwords:** you will create or type three passwords below: the database
> password, the courses@ mailbox password and your new admin password. Type them
> only into cPanel or the setup page. Never paste them into a chat, an email or GitHub.
> Save them in a password manager, or somewhere safe offline.

---

## Step 1 — Create the web address api.pawpad.in

1. Sign in to cPanel.
2. In the search box at the top, type **Domains** and open **Domains**.
   On older cPanel versions it's called **Subdomains**.
3. Click **Create A New Domain**.
4. In **Domain**, type `api.pawpad.in`.
5. **Untick** "Share document root" if you see it.
   Leave the document root it suggests (usually `api.pawpad.in`). Write this folder name down.
6. Click **Submit**.

cPanel adds the DNS record for you, because pawpad.in's DNS is managed in this cPanel.

## Step 2 — Turn on the padlock (HTTPS) for api.pawpad.in

1. In cPanel, search for **SSL/TLS Status** and open it.
2. Tick `api.pawpad.in` and click **Run AutoSSL**.
3. Wait a few minutes, then refresh the page. `api.pawpad.in` should show a green padlock.
   It can take up to an hour. You can carry on with steps 3–6 while you wait.

## Step 3 — Choose the PHP version

1. In cPanel, search for **MultiPHP Manager** (or **Select PHP Version**) and open it.
2. Tick `api.pawpad.in` and choose **PHP 8.1** or newer (8.2 or 8.3 are fine).
3. Click **Apply**.

## Step 4 — Create the database

1. In cPanel, search for **MySQL Database Wizard** and open it.
2. **Step 1 — Create a database:** type `pawpad` and click **Next Step**.
   cPanel adds your account name in front, e.g. `abcuser_pawpad`. Write down the **full** name.
3. **Step 2 — Create database user:** username `pawpadapi`.
   Click **Password Generator**, tick "I have copied this password in a safe place", then click **Use Password**.
   Save that password somewhere safe. Click **Create User**.
   Write down the **full** username, e.g. `abcuser_pawpadapi`.
4. **Step 3 — Add user to the database:** tick **ALL PRIVILEGES**, then **Next Step**.

## Step 5 — Find the courses@ mailbox settings

1. In cPanel, open **Email Accounts**.
2. Next to `courses@pawpad.in`, click **Connect Devices**.
3. Under **Secure SSL/TLS Settings**, note the **Outgoing Server** (usually `mail.pawpad.in`) and its **SMTP Port** (usually `465`).
4. You also need the courses@ mailbox password. If you don't know it, click **Manage** next to `courses@pawpad.in` and set a new one.
   If you do, remember to update it on any phone or computer that reads the courses@ mailbox.

## Step 6 — Upload the files

1. In cPanel, open **File Manager**.
2. Click **Settings** (top right), tick **Show Hidden Files (dotfiles)**, and click **Save**.
3. Open the folder from Step 1 (e.g. `api.pawpad.in`).
4. Click **Upload**, choose `pawpad-api.zip`, and wait until it says 100%. Then go back to File Manager.
5. Right-click `pawpad-api.zip` → **Extract** → **Extract Files**.
6. You should now see `index.php`, `setup.php`, `config.sample.php`, `.htaccess` and a `lib` folder directly inside `api.pawpad.in`.
   Delete `pawpad-api.zip` (right-click → **Delete**).

## Step 7 — Fill in the settings file

1. In File Manager, right-click `config.sample.php` → **Copy**. For the new name type `config.php` (same folder), then click **Copy File(s)**.
2. Right-click `config.php` → **Edit** → **Edit**.
3. Change these lines. Keep the quote marks `'...'` around each value.

   | Setting | What to type |
   |---|---|
   | `db_name` | full database name from Step 4 (e.g. `abcuser_pawpad`) |
   | `db_user` | full database username from Step 4 (e.g. `abcuser_pawpadapi`) |
   | `db_password` | the database password from Step 4 |
   | `smtp_host` / `smtp_port` | the Outgoing Server and port from Step 5 |
   | `smtp_password` | the courses@ mailbox password |
   | `setup_key` | any long phrase you make up, at least 16 characters, e.g. `blue-kettle-mango-river-42`. You'll need it once, in Step 8 |

   If you open the admin panel from an address other than `pawpad.in`, `www.pawpad.in` or
   `pawpadpvtltd.github.io`, add that address to `allowed_origins` too.
4. Click **Save Changes**.

## Step 8 — Create your admin login

1. Open **https://api.pawpad.in/setup.php** in your browser.
2. Fill in:
   - **Setup key:** the phrase you typed in Step 7.
   - **Admin email:** e.g. `pawpadpetstylist@gmail.com`.
   - **New admin password:** at least 10 characters. Use a new password, not "2017".
3. Click **Create tables & save admin**. You should see a green "Done" message.
4. Go back to File Manager, edit `config.php` again, and change the setup key back to empty:
   `'setup_key' => '',` then **Save Changes**.
5. Reload `https://api.pawpad.in/setup.php`. It should now say "This page is switched off."

To add another admin, or if you forget your password: put a setup key back in
`config.php`, repeat this step with that email address, then empty the setup key again.

## Step 9 — Check that it works

1. Open **https://api.pawpad.in/index.php?action=health**
   You should see: `{"ok":true,"database":true,"email":true}`
   - `"email":false` → check the `smtp_` settings in `config.php`.
   - An error about the database → check the `db_` settings.
2. Open **https://api.pawpad.in/config.php**. You should get **Forbidden** or a blank page, never your settings.
3. On the website, fill in a course application with your own email address.
   The confirmation page should show an ID like `PCGEC - 001`.
4. Open the admin panel and sign in with the email and password from Step 8.
   Open **Course Applications**; your test application should be there.
5. Click **Inspect & Approve**, pick an interview date and click **Schedule & Send Invite**.
   The message should say the email was sent **from courses@pawpad.in**.
   Check your inbox (and spam folder); courses@ gets a copy too.
6. Tick your test application, click **Decline**, then delete it.

## If something goes wrong

| What you see | What to do |
|---|---|
| The admin panel still accepts "2017" | The website can't reach the server yet. Check Step 9.1 and that `api.pawpad.in` has a padlock (Step 2) |
| "Too many attempts" | Wait 15 minutes and try again |
| The email isn't sent | Check the courses@ password and outgoing server in `config.php` (Step 5) |
| Anything else | Send me a screenshot of the error, but **not** of `config.php` |

Applications that were saved in visitors' browsers before this change can't be
moved to the server: they only ever existed on those people's computers.
