/**
 * Blog & Vlogs: the list of articles and videos (blog.html) and one post
 * (blog/post.html?slug=… and the ready-made blog/<slug>.html pages).
 *
 * Posts come from the Pawpad server. Until the Blog is switched on in
 * Website Content CMS → Blog & Vlogs, visitors see "coming soon" and the
 * page is kept out of Google; signed-in staff see a preview instead.
 */
const { useState: useStateBlog, useEffect: useEffectBlog } = React;
const hb = React.createElement;

const BLOG_TYPE_LABEL = { article: "Article", video: "Video" };

function blogFormat() {
  return window.PawpadBlogFormat;
}

/** Address of a post. Ready-made pages exist at blog/<slug>.html once published; this one always works. */
function blogPostHref(slug) {
  return "blog/post.html?slug=" + encodeURIComponent(slug);
}

/** Sets a <meta>/<link> in the page head (title, description, canonical, robots). */
function setHeadTag(selector, create, attr, value) {
  let el = document.head.querySelector(selector);
  if (!el) {
    el = document.createElement(create.tag);
    Object.keys(create.attrs).forEach((k) => el.setAttribute(k, create.attrs[k]));
    document.head.appendChild(el);
  }
  el.setAttribute(attr, value);
}
function keepOutOfGoogle() {
  setHeadTag('meta[name="robots"]', { tag: "meta", attrs: { name: "robots" } }, "content", "noindex, nofollow");
}

function BlogTypeBadge({ kind, draft }) {
  return hb("span", { className: "blog-badges" },
    hb("span", { className: "blog-badge blog-badge-" + kind }, BLOG_TYPE_LABEL[kind] || "Post"),
    draft && hb("span", { className: "blog-badge blog-badge-draft" }, "Draft · not public"));
}

/** The picture on a card: the cover, else the video's own thumbnail, else a calm placeholder. */
function BlogCardImage({ post }) {
  const v = post.kind === "video" && blogFormat() ? blogFormat().video(post.videoUrl) : null;
  const src = post.cover || (v && v.thumb) || "";
  return hb("div", { className: "blog-card-img" + (src ? "" : " blog-card-img-empty") },
    src ? hb("img", { src, alt: post.coverAlt || post.title, loading: "lazy" }) : hb(window.PawIcon || "span", { size: 40, color: "var(--driftwood)" }),
    post.kind === "video" && hb("span", { className: "blog-play", "aria-hidden": "true" }, "▶"));
}

function BlogCard({ post }) {
  const F = blogFormat();
  const meta = post.kind === "video"
    ? "Video"
    : `${F ? F.readingMinutes(post.body || post.summary) : 1} min read`;
  return hb("article", { className: "blog-card", "data-post": post.slug },
    hb("a", { href: blogPostHref(post.slug), className: "blog-card-link" },
      hb(BlogCardImage, { post }),
      hb("div", { className: "blog-card-body" },
        hb(BlogTypeBadge, { kind: post.kind, draft: post.status === "draft" }),
        hb("h2", { className: "blog-card-title" }, post.title),
        post.summary && hb("p", { className: "blog-card-summary" }, post.summary),
        hb("p", { className: "blog-card-meta" }, [F && post.publishedAt ? F.formatDate(post.publishedAt) : "", meta].filter(Boolean).join(" · ")))));
}

function BlogPreviewBanner({ enabled }) {
  return hb("div", { className: "blog-preview-banner", role: "status" },
    hb("strong", null, "Preview for Pawpad staff."),
    enabled
      ? " Drafts are shown here only to you."
      : " The Blog isn't public yet: visitors see \"coming soon\". Switch it on in Website Content CMS → Blog & Vlogs.");
}

