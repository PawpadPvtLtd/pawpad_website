const { useState: useStateS, useEffect: useEffectS } = React;

function StudioSetupHero({ onBook }) {
  const cms = (typeof useCmsContent === "function")
    ? useCmsContent("studioSetup")
    : (window.PawpadContentStore ? window.PawpadContentStore.get("studioSetup") : {});

  return React.createElement(
    "section",
    { className: "ss-hero" },
    React.createElement(
      "div",
      { className: "container ss-hero-grid" },
      React.createElement(
        "div",
        { className: "ss-hero-text" },
        React.createElement("p", { className: "eyebrow reveal in" }, cms.eyebrow || "Studio Setup & Business Consulting"),
        React.createElement(
          "h1",
          { className: "h-display reveal in ss-title", style: { marginTop: 24, maxWidth: "16ch" } },
          cms.title || "Planning your ",
          React.createElement("br", null),
          React.createElement("em", { className: "italic", style: { color: "var(--driftwood)" } }, cms.titleAccent !== undefined ? cms.titleAccent : "grooming space?")
        ),
        React.createElement(
          "p",
          { className: "lead reveal in", style: { marginTop: 28, maxWidth: "54ch" } },
          cms.heroLead || "Get layout, equipment and budget guidance from PawPad, where many working studio owners got their start — not a generic checklist."
        ),
        React.createElement(
          "p",
          { className: "ss-intro-sub reveal in", style: { marginTop: 18, maxWidth: "54ch", color: "var(--ink-soft)", fontSize: "17px", lineHeight: "1.65" } },
          cms.introText || "Whether you're setting up your first grooming space or reworking an existing one, the equipment you choose and the way you lay out your studio shape everything downstream — how efficiently you can work, how safe it is for you and the animals, and how much you spend fixing avoidable mistakes later."
        ),
        React.createElement(
          "div",
          { className: "ss-hero-actions reveal in", style: { display: "flex", gap: "14px", marginTop: "36px", flexWrap: "wrap" } },
          React.createElement(
            "a",
            {
              href: "#packages",
              className: "btn btn-primary",
              onClick: (e) => {
                e.preventDefault();
                const el = document.getElementById("packages");
                if (el) el.scrollIntoView({ behavior: "smooth", block: "start" });
              }
            },
            "Explore Packages ",
            React.createElement("span", { style: { marginLeft: 4 } }, "↓")
          ),
          React.createElement(
            "a",
            {
              href: "course_forms/pawpad-application-consulting-gssc.html",
              className: "btn btn-ghost"
            },
            "Book Consultation ",
            React.createElement(window.Arrow || Arrow, null)
          )
        )
      ),
      React.createElement(
        "div",
        { className: "ss-hero-visual reveal in" },
        React.createElement(
          "div",
          { className: "ss-hero-img-card" },
          React.createElement("img", {
            src: cms.heroImage || "assets/img/pawpad/studio-setup-overview.webp",
            alt: "Pawpad Grooming Studio Space Layout",
            fetchpriority: "high",
            decoding: "async",
            onError: (e) => { if (window.handleImgError) window.handleImgError(e, "assets/img/pawpad/studio-setup-overview.webp"); }
          }),
          React.createElement(
            "span",
            { className: "ss-hero-caption" },
            "Practical Setup · Real Experience"
          )
        )
      )
    ),
    React.createElement(
      "style",
      null,
      `
        .ss-hero { padding: 180px 0 60px; background: var(--cream-bg); }
        .ss-hero-grid { display: grid; grid-template-columns: 1.1fr 1fr; gap: 64px; align-items: start; }
        .ss-title {
          color: var(--ink);
          margin: 0;
        }
        .ss-title em {
          color: var(--driftwood);
          font-style: italic;
        }
        .ss-hero-visual {
          position: relative;
          margin-top: 10px;
        }
        .ss-hero-img-card {
          position: relative;
          border-radius: 28px;
          overflow: hidden;
          background: #ffffff;
          box-shadow: 0 20px 48px -16px rgba(0,0,0,0.08);
          border: 1px solid color-mix(in oklab, var(--ink), transparent 90%);
        }
        .ss-hero-img-card img {
          width: 100%;
          height: auto;
          aspect-ratio: 4/3;
          object-fit: cover;
          display: block;
        }
        .ss-hero-caption {
          position: absolute;
          left: 16px;
          bottom: 16px;
          background: color-mix(in oklab, var(--cream-bg), transparent 12%);
          backdrop-filter: blur(8px);
          -webkit-backdrop-filter: blur(8px);
          padding: 6px 14px;
          border-radius: 999px;
          font-size: 12px;
          font-weight: 600;
          letter-spacing: .04em;
          color: var(--ink);
          box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .ss-hero-actions .btn-ghost {
          background: transparent;
          color: var(--ink);
          border: 1.5px solid color-mix(in oklab, var(--ink), transparent 65%);
          transition: all var(--t-fast) var(--ease);
        }
        .ss-hero-actions .btn-ghost:hover {
          background: var(--champagne-soft);
          border-color: var(--ink);
          color: var(--ink);
        }
        .ss-hero-actions .btn-ghost:hover .arr {
          transform: translateX(3px);
        }
        @media (max-width: 960px) {
          .ss-hero { padding: 140px 0 50px; }
          .ss-hero-grid { grid-template-columns: 1fr; gap: 40px; }
          .ss-title em { white-space: normal !important; }
        }
      `
    )
  );
}

