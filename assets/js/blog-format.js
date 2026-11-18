/**
 * Blog & Vlogs: turns the text written in the admin panel into a formatted post,
 * and turns a YouTube / Vimeo / Instagram link into a player.
 *
 * Writing format (shown as a cheat sheet in the admin panel):
 *   ## Heading            ### Smaller heading
 *   **bold**   *italic*   [link text](https://…)
 *   - bullet point        1. numbered point
 *   > a quote             ![photo description](https://…/photo.webp)
 *   A blank line starts a new paragraph.
 *
 * Everything is escaped first, so nothing typed can add code to the page.
 * Used by the website (window.PawpadBlogFormat) and by scripts/build-blog.mjs (Node).
 */
(function (root, factory) {
  if (typeof module === "object" && module.exports) module.exports = factory();
  else root.PawpadBlogFormat = factory();
})(typeof self !== "undefined" ? self : this, function () {
  const escapeHtml = (t) => String(t == null ? "" : t).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

  // Links and images may only point to web addresses or the site's own files.
  const safeUrl = (url) => {
    const u = String(url || "").trim();
    if (/^https?:\/\//i.test(u)) return u;
    if (/^(\/|\.\.\/|assets\/|[a-z0-9-]+\.html)/i.test(u) && !/^\/\//.test(u)) return u;
    return "";
  };

  /** **bold**, *italic*, [text](url) inside one line of (already escaped) text. */
  function inline(text) {
    let out = escapeHtml(text);
    out = out.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (m, label, url) => {
      const href = safeUrl(url.replace(/&amp;/g, "&"));
      if (!href) return label;
      const external = /^https?:\/\//i.test(href) && !/^https?:\/\/(www\.)?pawpad\.in/i.test(href);
      return `<a href="${escapeHtml(href)}"${external ? ' target="_blank" rel="noopener noreferrer"' : ""}>${label}</a>`;
    });
    out = out.replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>");
    out = out.replace(/(^|[^*])\*([^*\s][^*]*?)\*(?!\*)/g, "$1<em>$2</em>");
    return out;
  }

  /** The whole post body as HTML. */
  function render(text) {
    const lines = String(text || "").replace(/\r\n/g, "\n").split("\n");
    const out = [];
    let para = [];
    let list = null; // { tag: "ul" | "ol", items: [] }
    const flushPara = () => {
      if (para.length) out.push(`<p>${para.map(inline).join("<br>")}</p>`);
      para = [];
    };
    const flushList = () => {
      if (list) out.push(`<${list.tag}>${list.items.map((i) => `<li>${inline(i)}</li>`).join("")}</${list.tag}>`);
      list = null;
    };
    for (const raw of lines) {
      const line = raw.trimEnd();
      let m;
      if (!line.trim()) { flushPara(); flushList(); continue; }
      if ((m = line.match(/^(#{2,3})\s+(.+)$/))) {
        flushPara(); flushList();
        const tag = m[1].length === 2 ? "h2" : "h3";
        out.push(`<${tag}>${inline(m[2])}</${tag}>`);
        continue;
      }
      if ((m = line.match(/^!\[([^\]]*)\]\(([^)\s]+)\)\s*$/))) {
        flushPara(); flushList();
        const src = safeUrl(m[2]);
        if (src) out.push(`<figure><img src="${escapeHtml(src)}" alt="${escapeHtml(m[1])}" loading="lazy">${m[1] ? `<figcaption>${escapeHtml(m[1])}</figcaption>` : ""}</figure>`);
        continue;
      }
      if ((m = line.match(/^>\s?(.*)$/))) {
        flushPara(); flushList();
        out.push(`<blockquote><p>${inline(m[1])}</p></blockquote>`);
        continue;
      }
      if ((m = line.match(/^\s*[-*]\s+(.+)$/)) || (m = line.match(/^\s*\d+[.)]\s+(.+)$/))) {
        flushPara();
        const tag = /^\s*\d/.test(line) ? "ol" : "ul";
        if (list && list.tag !== tag) flushList();
        if (!list) list = { tag, items: [] };
        list.items.push(m[1]);
        continue;
      }
      flushList();
      para.push(line.trim());
    }
    flushPara(); flushList();
    return out.join("\n");
  }

  /** Plain text (for descriptions and reading time). */
  function plainText(text) {
    return String(text || "")
      .replace(/!\[[^\]]*\]\([^)]*\)/g, "")
      .replace(/\[([^\]]+)\]\([^)]*\)/g, "$1")
      .replace(/^#{2,3}\s+/gm, "").replace(/^>\s?/gm, "").replace(/^\s*([-*]|\d+[.)])\s+/gm, "")
      .replace(/\*\*?/g, "").replace(/\s+/g, " ").trim();
  }

  function readingMinutes(text) {
    const words = plainText(text).split(" ").filter(Boolean).length;
    return Math.max(1, Math.round(words / 200));
  }

  /**
   * A video link as { provider, embedUrl, watchUrl, thumb }. Instagram can't be
   * played inside another site reliably, so it gets a "Watch on Instagram" link instead.
   */
  function video(url) {
    const u = String(url || "").trim();
    let m;
    if ((m = u.match(/^https:\/\/(?:www\.|m\.)?youtube\.com\/(?:watch\?(?:.*&)?v=|shorts\/|embed\/|live\/)([A-Za-z0-9_-]{6,20})/)) ||
        (m = u.match(/^https:\/\/youtu\.be\/([A-Za-z0-9_-]{6,20})/))) {
      return { provider: "youtube", id: m[1], embedUrl: `https://www.youtube-nocookie.com/embed/${m[1]}?rel=0`, watchUrl: `https://www.youtube.com/watch?v=${m[1]}`, thumb: `https://i.ytimg.com/vi/${m[1]}/hqdefault.jpg` };
    }
    if ((m = u.match(/^https:\/\/(?:www\.|player\.)?vimeo\.com\/(?:video\/)?(\d{5,12})/))) {
      return { provider: "vimeo", id: m[1], embedUrl: `https://player.vimeo.com/video/${m[1]}?dnt=1`, watchUrl: `https://vimeo.com/${m[1]}`, thumb: "" };
    }
    if ((m = u.match(/^https:\/\/(?:www\.)?instagram\.com\/(?:reel|reels|p|tv)\/([A-Za-z0-9_-]+)/))) {
      return { provider: "instagram", id: m[1], embedUrl: "", watchUrl: `https://www.instagram.com/reel/${m[1]}/`, thumb: "" };
    }
    return null;
  }

  /** "12 November 2026". */
  function formatDate(iso) {
    if (!iso) return "";
    const d = new Date(iso);
    if (isNaN(d)) return "";
    return d.toLocaleDateString("en-IN", { day: "numeric", month: "long", year: "numeric", timeZone: "Asia/Kolkata" });
  }

  return { escapeHtml, render, plainText, readingMinutes, video, formatDate, safeUrl };
});