function BlogCta() {
  const cms = (typeof useCmsContent === "function") ? useCmsContent("blog") : {};
  return hb("section", { className: "blog-cta" },
    hb("div", { className: "container blog-cta-inner" },
      hb("div", null,
        hb("h2", { className: "h-2" }, cms.ctaTitle || "Book a calm grooming session"),
        hb("p", { className: "lead-sm" }, cms.ctaText || "Appointment-only care in Kalyan Nagar, Bengaluru, at your pet's pace.")),
      hb("a", { href: "grooming.html", className: "btn btn-primary" }, cms.ctaButton || "See grooming packages")));
}

function BlogPage() {
  const cms = (typeof useCmsContent === "function") ? useCmsContent("blog") : {};
  const [state, setState] = useStateBlog({ loading: true, enabled: false, preview: false, posts: [], error: "" });
  const [filter, setFilter] = useStateBlog("all");

  useEffectBlog(() => {
    const staff = window.PawpadApi && window.PawpadApi.hasSession();
    if (!window.PawpadApi || !window.PawpadApi.isEnabled()) {
      setState({ loading: false, enabled: false, preview: false, posts: [], error: "" });
      return;
    }
    window.PawpadApi.call("list_posts", staff ? { preview: 1 } : {}).then((r) => {
      if (r.ok) setState({ loading: false, enabled: Boolean(r.data.enabled), preview: Boolean(r.data.preview), posts: r.data.posts || [], error: "" });
      else setState({ loading: false, enabled: false, preview: false, posts: [], error: "The posts could not be loaded. Please try again in a moment." });
    });
  }, []);

  const visible = !state.loading && (state.enabled || state.preview);
  useEffectBlog(() => {
    if (!state.loading && !state.enabled) keepOutOfGoogle();
  }, [state.loading, state.enabled]);

  if (!state.loading && !visible) {
    return hb("div", { className: "page-enter" },
      hb("section", { className: "blog-hero blog-soon" },
        hb("div", { className: "container" },
          hb("p", { className: "eyebrow" }, cms.eyebrow || "Blog & Vlogs"),
          hb("h1", { className: "h-display", style: { marginTop: 20 } }, "Coming soon"),
          hb("p", { className: "lead", style: { marginTop: 22, maxWidth: "52ch" } }, "We're writing our first articles and filming short videos for pet parents. Until then, our grooming page has everything you need to book."),
          hb("a", { href: "grooming.html", className: "btn btn-primary", style: { marginTop: 28 } }, "See grooming packages"))),
      hb(BlogStyles));
  }

  const counts = { all: state.posts.length, article: state.posts.filter((p) => p.kind === "article").length, video: state.posts.filter((p) => p.kind === "video").length };
  const shown = state.posts.filter((p) => filter === "all" || p.kind === filter);

  return hb("div", { className: "page-enter" },
    state.preview && hb(BlogPreviewBanner, { enabled: state.enabled }),
    hb("section", { className: "blog-hero" },
      hb("div", { className: "container" },
        hb("p", { className: "eyebrow" }, cms.eyebrow || "Blog & Vlogs"),
        hb("h1", { className: "h-display", style: { marginTop: 20, maxWidth: "16ch" } },
          cms.title || "Notes from ",
          hb("em", { className: "italic", style: { color: "var(--driftwood)" } }, cms.titleAccent || "the studio")),
        hb("p", { className: "lead", style: { marginTop: 24, maxWidth: "58ch" } }, cms.lead || ""))),
    hb("section", { className: "blog-list" },
      hb("div", { className: "container" },
        hb("div", { className: "blog-filters", role: "tablist", "aria-label": "Show" },
          [["all", "All"], ["article", "Articles"], ["video", "Videos"]].map(([k, label]) =>
            hb("button", { key: k, type: "button", role: "tab", "aria-selected": filter === k, className: "blog-filter" + (filter === k ? " active" : ""), onClick: () => setFilter(k), "data-filter": k },
              `${label} (${counts[k]})`))),
        state.loading ? hb("p", { className: "lead-sm" }, "Loading posts…")
          : state.error ? hb("p", { className: "lead-sm", role: "alert" }, state.error)
          : shown.length === 0 ? hb("p", { className: "lead-sm blog-empty" }, filter === "video" ? "No videos yet." : filter === "article" ? "No articles yet." : "No posts yet.")
          : hb("div", { className: "blog-grid" }, shown.map((p) => hb(BlogCard, { key: p.id, post: p }))))),
    hb(BlogCta),
    hb(BlogStyles));
}

