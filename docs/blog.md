# Blog & Vlogs: staff guide

Everything is in the admin panel: **Website Content CMS → Blog & Vlogs**.

The Blog is **live** (switched on in November 2026): "Blog" is in the menu and
footer, and every published post shows on pawpad.in/blog.html. Drafts are never shown.

It can be hidden again with the switch **Show the Blog on the website** (untick, then
**Save Live Changes**). While it is off:
- visitors who open pawpad.in/blog.html see "Coming soon";
- there is no "Blog" link in the menu or footer;
- Google is told to skip the page, and the Blog is not in the sitemap;
- you can still see every post (drafts included) when you are signed in to the admin panel in the same browser. A "Preview for Pawpad staff" bar shows at the top.

## Write an article

1. Click **+ New article**.
2. Fill in the **Title** and a **Short summary** (one or two sentences; it shows on the Blog page and in Google).
3. Add a **Cover photo** and describe it in a few words (for example "A shih tzu relaxing after a bath").
4. Write the introduction (or the whole article) in **Article text**. Simple formatting:

   | Type this | You get |
   |---|---|
   | `## Heading` | a heading |
   | `### Smaller heading` | a smaller heading |
   | `**bold**` | **bold** |
   | `*italic*` | *italic* |
   | `- point` | a bullet point |
   | `1. point` | a numbered point |
   | `> words` | a quote |
   | `[text](https://…)` | a link |

   Leave a blank line between paragraphs. To put a photo inside the text, click **+ Add a photo**, then change "Describe the photo here" to a short description.
5. **More sections (optional):** for a longer article, click **+ Add a section** under the text.
   Each section has its own **Heading**, **Photo** (with a short description), and **Text**
   (the same simple formatting as above). Add as many as you need; **↑** and **↓** change the
   order and **Remove** deletes a section. Empty sections are left out. On the website the
   sections follow the introduction text, in order.
6. Click **Preview** to see how it will look.
7. Add **Keywords & phrases**: what someone would type into Google to find this, separated by commas.
8. Click **Save draft** (visitors don't see it) or **Publish**.

Article text is **justified** (straight edges on both sides) automatically. Headings and
photo descriptions stay as they are.

## Add a video (vlog)

1. Upload the video to YouTube (recommended), Vimeo or Instagram first. The website can't hold video files itself.
2. Click **+ New video** and paste the video's link (it starts with `https://`). A line under it says "✓ YouTube video recognised" (or Vimeo / Instagram). If it turns red, the link isn't a YouTube, Vimeo or Instagram video link.
3. Add a title and a summary. YouTube videos use their own thumbnail, so a cover photo is optional.
4. Click **Publish**.

YouTube and Vimeo videos play on the Pawpad page. Instagram videos show a "Watch on Instagram" button, because Instagram doesn't let other websites play them reliably.

## Change or remove a post

- **Edit** opens the post again. On a published post, **Save & keep published** saves your changes, and **Unpublish (back to draft)** hides it.
- **Delete** removes the post for good, after you confirm. A video is only removed from the website; it stays on YouTube.
- **View ↗** opens the post as visitors see it.

## Google pages

Within about 30 minutes of publishing a ready-made page is created for each published post (`pawpad.in/blog/<post-name>.html`) and added to the sitemap, so Google can find it. Until then the post is already visible on the Blog page.


## For the developer

- Posts are stored in the `blog_posts` table (schema version 9, created automatically on deploy). Sections are a JSON list in its `sections` column (schema 14): `{heading, image, imageAlt, text}`, at most 40. `assets/js/blog-format.js` (`renderSections`) shows them on the Blog page and in the built pages.
- `scripts/build-blog.mjs` builds `blog/<slug>.html` and the Blog part of `sitemap.xml` (between `<!-- blog:start -->` and `<!-- blog:end -->`) from `api.pawpad.in`. When the Blog is switched off it removes the built pages. If the server can't be reached it changes nothing.
- `.github/workflows/blog-pages.yml` runs it every 30 minutes, and can also be started by hand: GitHub → Actions → **Blog pages** → **Run workflow**. It commits to `main`, so it needs permission to push there (see "Workflow permissions" under Settings → Actions → General). GitHub pauses scheduled workflows after 60 days with no activity in the repository; start it by hand once to turn it back on.
- `blog/post.html?slug=<slug>` shows any post straight away, before its page has been built.
