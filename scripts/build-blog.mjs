#!/usr/bin/env node
/**
 * Builds the Blog's ready-made pages for Google from the posts published in the
 * admin panel (Website Content CMS → Blog & Vlogs):
 *   - blog/<slug>.html for every published post (title, description, text and
 *     video already in the page, so search engines read it without running scripts);
 *   - the Blog part of sitemap.xml (between <!-- blog:start --> and <!-- blog:end -->).
 *
 * Run by .github/workflows/blog-pages.yml every 30 minutes. While the Blog is
 * switched off it removes any built post pages and leaves the sitemap without
 * Blog entries. If the Pawpad server can't be reached it changes nothing.
 *
 *   node scripts/build-blog.mjs                     (uses https://api.pawpad.in)
 *   PAWPAD_API=http://127.0.0.1:8089 node scripts/build-blog.mjs
 */
import { readFileSync, writeFileSync, readdirSync, unlinkSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { createRequire } from "node:module";
import path from "node:path";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const API = (process.env.PAWPAD_API || "https://api.pawpad.in").replace(/\/$/, "");
const SITE = "https://pawpad.in";
const F = createRequire(import.meta.url)(path.join(ROOT, "assets/js/blog-format.js"));
const esc = F.escapeHtml;

async function get(action, query = "") {
  const res = await fetch(`${API}/index.php?action=${action}${query}`, { headers: { Accept: "application/json" } });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.ok !== true) throw new Error(`${action}: HTTP ${res.status} ${data.error || ""}`.trim());
  return data;
}

function descriptionOf(post) {
  const d = post.seoDescription || post.summary || F.plainText(post.body);
  return d.length > 160 ? d.slice(0, 157).replace(/\s+\S*$/, "") + "…" : d;
}

/** The post as plain HTML inside #app (React replaces it with the same post when the page loads). */
function staticBody(post) {
  const v = post.kind === "video" ? F.video(post.videoUrl) : null;
  const media = v
    ? (v.embedUrl
        ? `<div class="blog-video"><iframe src="${esc(v.embedUrl)}" title="${esc(post.title)}" loading="lazy" allowfullscreen></iframe></div>`
        : `<p><a href="${esc(v.watchUrl)}">Watch this video on Instagram</a></p>`)
    : (post.cover ? `<figure class="blog-post-cover"><img src="${esc(post.cover)}" alt="${esc(post.coverAlt || post.title)}"></figure>` : "");
  return `
    <article class="blog-post"><div class="container blog-post-inner">
      <p><a href="blog.html">← All posts</a></p>
      <h1 class="h-1">${esc(post.title)}</h1>
      <p class="blog-post-meta">${esc([F.formatDate(post.publishedAt), post.author ? "By " + post.author : ""].filter(Boolean).join(" · "))}</p>
      ${post.summary ? `<p class="lead">${esc(post.summary)}</p>` : ""}
      ${media}
      <div class="blog-post-body">${F.render(post.body)}</div>
    </div></article>
  `;
}

function structuredData(post) {
  const url = `${SITE}/blog/${post.slug}.html`;
  const v = post.kind === "video" ? F.video(post.videoUrl) : null;
  const image = post.cover || (v && v.thumb) || `${SITE}/assets/img/pawpad/landing-page-cover.png`;
  const data = post.kind === "video"
    ? { "@context": "https://schema.org", "@type": "VideoObject", name: post.title, description: descriptionOf(post) || post.title, thumbnailUrl: image, uploadDate: post.publishedAt, contentUrl: v ? v.watchUrl : post.videoUrl, ...(v && v.embedUrl ? { embedUrl: v.embedUrl } : {}), url }
    : { "@context": "https://schema.org", "@type": "BlogPosting", headline: post.title, description: descriptionOf(post), image, datePublished: post.publishedAt, dateModified: post.updatedAt || post.publishedAt, url, mainEntityOfPage: url, ...(post.keywords ? { keywords: post.keywords } : {}), author: post.author ? { "@type": "Person", name: post.author } : { "@type": "Organization", name: "Pawpad" }, publisher: { "@id": `${SITE}/#business` } };
  return JSON.stringify(data).replace(/</g, "\\u003c");
}