/** The video itself: YouTube and Vimeo play here; Instagram opens on Instagram. */
function BlogVideo({ post }) {
  const v = blogFormat() ? blogFormat().video(post.videoUrl) : null;
  if (!v) return null;
  if (v.provider === "instagram") {
    return hb("a", { className: "blog-insta", href: v.watchUrl, target: "_blank", rel: "noopener noreferrer" },
      post.cover ? hb("img", { src: post.cover, alt: post.coverAlt || post.title }) : null,
      hb("span", null, "▶ Watch this video on Instagram"));
  }
  return hb("div", { className: "blog-video" },
    hb("iframe", {
      src: v.embedUrl, title: post.title, loading: "lazy",
      allow: "accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share",
      allowFullScreen: true, referrerPolicy: "strict-origin-when-cross-origin"
    }));
}

function BlogPostPage() {
  const F = blogFormat();
  const embedded = (() => {
    try { const el = document.getElementById("pawpad-post"); return el ? JSON.parse(el.textContent) : null; } catch (e) { return null; }
  })();
  const slug = (embedded && embedded.slug) || new URLSearchParams(window.location.search).get("slug") || "";
  const [state, setState] = useStateBlog({ loading: !embedded, post: embedded, preview: false, missing: false });

  useEffectBlog(() => {
    if (!slug || !window.PawpadApi || !window.PawpadApi.isEnabled()) {
      if (!embedded) setState({ loading: false, post: null, preview: false, missing: true });
      return;
    }
    window.PawpadApi.call("get_post", { slug }).then((r) => {
      if (r.ok) setState({ loading: false, post: r.data.post, preview: Boolean(r.data.preview), missing: false });
      else if (!embedded) setState({ loading: false, post: null, preview: false, missing: true });
    });
  }, []);

  const post = state.post;
  useEffectBlog(() => {
    if (state.missing || state.preview) keepOutOfGoogle();
    if (!post) return;
    const title = post.seoTitle || `${post.title} | Pawpad Blog`;
    const desc = post.seoDescription || post.summary || (F ? F.plainText(post.body).slice(0, 155) : "");
    document.title = title;
    setHeadTag('meta[name="description"]', { tag: "meta", attrs: { name: "description" } }, "content", desc);
    setHeadTag('link[rel="canonical"]', { tag: "link", attrs: { rel: "canonical" } }, "href", `https://pawpad.in/blog/${post.slug}.html`);
    if (post.keywords) setHeadTag('meta[name="keywords"]', { tag: "meta", attrs: { name: "keywords" } }, "content", post.keywords);
  }, [post, state.missing, state.preview]);

  if (state.loading) return hb("div", { className: "page-enter" }, hb("section", { className: "blog-hero" }, hb("div", { className: "container" }, hb("p", { className: "lead-sm" }, "Loading…"))), hb(BlogStyles));
  if (!post) {
    return hb("div", { className: "page-enter" },
      hb("section", { className: "blog-hero blog-soon" },
        hb("div", { className: "container" },
          hb("p", { className: "eyebrow" }, "Blog & Vlogs"),
          hb("h1", { className: "h-1", style: { marginTop: 18 } }, "This post isn't available"),
          hb("p", { className: "lead", style: { marginTop: 18 } }, "It may have been moved or isn't public yet."),
          hb("a", { href: "blog.html", className: "btn btn-primary", style: { marginTop: 24 } }, "See all posts"))),
      hb(BlogStyles));
  }

  const topics = String(post.keywords || "").split(",").map((t) => t.trim()).filter(Boolean).slice(0, 8);
  const shareUrl = `https://pawpad.in/blog/${post.slug}.html`;
  return hb("div", { className: "page-enter" },
    state.preview && hb(BlogPreviewBanner, { enabled: false }),
    hb("article", { className: "blog-post" },
      hb("div", { className: "container blog-post-inner" },
        hb("a", { href: "blog.html", className: "blog-back" }, "← All posts"),
        hb(BlogTypeBadge, { kind: post.kind, draft: post.status === "draft" }),
        hb("h1", { className: "h-1 blog-post-title" }, post.title),
        hb("p", { className: "blog-post-meta" },
          [post.publishedAt && F ? F.formatDate(post.publishedAt) : "", post.author ? `By ${post.author}` : "", post.kind === "article" && F ? `${F.readingMinutes(post.body)} min read` : ""].filter(Boolean).join(" · ")),
        post.summary && hb("p", { className: "lead blog-post-summary" }, post.summary),
        post.kind === "video" ? hb(BlogVideo, { post }) : (post.cover && hb("figure", { className: "blog-post-cover" }, hb("img", { src: post.cover, alt: post.coverAlt || post.title }))),
        post.body && hb("div", { className: "blog-post-body", dangerouslySetInnerHTML: { __html: F ? F.render(post.body) : "" } }),
        topics.length > 0 && hb("p", { className: "blog-topics" }, topics.map((t) => hb("span", { key: t }, t))),
        hb("p", { className: "blog-share" }, "Share: ",
          hb("a", { href: "https://wa.me/?text=" + encodeURIComponent(post.title + " " + shareUrl), target: "_blank", rel: "noopener noreferrer" }, "WhatsApp"), " · ",
          hb("a", { href: "https://www.facebook.com/sharer/sharer.php?u=" + encodeURIComponent(shareUrl), target: "_blank", rel: "noopener noreferrer" }, "Facebook")))),
    hb(BlogCta),
    hb(BlogStyles));
}

