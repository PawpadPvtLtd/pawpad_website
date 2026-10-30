# Getting pawpad.in into Google (Search Console)

The website is ready for Google: every public page has a title, a description and
its address, the sitemap lists them, and the business details (address, hours,
phone) and the courses are described in a form Google reads directly.

What is left is telling Google about the site. This is done **once**, by someone
who can sign in to Pawpad's Google account and to cPanel. It takes about 15 minutes,
then Google needs a few days to a few weeks to show the pages.

---

## Step 1 — Add the site to Search Console

1. Go to **https://search.google.com/search-console** and sign in with Pawpad's
   Google account (the one that owns the Google Business Profile, if there is one).
2. Click **Add property**, choose **Domain** (the left box), type `pawpad.in` and
   click **Continue**.
3. Google shows a **TXT record** that starts with `google-site-verification=`.
   Click **Copy**. Leave this window open.

## Step 2 — Add the record in cPanel

1. Sign in to cPanel and open **Zone Editor** (under *Domains*).
2. Next to **pawpad.in**, click **Manage**, then **+ Add Record** → **Add TXT Record**.
3. **Name:** `pawpad.in.` (with the dot at the end, as cPanel shows it).
   **Record:** paste the text you copied (`google-site-verification=…`).
4. Click **Save Record**.

## Step 3 — Verify

Back in Search Console, click **Verify**. If it says the record isn't found yet,
wait 15–30 minutes and click **Verify** again (DNS changes take a little while).

## Step 4 — Submit the sitemap

1. In Search Console, open **Sitemaps** (left menu).
2. Type `sitemap.xml` after `https://pawpad.in/` and click **Submit**.
   The status should become **Success** and show 15 pages.

## Step 5 — Ask Google to look at each page now

Open **URL inspection** (search box at the top), paste each address below, wait
for the result, then click **Request indexing**. Google allows about 10 a day, so
this can be split over two days.

```
https://pawpad.in/
https://pawpad.in/grooming.html
https://pawpad.in/boarding.html
https://pawpad.in/courses.html
https://pawpad.in/studio-setup.html
https://pawpad.in/about.html
https://pawpad.in/contact.html
https://pawpad.in/myotherapy.html
https://pawpad.in/policies.html
https://pawpad.in/course_forms/pawpad-essentials-dog-page.html
https://pawpad.in/course_forms/pawpad-essentials-cat-page.html
https://pawpad.in/course_forms/pawpad-practitioner-dog-page.html
https://pawpad.in/course_forms/pawpad-practitioner-cat-page.html
https://pawpad.in/course_forms/pawpad-foundations-page.html
https://pawpad.in/course_forms/pawpad-studio-consulting-page.html
```

New blog posts (Phase 2) are added to the sitemap automatically; request indexing
for an important post the same way.

## Optional — Bing

At **https://www.bing.com/webmasters**, choose **Import from Google Search Console**.
Bing copies the site and sitemap, with no extra verification.

---

## What Google is told, and where it is kept

| What | Where it lives |
|---|---|
| Page titles and descriptions | In each page file (`*.html`, `course_forms/*-page.html`) |
| Business details: address, phone, hours, social pages | `index.html` and `contact.html` (the `application/ld+json` block). Update both if the address, phone or hours change |
| Course name, code and fee | Each course page; the code and fee follow Website Content CMS automatically |
| Pages kept out of Google | Application forms, the thank-you page, `course.html` and `admin.html` (marked *noindex*) |
| Page list for Google | `sitemap.xml` |
| Missing pages | `404.html` (shown for any address that doesn't exist) |
