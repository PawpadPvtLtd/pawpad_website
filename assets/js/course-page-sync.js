/**
 * Course and studio-setup detail pages (course_forms/*.html) are written by hand, so this keeps
 * them in step with the admin panel: the fee, deposit, balance, duration, title and code shown on
 * the page follow what is saved in Website Content CMS, an "At a glance" box shows the CMS
 * overview when it has been changed, and application forms close when applications are off.
 */
(function () {
  const store = window.PawpadContentStore;
  if (!store) return;
  const file = decodeURIComponent(window.location.pathname.split("/").pop() || "").toLowerCase();
  const isApplication = file.indexOf("application") !== -1;
  const base = (url) => String(url || "").split("/").pop().split("?")[0].toLowerCase();
  const money = (text) => {
    const n = parseFloat(String(text || "").replace(/[^0-9.]/g, ""));
    return isNaN(n) ? null : n;
  };
  const rupees = (n) => "₹" + Math.round(n).toLocaleString("en-IN");
  const stripCode = (title) => String(title || "").replace(/\s*\([A-Z0-9]{2,12}\)\s*$/, "").trim();
  const escapeHtml = (t) => String(t || "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

  // Every item (course or consultation package) whose page is this one.
  function findItems(listKey, content) {
    const list = content && Array.isArray(content[listKey]) ? content[listKey] : [];
    return list.filter((c) => c && [c.knowMoreUrl, c.enrollUrl, c.bookUrl, c.applyUrl].some((u) => u && base(u) === file));
  }

  function replaceInPage(pairs) {
    const useful = pairs.filter(([from, to]) => from && to !== undefined && to !== null && String(from) !== String(to));
    if (!useful.length) return;
    // Longest first, so "Pawpad … Certificate (PCGEC)" is replaced before "PCGEC".
    useful.sort((a, b) => String(b[0]).length - String(a[0]).length);
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach((node) => {
      if (node.parentNode && /^(SCRIPT|STYLE|TEXTAREA)$/.test(node.parentNode.nodeName)) return;
      let text = node.nodeValue;
      useful.forEach(([from, to]) => { text = text.split(String(from)).join(String(to)); });
      if (text !== node.nodeValue) node.nodeValue = text;
    });
    useful.forEach(([from, to]) => { document.title = document.title.split(String(from)).join(String(to)); });
  }

  function glanceBox(item, def) {
    const includes = Array.isArray(item.includes) ? item.includes.filter(Boolean) : [];
    const changed = (item.desc || "") !== (def.desc || "") || JSON.stringify(includes) !== JSON.stringify(def.includes || []);
    if (!changed || document.getElementById("pawpad-glance")) return;
    const h1 = document.querySelector("h1");
    if (!h1) return;
    const box = document.createElement("div");
    box.id = "pawpad-glance";
    box.setAttribute("style", "margin:24px 0;padding:20px 22px;border-radius:16px;background:#f7f1e6;border:1px solid #e6dcc8;font-size:15px;line-height:1.6;color:#2e2e2e");
    const facts = [item.duration, item.price, item.deposit ? "Deposit " + item.deposit : ""].filter(Boolean).map(escapeHtml).join(" · ");
    box.innerHTML = '<p style="margin:0 0 8px;font-size:12px;letter-spacing:.16em;text-transform:uppercase;color:#9a7b4f;font-weight:700">At a glance</p>'
      + (facts ? '<p style="margin:0 0 8px;font-weight:700">' + facts + "</p>" : "")
      + (item.desc ? '<p style="margin:0 0 8px">' + escapeHtml(item.desc) + "</p>" : "")
      + (includes.length ? '<ul style="margin:0;padding-left:20px">' + includes.map((i) => "<li>" + escapeHtml(i) + "</li>").join("") + "</ul>" : "");
    h1.insertAdjacentElement("afterend", box);
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
      replaceInPage(pairs);
      if (!isApplication && items.length === 1) {
        glanceBox(items[0], defaults.find((d) => d && d.key === items[0].key) || {});
      }
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
        [def.price, item.price],
        [def.deposit, item.deposit],
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