function BlogStyles() {
  return hb("style", null, `
    .blog-preview-banner { background: var(--champagne-deep); color: var(--ink); padding: 12px var(--pad-edge); font-size: 14px; text-align: center; }
    .blog-hero { background: var(--cream-bg); padding-top: clamp(120px, 14vw, 170px); padding-bottom: 36px; }
    .blog-soon { min-height: 60vh; padding-bottom: 80px; }
    .blog-list { background: var(--cream-bg); padding-bottom: var(--pad-section); }
    .blog-filters { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 32px; }
    .blog-filter { border: 1px solid var(--eagle); background: transparent; color: var(--ink); border-radius: 999px; padding: 8px 18px; font: 600 14px var(--f-body); cursor: pointer; }
    .blog-filter.active { background: var(--ink); border-color: var(--ink); color: var(--cream-bg); }
    .blog-filter:focus-visible { outline: 2px solid var(--driftwood); outline-offset: 2px; }
    .blog-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr)); gap: 28px; }
    .blog-card { background: var(--white); border-radius: 20px; overflow: hidden; box-shadow: 0 18px 40px -30px color-mix(in oklab, var(--ink), transparent 40%); transition: transform var(--t-fast) var(--ease); }
    .blog-card:hover { transform: translateY(-3px); }
    .blog-card-link { display: flex; flex-direction: column; height: 100%; color: inherit; text-decoration: none; }
    .blog-card-link:focus-visible { outline: 2px solid var(--driftwood); outline-offset: 3px; }
    .blog-card-img { position: relative; aspect-ratio: 16 / 10; background: var(--champagne-soft); overflow: hidden; }
    .blog-card-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .blog-card-img-empty { display: grid; place-items: center; }
    .blog-play { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); width: 54px; height: 54px; border-radius: 50%; background: color-mix(in oklab, var(--ink), transparent 25%); color: var(--white); display: grid; place-items: center; font-size: 18px; padding-left: 4px; }
    .blog-card-body { padding: 20px 22px 24px; display: flex; flex-direction: column; gap: 10px; flex: 1; }
    .blog-card-title { font-family: var(--f-display); font-size: 24px; line-height: 1.2; margin: 0; color: var(--ink); text-wrap: balance; }
    .blog-card-summary { margin: 0; font-size: 15px; line-height: 1.6; color: var(--ink-soft); }
    .blog-card-meta { margin: auto 0 0; font-size: 13px; color: var(--ink-mute); }
    .blog-badges { display: flex; gap: 6px; flex-wrap: wrap; }
    .blog-badge { font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; padding: 4px 10px; border-radius: 999px; background: var(--eagle-soft); color: var(--ink); }
    .blog-badge-video { background: var(--champagne); }
    .blog-badge-draft { background: var(--ink); color: var(--cream-bg); }
    .blog-empty { padding: 24px 0; }
    .blog-hero .lead-sm, .blog-list .lead-sm, .blog-cta .lead-sm { font-size: 15px; line-height: 1.6; color: var(--ink-mute); }
    .blog-post { background: var(--cream-bg); padding-top: clamp(110px, 13vw, 150px); padding-bottom: var(--pad-section); }
    .blog-post-inner { max-width: 760px; display: flex; flex-direction: column; gap: 16px; }
    .blog-back { color: var(--driftwood-deep); font-weight: 600; text-decoration: none; }
    .blog-post-title { margin: 4px 0 0; text-wrap: balance; }
    .blog-post-meta { margin: 0; color: var(--ink-mute); font-size: 14px; }
    .blog-post-summary { margin: 4px 0 0; }
    .blog-post-cover { margin: 8px 0 0; }
    .blog-post-cover img, .blog-post-body img { width: 100%; height: auto; border-radius: 18px; display: block; }
    .blog-video { position: relative; aspect-ratio: 16 / 9; border-radius: 18px; overflow: hidden; background: var(--ink); margin-top: 8px; }
    .blog-video iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
    .blog-insta { display: flex; flex-direction: column; gap: 12px; padding: 18px; border-radius: 18px; background: var(--champagne-soft); color: var(--ink); font-weight: 600; text-decoration: none; }
    .blog-insta img { width: 100%; border-radius: 12px; }
    .blog-post-body { font-size: 17px; line-height: 1.8; color: var(--ink-soft); }
    .blog-post-body > * + * { margin-top: 18px; }
    .blog-post-body h2 { font-family: var(--f-display); font-size: 30px; line-height: 1.2; color: var(--ink); margin-top: 36px; }
    .blog-post-body h3 { font-size: 20px; color: var(--ink); margin-top: 28px; }
    .blog-post-body a { color: var(--driftwood-deep); }
    .blog-post-body ul, .blog-post-body ol { padding-left: 22px; }
    .blog-post-body blockquote { margin: 24px 0; padding: 4px 0 4px 20px; border-left: 3px solid var(--driftwood); font-style: italic; color: var(--ink); }
    .blog-post-body figcaption { font-size: 13px; color: var(--ink-mute); margin-top: 8px; }
    .blog-topics { display: flex; flex-wrap: wrap; gap: 8px; margin: 16px 0 0; }
    .blog-topics span { font-size: 13px; padding: 4px 12px; border-radius: 999px; background: var(--eagle-soft); color: var(--ink); }
    .blog-share { font-size: 14px; color: var(--ink-mute); margin: 8px 0 0; }
    .blog-share a { color: var(--driftwood-deep); font-weight: 600; }
    .blog-cta { background: var(--champagne-soft); padding-block: 56px; }
    .blog-cta-inner { display: flex; align-items: center; justify-content: space-between; gap: 24px; flex-wrap: wrap; }
    .blog-cta-inner p { margin: 8px 0 0; }
    @media (max-width: 600px) { .blog-card-title { font-size: 21px; } .blog-post-body { font-size: 16px; } }
  `);
}

Object.assign(window, { BlogPage, BlogPostPage });