function StudioAudienceAndValue() {
  const cms = (typeof useCmsContent === "function")
    ? useCmsContent("studioSetup")
    : (window.PawpadContentStore ? window.PawpadContentStore.get("studioSetup") : {});

  const whoList = (cms.whoThisIsFor && Array.isArray(cms.whoThisIsFor)) ? cms.whoThisIsFor : [
    "Anyone planning to open a grooming studio, whether your first or a new location",
    "Groomers reworking an existing space that isn't working well",
    "Both dog and cat setups — the equipment and layout needs differ meaningfully between the two, and both are covered"
  ];

  const whatList = (cms.whatYouGet && Array.isArray(cms.whatYouGet)) ? cms.whatYouGet : [
    {
      title: "Equipment guidance",
      desc: "What you actually need for your setup and species mix, what you can skip, and a realistic budget estimate."
    },
    {
      title: "Space & layout recommendations",
      desc: "How to arrange your available space for workflow, safety, and animal comfort."
    },
    {
      title: "Battle-tested insights",
      desc: "Every recommendation grounded in what's actually been used and tested in a working studio, not a generic vendor list."
    }
  ];

  return React.createElement(
    "section",
    { className: "ss-value-section" },
    React.createElement(
      "div",
      { className: "container" },
      React.createElement(
        "div",
        { className: "ss-value-grid" },
        // Left: Who this is for
        React.createElement(
          "div",
          { className: "ss-value-card reveal" },
          React.createElement("p", { className: "eyebrow" }, "AUDIENCE & FIT"),
          React.createElement("h2", { className: "h-2", style: { marginTop: 12, marginBottom: 20 } }, "Who this is for"),
          React.createElement(
            "ul",
            { className: "ss-bullet-list" },
            whoList.map((item, idx) => React.createElement(
              "li",
              { key: idx },
              React.createElement(
                "span",
                { className: "ss-check-icon" },
                React.createElement(
                  "svg",
                  { width: 14, height: 14, viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", strokeWidth: 3, strokeLinecap: "round", strokeLinejoin: "round" },
                  React.createElement("polyline", { points: "20 6 9 17 4 12" })
                )
              ),
              React.createElement("span", null, typeof item === "string" ? item : (item.text || item.title))
            ))
          )
        ),
        // Right: What you get
        React.createElement(
          "div",
          { className: "ss-value-card reveal", style: { transitionDelay: "100ms" } },
          React.createElement("p", { className: "eyebrow" }, "DELIVERABLES & VALUE"),
          React.createElement("h2", { className: "h-2", style: { marginTop: 12, marginBottom: 20 } }, "What you get"),
          React.createElement(
            "div",
            { className: "ss-get-list" },
            whatList.map((item, idx) => React.createElement(
              "div",
              { key: idx, className: "ss-get-item" },
              React.createElement(
                "div",
                { className: "ss-get-icon" },
                "0" + (idx + 1)
              ),
              React.createElement(
                "div",
                null,
                React.createElement("h4", { className: "ss-get-title" }, typeof item === "string" ? item : item.title),
                item.desc && React.createElement("p", { className: "ss-get-desc" }, item.desc)
              )
            ))
          )
        )
      )
    ),
    React.createElement(
      "style",
      null,
      `
        .ss-value-section { padding: 60px 0 70px; background: var(--champagne-soft); }
        .ss-value-grid { display: grid; grid-template-columns: 1fr 1.05fr; gap: 32px; }
        .ss-value-card {
          background: #ffffff;
          border-radius: 24px;
          padding: 40px 36px;
          border: 1px solid color-mix(in oklab, var(--ink), transparent 92%);
          box-shadow: 0 4px 20px rgba(0,0,0,0.02);
        }
        .ss-bullet-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 16px; }
        .ss-bullet-list li { display: flex; align-items: flex-start; gap: 12px; font-size: 15.5px; line-height: 1.6; color: var(--ink); }
        .ss-check-icon {
          flex-shrink: 0;
          margin-top: 4px;
          width: 22px;
          height: 22px;
          border-radius: 50%;
          background: color-mix(in oklab, var(--driftwood), transparent 82%);
          color: var(--driftwood-deep);
          display: inline-flex;
          align-items: center;
          justify-content: center;
        }
        .ss-get-list { display: flex; flex-direction: column; gap: 20px; }
        .ss-get-item { display: flex; align-items: flex-start; gap: 16px; }
        .ss-get-icon {
          flex-shrink: 0;
          width: 34px;
          height: 34px;
          border-radius: 10px;
          background: var(--champagne);
          color: var(--driftwood-deep);
          font-family: var(--f-display);
          font-weight: 600;
          font-size: 14px;
          display: flex;
          align-items: center;
          justify-content: center;
        }
        .ss-get-title { font-family: var(--f-body); font-size: 16px; font-weight: 700; color: var(--ink); margin: 0 0 4px; }
        .ss-get-desc { font-size: 14.5px; color: var(--ink-soft); line-height: 1.55; margin: 0; }
        @media (max-width: 900px) {
          .ss-value-grid { grid-template-columns: 1fr; gap: 24px; }
          .ss-value-card { padding: 30px 24px; }
        }
      `
    )
  );
}

function StudioPackages({ onBook, onAddToCart }) {
  const cms = (typeof useCmsContent === "function")
    ? useCmsContent("studioSetup")
    : (window.PawpadContentStore ? window.PawpadContentStore.get("studioSetup") : {});

  const packages = (cms.packages && Array.isArray(cms.packages)) ? cms.packages : [
    {
      key: "studio-consulting-online",
      title: "Option 1: Online Consultation",
      tag: "Remote Guidance",
      price: "₹20,000",
      priceNum: 20000,
      duration: "2 Video Calls",
      desc: "Two video calls plus a written equipment and space brief, based on your plans, photos, or floor plan.",
      includes: [
        "Two 1-on-1 consultation calls",
        "Written equipment list & budget estimate",
        "Space and layout recommendations"
      ],
      note: "Ideal for remote guidance on budgets, layout, and essential gear.",
      ctaText: "Book Online Consultation",
      knowMoreUrl: "course_forms/pawpad-studio-consulting-page.html",
      applyUrl: "course_forms/pawpad-application-consulting-gssc.html?format=online"
    },
    {
      key: "studio-consulting-in-person",
      title: "Option 2: In-Person Studio Visit",
      tag: "On-Site Assessment",
      price: "₹35,000",
      priceUnit: "per day",
      priceNum: 35000,
      duration: "Full Day On-Site",
      desc: "A full day on-site, assessing your actual space in person before giving recommendations.",
      includes: [
        "Full-day on-site visit",
        "Written equipment list & budget estimate",
        "Space and layout recommendations"
      ],
      note: "Travel and accommodation billed separately, at actuals.",
      ctaText: "Book In-Person Visit",
      knowMoreUrl: "course_forms/pawpad-studio-consulting-page.html",
      applyUrl: "course_forms/pawpad-application-consulting-gssc.html?format=in-person"
    }
  ];

  const handleAddToCartClick = (pkg) => {
    const item = {
      id: pkg.key,
      title: pkg.title,
      category: "Studio Setup Consulting",
      price: parseFloat(String(pkg.price || "").replace(/[^0-9.]/g, "")) || pkg.priceNum || 20000,
      priceDisplay: pkg.price + (pkg.priceUnit ? ` / ${pkg.priceUnit}` : ""),
      desc: pkg.desc,
      img: "assets/img/pawpad/studio-setup-overview.webp",
      requiresPetInfo: false
    };
    if (typeof onAddToCart === "function") {
      onAddToCart(item);
    } else if (window.addToCart) {
      window.addToCart(item);
    }
  };

  return React.createElement(
    "section",
    { id: "packages", className: "ss-packages-section" },
    React.createElement(
      "div",
      { className: "container" },
      React.createElement(
        "div",
        { className: "ss-packages-header reveal" },
        React.createElement("p", { className: "eyebrow" }, "CONSULTATION FORMATS"),
        React.createElement(
          "h2",
          { className: "h-1", style: { marginTop: 16 } },
          "Two ways to ",
          React.createElement("em", { className: "italic", style: { color: "var(--driftwood)" } }, "work together")
        ),
        React.createElement(
          "p",
          { className: "lead", style: { marginTop: 14, maxWidth: "60ch" } },
          "Choose remote video consultations or an intensive on-site studio visit based on your stage and location."
        )
      ),
      React.createElement(
        "div",
        { className: "ss-packages-grid" },
        packages.map((pkg, idx) => {
          const applyHref = pkg.applyUrl || (pkg.key === "studio-consulting-in-person"
            ? "course_forms/pawpad-application-consulting-gssc.html?format=in-person"
            : "course_forms/pawpad-application-consulting-gssc.html?format=online");

          return React.createElement(
            "article",
            { key: pkg.key || idx, className: "ss-package-card reveal", style: { transitionDelay: `${idx * 100}ms` } },
            pkg.tag && React.createElement("div", { className: "ss-pkg-tag" }, pkg.tag),
            React.createElement("h3", { className: "ss-pkg-title" }, pkg.title),
            React.createElement(
              "div",
              { className: "ss-pkg-price-row" },
              React.createElement("span", { className: "ss-pkg-price" }, pkg.price),
              pkg.priceUnit && React.createElement("span", { className: "ss-pkg-unit" }, `/ ${pkg.priceUnit.replace(/^\/\s*/, "")}`),
              pkg.duration && React.createElement("span", { className: "ss-pkg-duration" }, ` · ${pkg.duration}`)
            ),
            React.createElement("p", { className: "ss-pkg-desc" }, pkg.desc),
            pkg.includes && Array.isArray(pkg.includes) && React.createElement(
              "ul",
              { className: "ss-pkg-includes" },
              pkg.includes.map((inc, i) => React.createElement(
                "li",
                { key: i },
                React.createElement(
                  "svg",
                  { width: 14, height: 14, viewBox: "0 0 24 24", fill: "none", stroke: "var(--driftwood)", strokeWidth: 2.5, strokeLinecap: "round", strokeLinejoin: "round", style: { flexShrink: 0, marginTop: 4 } },
                  React.createElement("polyline", { points: "20 6 9 17 4 12" })
                ),
                React.createElement("span", null, inc)
              ))
            ),
            pkg.note && React.createElement("div", { className: "ss-pkg-note" }, pkg.note),
            React.createElement(
              "div",
              { className: "ss-pkg-actions" },
              React.createElement(
                "a",
                { href: applyHref, className: "btn btn-primary ss-btn-book" },
                pkg.ctaText || "Book Now ",
                React.createElement("span", { style: { marginLeft: 4 } }, "→")
              ),
              React.createElement(
                "button",
                {
                  type: "button",
                  className: "btn btn-outline ss-btn-cart",
                  onClick: () => handleAddToCartClick(pkg)
                },
                "Add to Cart"
              )
            )
          );
        })
      ),
      cms.disclaimer && React.createElement(
        "div",
        { className: "ss-disclaimer-box reveal" },
        React.createElement("strong", null, "Note: "),
        cms.disclaimer
      )
    ),
    React.createElement(
      "style",
      null,
      `
        .ss-packages-section { padding: 90px 0; background: var(--cream-bg); }
        .ss-packages-header { margin-bottom: 52px; }
        .ss-packages-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; }
        .ss-package-card {
          background: #ffffff;
          border-radius: 24px;
          padding: 44px 38px;
          border: 1.5px solid color-mix(in oklab, var(--ink), transparent 90%);
          box-shadow: 0 4px 24px rgba(0,0,0,0.03);
          display: flex;
          flex-direction: column;
          position: relative;
          transition: transform var(--t-fast) var(--ease), box-shadow var(--t-fast) var(--ease), border-color var(--t-fast) var(--ease);
        }
        .ss-package-card:hover {
          transform: translateY(-4px);
          border-color: var(--driftwood);
          box-shadow: 0 24px 48px -20px color-mix(in oklab, var(--ink), transparent 75%);
        }
        .ss-pkg-tag {
          align-self: flex-start;
          font-size: 12px;
          font-weight: 700;
          letter-spacing: 0.08em;
          text-transform: uppercase;
          color: var(--driftwood-deep);
          background: color-mix(in oklab, var(--champagne), transparent 40%);
          padding: 4px 12px;
          border-radius: 999px;
          margin-bottom: 18px;
        }
        .ss-pkg-title { font-family: var(--f-display); font-size: 26px; font-weight: 500; color: var(--ink); margin: 0 0 14px; line-height: 1.25; }
        .ss-pkg-price-row { display: flex; align-items: baseline; flex-wrap: wrap; margin-bottom: 18px; gap: 4px; }
        .ss-pkg-price { font-family: var(--f-display); font-size: 32px; color: var(--driftwood); font-weight: 500; }
        .ss-pkg-unit { font-size: 15px; color: var(--ink-soft); font-weight: 500; }
        .ss-pkg-duration { font-size: 14px; color: var(--ink-mute); font-weight: 400; }
        .ss-pkg-desc { font-size: 15px; line-height: 1.65; color: var(--ink-soft); margin: 0 0 24px; }
        .ss-pkg-includes { list-style: none; padding: 0; margin: 0 0 24px; display: flex; flex-direction: column; gap: 12px; flex: 1; }
        .ss-pkg-includes li { display: flex; align-items: flex-start; gap: 10px; font-size: 14.5px; line-height: 1.55; color: var(--ink); }
        .ss-pkg-note {
          background: #fdf8ec;
          border-radius: 12px;
          padding: 12px 16px;
          font-size: 13.5px;
          color: var(--ink-soft);
          font-style: italic;
          margin-bottom: 28px;
          border-left: 3px solid var(--driftwood);
        }
        .ss-pkg-actions { display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 12px; margin-top: auto; }
        .ss-btn-book { justify-content: center; text-align: center; }
        .ss-btn-cart { justify-content: center; text-align: center; }
        .ss-disclaimer-box {
          margin-top: 48px;
          padding: 20px 24px;
          background: color-mix(in oklab, var(--champagne), transparent 50%);
          border-radius: 14px;
          border: 1px solid color-mix(in oklab, var(--ink), transparent 90%);
          font-size: 13.5px;
          line-height: 1.6;
          color: var(--ink-soft);
        }
        @media (max-width: 860px) {
          .ss-packages-grid { grid-template-columns: 1fr; gap: 24px; }
          .ss-package-card { padding: 32px 24px; }
          .ss-pkg-actions { grid-template-columns: 1fr; }
        }
      `
    )
  );
}

function StudioGallery() {
  const cms = (typeof useCmsContent === "function")
    ? useCmsContent("studioSetup")
    : (window.PawpadContentStore ? window.PawpadContentStore.get("studioSetup") : {});

  const gallery = (cms.gallery && Array.isArray(cms.gallery)) ? cms.gallery : [
    {
      img: "assets/img/pawpad/studio-setup-overview.webp",
      caption: "Spacious studio layout with dedicated washing & drying zones"
    },
    {
      img: "assets/img/pawpad/studio-setup-grooming-area.webp",
      caption: "Ergonomic grooming table & stainless steel bathing station"
    },
    {
      img: "assets/img/pawpad/studio-setup-hydraulic-table.webp",
      caption: "Hydraulic lift table and high-velocity dryer positioning"
    }
  ];

  return React.createElement(
    "section",
    { className: "ss-gallery-section" },
    React.createElement(
      "div",
      { className: "container" },
      React.createElement(
        "div",
        { className: "ss-gallery-header reveal" },
        React.createElement("p", { className: "eyebrow" }, "STUDIO INFRASTRUCTURE"),
        React.createElement(
          "h2",
          { className: "h-1", style: { marginTop: 14 } },
          "Designed for ",
          React.createElement("em", { className: "italic", style: { color: "var(--driftwood)" } }, "calm, ergonomic care")
        ),
        React.createElement(
          "p",
          { className: "lead", style: { marginTop: 14, maxWidth: "58ch" } },
          "From non-slip flooring and hydraulic lift tables to specialized stainless steel bathing stations, every detail is engineered for safety and ease."
        )
      ),
      React.createElement(
        "div",
        { className: "ss-gallery-grid" },
        gallery.map((item, idx) => React.createElement(
          "div",
          { key: idx, className: "ss-gallery-item reveal", style: { transitionDelay: `${idx * 120}ms` } },
          React.createElement(
            "div",
            { className: "ss-gallery-img-wrap" },
            React.createElement("img", {
              src: item.img,
              alt: item.caption || `Pawpad Studio Setup ${idx + 1}`,
              loading: "lazy",
              onError: (e) => { if (window.handleImgError) window.handleImgError(e, "assets/img/pawpad/studio-setup-overview.webp"); }
            })
          ),
          item.caption && React.createElement("p", { className: "ss-gallery-caption" }, item.caption)
        ))
      )
    ),
    React.createElement(
      "style",
      null,
      `
        .ss-gallery-section { padding: 90px 0; background: var(--champagne-soft); }
        .ss-gallery-header { margin-bottom: 48px; }
        .ss-gallery-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
        .ss-gallery-item {
          background: #ffffff;
          border-radius: 20px;
          overflow: hidden;
          box-shadow: 0 4px 20px rgba(0,0,0,0.03);
          border: 1px solid color-mix(in oklab, var(--ink), transparent 90%);
          display: flex;
          flex-direction: column;
        }
        .ss-gallery-img-wrap {
          width: 100%;
          aspect-ratio: 4/3;
          overflow: hidden;
          background: #f0ebe1;
        }
        .ss-gallery-img-wrap img {
          width: 100%;
          height: 100%;
          object-fit: cover;
          transition: transform 0.6s var(--ease);
          display: block;
        }
        .ss-gallery-item:hover .ss-gallery-img-wrap img { transform: scale(1.05); }
        .ss-gallery-caption {
          padding: 16px 20px;
          margin: 0;
          font-size: 14px;
          line-height: 1.5;
          color: var(--ink-soft);
          font-style: italic;
          background: #ffffff;
        }
        @media (max-width: 900px) {
          .ss-gallery-grid { grid-template-columns: 1fr; gap: 20px; }
        }
      `
    )
  );
}

function StudioRoadmap() {
  const cms = (typeof useCmsContent === "function")
    ? useCmsContent("studioSetup")
    : (window.PawpadContentStore ? window.PawpadContentStore.get("studioSetup") : {});

  const steps = (cms.steps && Array.isArray(cms.steps)) ? cms.steps : [
    {
      step: "1",
      title: "Get in touch",
      desc: "Tell us a bit about your space, species focus, and plans."
    },
    {
      step: "2",
      title: "Choose the right format",
      desc: "We'll recommend the online or in-person option based on what you need."
    },
    {
      step: "3",
      title: "Book & plan",
      desc: "Book your session and (for in-person visits) confirm travel dates to begin."
    }
  ];

  return React.createElement(
    "section",
    { className: "ss-roadmap-section" },
    React.createElement(
      "div",
      { className: "container" },
      React.createElement(
        "div",
        { className: "ss-roadmap-header reveal" },
        React.createElement("p", { className: "eyebrow" }, "HOW TO GET STARTED"),
        React.createElement(
          "h2",
          { className: "h-1", style: { marginTop: 14 } },
          "Simple 3-step ",
          React.createElement("em", { className: "italic", style: { color: "var(--driftwood)" } }, "process")
        )
      ),
      React.createElement(
        "div",
        { className: "ss-steps-grid" },
        steps.map((s, idx) => React.createElement(
          "div",
          { key: idx, className: "ss-step-card reveal", style: { transitionDelay: `${idx * 100}ms` } },
          React.createElement("div", { className: "ss-step-num" }, s.step || (idx + 1)),
          React.createElement("h3", { className: "ss-step-title" }, s.title),
          React.createElement("p", { className: "ss-step-desc" }, s.desc)
        ))
      ),
      React.createElement(
        "div",
        { className: "ss-roadmap-cta reveal", style: { marginTop: 44, textAlign: "center" } },
        React.createElement(
          "a",
          { href: "course_forms/pawpad-application-consulting-gssc.html", className: "btn btn-primary" },
          "Get in Touch & Book ",
          React.createElement("span", { style: { marginLeft: 4 } }, "→")
        )
      )
    ),
    React.createElement(
      "style",
      null,
      `
        .ss-roadmap-section { padding: 80px 0; background: var(--cream-bg); }
        .ss-roadmap-header { margin-bottom: 48px; text-align: center; }
        .ss-steps-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 28px; }
        .ss-step-card {
          background: #ffffff;
          border-radius: 20px;
          padding: 36px 30px;
          border: 1px solid color-mix(in oklab, var(--ink), transparent 92%);
          position: relative;
        }
        .ss-step-num {
          font-family: var(--f-display);
          font-size: 36px;
          font-weight: 600;
          color: var(--driftwood);
          line-height: 1;
          margin-bottom: 16px;
          opacity: 0.9;
        }
        .ss-step-title { font-family: var(--f-body); font-size: 19px; font-weight: 700; color: var(--ink); margin: 0 0 10px; }
        .ss-step-desc { font-size: 14.5px; line-height: 1.6; color: var(--ink-soft); margin: 0; }
        @media (max-width: 860px) {
          .ss-steps-grid { grid-template-columns: 1fr; gap: 20px; }
        }
      `
    )
  );
}

function StudioFaq() {
  const [openFaq, setOpenFaq] = useStateS(null);
  const cms = (typeof useCmsContent === "function")
    ? useCmsContent("studioSetup")
    : (window.PawpadContentStore ? window.PawpadContentStore.get("studioSetup") : {});

  const faqs = (cms.faqs && Array.isArray(cms.faqs)) ? cms.faqs : [
    {
      q: "What is the primary difference between Online and In-Person consulting?",
      a: "The Online Consultation is conducted over 2 video calls using your floor plans and photos, ideal for remote guidance on equipment and budgets. The In-Person Visit involves a full day on-site assessing your actual space in person to give customized layout and workflow recommendations."
    },
    {
      q: "Does this include a complete architectural floor plan design?",
      a: "Deliverables are equipment/budget guidance and space recommendations. This does not include a full architectural floor plan design."
    },
    {
      q: "Do you cover both dog and cat grooming setups?",
      a: "Yes. Both dog and cat setups are covered — the equipment and layout needs differ meaningfully between the two, and we address both."
    },
    {
      q: "How are travel and accommodation handled for in-person visits?",
      a: "For in-person visits, travel and accommodation are billed separately, at actuals, in addition to the ₹35,000/day consulting fee."
    }
  ];

  const toggle = (i) => setOpenFaq(openFaq === i ? null : i);

  return React.createElement(
    "section",
    { className: "ss-faq-section" },
    React.createElement(
      "div",
      { className: "container", style: { maxWidth: "860px" } },
      React.createElement(
        "div",
        { className: "ss-faq-header reveal" },
        React.createElement("p", { className: "eyebrow" }, "FREQUENTLY ASKED QUESTIONS"),
        React.createElement(
          "h2",
          { className: "h-1", style: { marginTop: 14 } },
          "Consulting ",
          React.createElement("em", { className: "italic", style: { color: "var(--driftwood)" } }, "details & FAQs")
        )
      ),
      React.createElement(
        "div",
        { className: "ss-faq-list" },
        faqs.map((faq, idx) => {
          const isOpen = openFaq === idx;
          return React.createElement(
            "div",
            { key: idx, className: "ss-faq-item reveal" + (isOpen ? " open" : "") },
            React.createElement(
              "button",
              {
                className: "ss-faq-q",
                onClick: () => toggle(idx),
                "aria-expanded": isOpen
              },
              React.createElement("span", null, faq.q),
              React.createElement(
                "span",
                { className: "ss-faq-icon" },
                isOpen ? "−" : "+"
              )
            ),
            isOpen && React.createElement(
              "div",
              { className: "ss-faq-a" },
              React.createElement("p", null, faq.a)
            )
          );
        })
      )
    ),
    React.createElement(
      "style",
      null,
      `
        .ss-faq-section { padding: 90px 0; background: var(--champagne-soft); }
        .ss-faq-header { margin-bottom: 44px; text-align: center; }
        .ss-faq-list { display: flex; flex-direction: column; gap: 14px; }
        .ss-faq-item {
          background: #ffffff;
          border-radius: 16px;
          border: 1px solid color-mix(in oklab, var(--ink), transparent 90%);
          overflow: hidden;
          transition: border-color var(--t-fast) var(--ease);
        }
        .ss-faq-item.open { border-color: var(--driftwood); }
        .ss-faq-q {
          width: 100%;
          text-align: left;
          background: none;
          border: none;
          padding: 22px 24px;
          font-family: var(--f-body);
          font-size: 16.5px;
          font-weight: 600;
          color: var(--ink);
          display: flex;
          align-items: center;
          justify-content: space-between;
          cursor: pointer;
          gap: 16px;
        }
        .ss-faq-icon {
          font-size: 22px;
          font-weight: 400;
          color: var(--driftwood);
          flex-shrink: 0;
        }
        .ss-faq-a {
          padding: 0 24px 22px;
          font-size: 15px;
          line-height: 1.65;
          color: var(--ink-soft);
        }
        .ss-faq-a p { margin: 0; }
      `
    )
  );
}

function StudioSetupPage({ onBook, onAddToCart }) {
  useReveal();

  return React.createElement(
    React.Fragment,
    null,
    React.createElement(StudioSetupHero, { onBook }),
    React.createElement(StudioAudienceAndValue, null),
    React.createElement(StudioPackages, { onBook, onAddToCart }),
    React.createElement(StudioGallery, null),
    React.createElement(StudioRoadmap, null),
    React.createElement(StudioFaq, null)
  );
}

Object.assign(window, { StudioSetupPage });