function buildPage(template, post) {
  const url = `${SITE}/blog/${post.slug}.html`;
  const title = post.seoTitle || `${post.title} | Pawpad Blog`;
  const desc = descriptionOf(post);
  const v = post.kind === "video" ? F.video(post.videoUrl) : null;
  const image = post.cover || (v && v.thumb) || `${SITE}/assets/img/pawpad/landing-page-cover.png`;
  const attr = (html, selector, value) => html.replace(new RegExp(`(<${selector}[^>]*?(?:content|href)=")[^"]*(")`), `$1${esc(value)}$2`);
  let html = template.replace(/<title>[^<]*<\/title>/, `<title>${esc(title)}</title>`);
  html = attr(html, 'meta name="description"', desc);
  html = attr(html, 'link rel="canonical"', url);
  html = attr(html, 'meta property="og:title"', title);
  html = attr(html, 'meta property="og:description"', desc);
  html = attr(html, 'meta property="og:url"', url);
  html = attr(html, 'meta property="og:image"', image);
  html = html.replace(/<meta property="og:type" content="website" \/>/, `<meta property="og:type" content="article" />`);
  const head = [
    post.keywords ? `<meta name="keywords" content="${esc(post.keywords)}" />` : "",
    `<script type="application/ld+json">${structuredData(post)}</script>`,
    // The post itself, so the page shows at once (the admin panel's latest version replaces it when it loads).
    `<script type="application/json" id="pawpad-post">${JSON.stringify(post).replace(/</g, "\\u003c")}</script>`
  ].filter(Boolean).map((l) => "  " + l).join("\n");
  html = html.replace("  <!-- POST-HEAD -->", head);
  html = html.replace("<!-- POST-BODY -->", staticBody(post));
  return html;
}

function sitemapBlock(enabled, posts) {
  if (!enabled) return "";
  const day = (iso) => String(iso || "").slice(0, 10);
  const newest = posts.reduce((d, p) => (day(p.updatedAt) > d ? day(p.updatedAt) : d), "");
  const urls = [`  <url>\n    <loc>${SITE}/blog.html</loc>\n${newest ? `    <lastmod>${newest}</lastmod>\n` : ""}    <changefreq>weekly</changefreq>\n    <priority>0.7</priority>\n  </url>`];
  for (const p of posts) {
    urls.push(`  <url>\n    <loc>${SITE}/blog/${p.slug}.html</loc>\n    <lastmod>${day(p.updatedAt || p.publishedAt)}</lastmod>\n    <changefreq>monthly</changefreq>\n    <priority>0.6</priority>\n  </url>`);
  }
  return urls.join("\n") + "\n";
}

async function main() {
  let enabled;
  let posts;
  try {
    const content = await get("get_content");
    enabled = Boolean(content.content && content.content.blog && content.content.blog.enabled);
    posts = enabled ? (await get("list_posts", "&full=1")).posts || [] : [];
  } catch (err) {
    console.error(`The Pawpad server could not be read (${err.message}). Nothing was changed.`);
    process.exit(1);
  }
  posts = posts.filter((p) => p.status === "published" && /^[a-z0-9-]{1,80}$/.test(p.slug));

  const dir = path.join(ROOT, "blog");
  const template = readFileSync(path.join(dir, "post.html"), "utf8");
  const wanted = new Set(posts.map((p) => `${p.slug}.html`));
  let removed = 0;
  for (const f of readdirSync(dir)) {
    if (f.endsWith(".html") && f !== "post.html" && !wanted.has(f)) {
      unlinkSync(path.join(dir, f));
      removed++;
    }
  }
  for (const p of posts) writeFileSync(path.join(dir, `${p.slug}.html`), buildPage(template, p));

  const sitemapPath = path.join(ROOT, "sitemap.xml");
  const sitemap = readFileSync(sitemapPath, "utf8");
  // The whole Blog part is rebuilt each time, so running again with no changes changes nothing.
  const next = sitemap.replace(/<!-- blog:start -->[\s\S]*?<!-- blog:end -->/, () => `<!-- blog:start -->\n${sitemapBlock(enabled, posts)}  <!-- blog:end -->`);
  if (next !== sitemap) writeFileSync(sitemapPath, next);

  console.log(enabled ? `Blog is on: ${posts.length} post page(s) built, ${removed} old page(s) removed.` : `Blog is switched off: no post pages (${removed} removed), no Blog entries in the sitemap.`);
}

main();
