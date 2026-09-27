/**
 * Course and studio-setup detail pages (course_forms/*.html) are written by hand, so this keeps
 * them in step with the admin panel: each course's "Know More" page is built from its Know More
 * section in Website Content CMS → Courses; the fee, deposit, balance, duration, title and code
 * follow the CMS; and application forms close when applications are off.
 */
(function () {
  const store = window.PawpadContentStore;
  if (!store) return;
  const file = decodeURIComponent(window.location.pathname.split("/").pop() || "").toLowerCase();
  const isApplication = file.indexOf("application") !== -1;
  // course.html?key=… shows a course added in the admin panel (no page of its own).
  const pageKey = file === "course.html" ? String(new URLSearchParams(window.location.search).get("key") || "").toLowerCase() : "";
  const base = (url) => String(url || "").split("/").pop().split("?")[0].toLowerCase();
  const money = (text) => {
    const n = parseFloat(String(text || "").replace(/[^0-9.]/g, ""));
    return isNaN(n) ? null : n;
  };
  const rupees = (n) => "₹" + Math.round(n).toLocaleString("en-IN");
  // "32,000" typed in the admin panel is shown as "₹32,000".
  const price = (text) => (text && typeof store.formatPrice === "function" ? store.formatPrice(text) : text);
  const stripCode = (title) => String(title || "").replace(/\s*\([A-Z0-9]{2,12}\)\s*$/, "").trim();
  const escapeHtml = (t) => String(t || "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

  // Every item (course or consultation package) whose page is this one.
  function findItems(listKey, content) {
    const list = content && Array.isArray(content[listKey]) ? content[listKey] : [];
    if (pageKey) return list.filter((c) => c && String(c.key || "").toLowerCase() === pageKey);
    return list.filter((c) => c && [c.knowMoreUrl, c.enrollUrl, c.bookUrl, c.applyUrl].some((u) => u && base(u) === file));
  }

  function replaceInPage(pairs) {
    const useful = pairs.filter(([from, to]) => from && to !== undefined && to !== null && String(from) !== String(to));
    if (!useful.length) return;
    // Longest first, so "Pawpad … Certificate (PCGEC)" is replaced before "PCGEC". One pass, so
    // swapping two prices (₹20,000 → ₹35,000 and ₹35,000 → ₹40,000) never chains.
    useful.sort((a, b) => String(b[0]).length - String(a[0]).length);
    const map = new Map(useful.map(([from, to]) => [String(from), String(to)]));
    const pattern = new RegExp(Array.from(map.keys()).map((k) => k.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")).join("|"), "g");
    const swap = (text) => String(text).replace(pattern, (m) => map.get(m));
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach((node) => {
      if (node.parentNode && /^(SCRIPT|STYLE|TEXTAREA)$/.test(node.parentNode.nodeName)) return;
      const text = swap(node.nodeValue);
      if (text !== node.nodeValue) node.nodeValue = text;
    });
    // Choices such as "Online Consultation (₹20,000)" are saved with the application, so their
    // values must carry the current price too.
    document.querySelectorAll('input[type="radio"], input[type="checkbox"], input[type="hidden"], option').forEach((el) => {
      if (el.name && /^_/.test(el.name)) return;
      const value = el.getAttribute("value");
      if (value && swap(value) !== value) el.setAttribute("value", swap(value));
    });
    document.title = swap(document.title);
  }

  // "**bold**" in the admin panel becomes bold text; everything else is shown as typed.
  const rich = (text) => escapeHtml(text).replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>");
  const paragraphs = (text) => String(text || "").split(/\n\s*\n/).map((t) => t.trim()).filter(Boolean);

  /** Builds the Know More page from the course's CMS content (or a basic one from the card). */
  function renderKnowMore(item, def) {
    const box = document.getElementById("course-content");
    if (!box) return;
    const km = item.knowMore || def.knowMore || {
      heading: stripCode(item.title),
      lede: item.desc || "",
      sections: [
        (item.includes || []).length ? { heading: "What you study", items: item.includes } : null,
        { heading: "Commitment & fees", items: [item.duration, item.price ? "**" + price(item.price) + "** per student" : "", item.deposit ? price(item.deposit) + " deposit due upon acceptance to hold your seat" : ""].filter(Boolean) },
        item.note ? { heading: "Who it's for", text: item.note } : null
      ].filter(Boolean)
    };
    const apply = String(item.enrollUrl || def.enrollUrl || "").replace(/^\/?course_forms\//, "");
    const applyHref = /^(https?:)?\/\//.test(apply) || !apply ? apply : (file === "course.html" || window.location.pathname.indexOf("/course_forms/") !== -1 ? apply : "course_forms/" + apply);
    let out = "";
    if (km.eyebrow) out += '<div class="eyebrow">' + escapeHtml(km.eyebrow) + "</div>";
    out += "<h1>" + escapeHtml(km.heading || stripCode(item.title)) + "</h1>";
    if (km.breadcrumb) out += '<div class="breadcrumb">' + escapeHtml(km.breadcrumb) + "</div>";
    out += "<hr>";
    if (km.lede) out += '<p class="lede">' + rich(km.lede) + "</p>";
    (km.intro || []).forEach((t) => { out += "<p>" + rich(t) + "</p>"; });
    const sections = (km.sections || []).filter((sec) => sec && (sec.heading || sec.text || (sec.items || []).length));
    sections.forEach((sec, i) => {
      out += "<section>";
      if (sec.heading) out += "<h2>" + escapeHtml(sec.heading) + "</h2>";
      paragraphs(sec.text).forEach((t) => { out += "<p>" + rich(t) + "</p>"; });
      const items = (sec.items || []).filter((x) => String(x || "").trim());
      if (items.length) {
        const tag = sec.numbered ? "ol" : "ul";
        out += "<" + tag + ">" + items.map((x) => "<li>" + rich(x) + "</li>").join("") + "</" + tag + ">";
      }
      // Apply Now always goes to this course's own application form (not editable in the CMS).
      if (i === sections.length - 1 && applyHref) out += '<a href="' + escapeHtml(applyHref) + '" class="cta">Apply Now</a>';
      out += "</section>";
    });
    if (!sections.length && applyHref) out += '<a href="' + escapeHtml(applyHref) + '" class="cta">Apply Now</a>';
    if (km.note) out += '<div class="note">' + rich(km.note) + "</div>";
    box.innerHTML = out;
    document.title = (km.heading || stripCode(item.title)) + (item.code ? " (" + item.code + ")" : "") + " · Pawpad Academy";
  }

  function closeApplications(message) {
    const form = document.querySelector("form");
    if (!form || document.getElementById("pawpad-closed")) return;
    const note = document.createElement("div");
    note.id = "pawpad-closed";
    note.setAttribute("role", "status");
    note.setAttribute("style", "margin:28px 0;padding:24px;border-radius:16px;background:#fff4e5;border:1px solid #e0b100;font-size:16px;line-height:1.6;color:#2e2e2e");
    note.innerHTML = "<strong>Applications are closed right now.</strong><br>" + escapeHtml(message)
      + '<br><a href="https://wa.me/919148443330" style="color:#9a7b4f;font-weight:700">WhatsApp us on +91 91484 43330</a> to hear when the next batch opens.';
    form.parentNode.insertBefore(note, form);
    form.style.display = "none";
  }

  function apply() {
    const sources = [["courses", "courseList"], ["studioSetup", "packages"]];
    for (const [pageKey, listKey] of sources) {
      const content = store.get(pageKey);
      const items = findItems(listKey, content);
      if (!items.length) continue;
      const defaults = store.getDefault(pageKey)[listKey] || [];
      const pairs = [];
      items.forEach((item) => {
        const def = defaults.find((d) => d && d.key === item.key) || {};
        pairs.push(...itemPairs(item, def));
      });
      // Know More pages are built for courses only (the studio consulting page stays as written).
      // Built first, so the fee and code swaps below also reach its text.
      if (!isApplication && listKey === "courseList" && items.length === 1) {
        renderKnowMore(items[0], defaults.find((d) => d && d.key === items[0].key) || {});
      }
      replaceInPage(pairs);
      if (isApplication && pageKey === "courses" && content.allowSubmissions === false) {
        closeApplications(content.closedMessage || "We are not taking new applications for this course at the moment.");
      }
      return;
    }
  }

  function itemPairs(item, def) {
      const pairs = [
        [def.title, item.title],
        [stripCode(def.title), stripCode(item.title)],
        [def.price, price(item.price)],
        [def.deposit, price(item.deposit)],
        [def.duration, item.duration]
      ];
      const oldBalance = money(def.price) !== null && money(def.deposit) !== null ? money(def.price) - money(def.deposit) : null;
      const newBalance = money(item.price) !== null && money(item.deposit) !== null ? money(item.price) - money(item.deposit) : null;
      if (oldBalance !== null && newBalance !== null) pairs.push([rupees(oldBalance), rupees(newBalance)]);
      const defCode = def.code || String(def.key || "").toUpperCase();
      const newCode = item.code || String(item.key || "").toUpperCase();
      if (defCode && newCode && def.code) pairs.push([defCode, newCode]);
      return pairs;
  }

  Promise.resolve(store.ready).then(apply, apply);
})();
