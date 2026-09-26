
const { useState: useStateE, useEffect: useEffectE, useRef: useRefE } = React;
const EXPERIENCE_STEPS = [
  {
    no: "01",
    title: "A Little Hello",
    body: "Tell us about your pet \u2014 their personality, preferences, and anything you'd like us to know.",
    img: "assets/img/pawpad/experience-a-little-hello.webp",
    pos: "right center"
  },
  {
    no: "02",
    title: "A Calm Welcome",
    body: "Your pet arrives in a quiet, appointment-only space designed to help them settle in comfortably.",
    img: "assets/img/pawpad/experience-a-calm-welcome-snapshot.webp"
  },
  {
    no: "03",
    title: "Getting to Know Them",
    body: "We take a moment to assess their coat, comfort levels, and individual needs before we begin.",
    img: "assets/img/pawpad/experience-getting-to-know-them-snapshot.webp"
  },
  {
    no: "04",
    title: "Care at Their Pace",
    body: "Grooming is tailored to your pet, with plenty of patience, breaks, and gentle handling along the way.",
    img: "assets/img/pawpad/experience-care-at-their-pace-snapshot.webp"
  },
  {
    no: "05",
    title: "One-on-One Attention",
    body: "No cages. No rushing. Just dedicated care focused entirely on your pet's wellbeing.",
    img: "assets/img/pawpad/grooming-page-grooming-hair-cut.webp"
  },
  {
    no: "06",
    title: "Ready to Head Home",
    body: "A fresh coat, a comfortable pet, and personalised recommendations for care between visits.",
    img: "assets/img/pawpad/experience-ready-to-head-home-snapshot.webp"
  }
];
// Everything on this page can be edited in the admin panel (Website Content CMS → Experience Page).
function useExperienceContent() {
  const cms = typeof useCmsContent === "function" ? useCmsContent("experience") : {};
  const c = cms || {};
  const list = (value, fallback) => (Array.isArray(value) && value.length ? value : fallback);
  return {
    ...c,
    steps: list(c.steps, EXPERIENCE_STEPS).map((st, i) => ({ ...(EXPERIENCE_STEPS[i] || {}), ...st, no: String(i + 1).padStart(2, "0") })),
    handling: c.handling || {},
    products: c.products || {}
  };
}
function ExperienceHero() {
  const c = useExperienceContent();
  return /* @__PURE__ */ React.createElement("section", { className: "exp-hero" }, /* @__PURE__ */ React.createElement("div", { className: "container" }, /* @__PURE__ */ React.createElement("p", { className: "eyebrow reveal in" }, c.eyebrow || "The Pawpad experience"), /* @__PURE__ */ React.createElement("h1", { className: "h-display reveal in", style: { marginTop: 24, maxWidth: "18ch" } }, c.title || "What it actually ", /* @__PURE__ */ React.createElement("em", { className: "italic", style: { color: "var(--driftwood)" } }, c.titleAccent || "feels like"), c.titleEnd === undefined ? " to bring your pet here" : c.titleEnd), /* @__PURE__ */ React.createElement("p", { className: "lead reveal in", style: { marginTop: 28, maxWidth: "60ch" } }, c.lead || "At Pawpad, appointments are intentionally spaced out to ensure that grooming is never rushed. We work at a pace that allows pets to settle, adjust, and feel comfortable throughout the grooming process.")), /* @__PURE__ */ React.createElement("style", null, `
        .exp-hero { padding: 180px 0 60px; }
      `));
}
function StepJourney() {
  const EXPERIENCE_STEPS = useExperienceContent().steps;
  const [active, setActive] = useStateE(0);
  const stepsRef = useRefE([]);

  useEffectE(() => {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((e) => {
        if (e.isIntersecting) {
          const i = parseInt(e.target.dataset.idx, 10);
          if (!isNaN(i)) setActive(i);
        }
      });
    }, {
      rootMargin: "-25% 0px -45% 0px",
      threshold: [0.1, 0.3, 0.6]
    });
    stepsRef.current.forEach((el) => el && io.observe(el));
    return () => io.disconnect();
  }, []);

  return /* @__PURE__ */ React.createElement("section", { className: "journey", id: "journey" },
    /* @__PURE__ */ React.createElement("div", { className: "container journey-grid" },
      /* @__PURE__ */ React.createElement("aside", { className: "journey-side" },
        /* @__PURE__ */ React.createElement("div", { className: "journey-sticky" },
          /* @__PURE__ */ React.createElement("p", { className: "eyebrow" }, "Step by step"),
          /* @__PURE__ */ React.createElement("h2", { className: "h-1", style: { marginTop: 18, maxWidth: "14ch" } },
            "What to expect"
          ),
          /* @__PURE__ */ React.createElement("div", { className: "journey-progress" },
            EXPERIENCE_STEPS.map((s, i) =>
              /* @__PURE__ */ React.createElement("button", {
                key: s.no,
                className: "j-prog " + (i === active ? "on" : i < active ? "done" : ""),
                onClick: () => {
                  var _a;
                  (_a = stepsRef.current[i]) == null ? void 0 : _a.scrollIntoView({ behavior: "smooth", block: "center" });
                }
              },
                /* @__PURE__ */ React.createElement("span", { className: "j-prog-no" }, s.no),
                /* @__PURE__ */ React.createElement("span", { className: "j-prog-title" }, s.title)
              )
            )
          )
        )
      ),
      /* @__PURE__ */ React.createElement("div", { className: "journey-main" },
        EXPERIENCE_STEPS.map((s, i) =>
          /* @__PURE__ */ React.createElement("article", {
            key: s.no,
            className: "j-step reveal",
            ref: (el) => stepsRef.current[i] = el,
            "data-idx": i
          },
            /* @__PURE__ */ React.createElement("div", { className: "j-step-img blob-1" },
              /* @__PURE__ */ React.createElement("img", {
                src: s.img,
                alt: s.title,
                style: { objectPosition: s.pos || "center center" }
              })
            ),
            /* @__PURE__ */ React.createElement("div", { className: "j-step-text" },
              /* @__PURE__ */ React.createElement("h3", { className: "h-2" }, s.title),
              /* @__PURE__ */ React.createElement("p", null, s.body)
            )
          )
        )
      )
    ),
    /* @__PURE__ */ React.createElement("style", null, `
      .journey { background: var(--champagne-soft); padding: 80px 0 120px; }
      .journey-grid { display: grid; grid-template-columns: 360px 1fr; gap: 56px; align-items: start; }
      .journey-side { position: relative; }
      .journey-sticky { position: sticky; top: 120px; }
      .journey-sticky .eyebrow { font-size: 26px; }
      .journey-progress { margin-top: 36px; display: flex; flex-direction: column; gap: 8px; }
      .j-prog {
        display: grid; grid-template-columns: 48px 1fr; gap: 16px;
        padding: 12px 0; align-items: baseline;
        text-align: left;
        color: var(--ink-mute);
        position: relative;
        transition: color var(--t-fast) var(--ease);
        border: none; background: transparent; cursor: pointer;
        font-family: var(--f-body); width: 100%;
      }
      .j-prog .j-prog-no { font-family: var(--f-display); font-size: 30px; line-height: 1; }
      .j-prog .j-prog-title { font-size: 26px; line-height: 1.25; }
      .j-prog.on { color: var(--ink); }
      .j-prog.on .j-prog-no { color: var(--driftwood); font-size: 34px; font-weight: 700; }
      .j-prog.on .j-prog-title { color: var(--driftwood); font-weight: 600; font-size: 26px; }
      .j-prog.done { color: color-mix(in oklab, var(--ink), transparent 50%); }
      .j-prog:hover { color: var(--ink); }
      .j-step {
        display: grid; grid-template-columns: 1fr 1fr; gap: 56px;
        align-items: center;
        padding: 40px 0;
        border-bottom: 1px dashed color-mix(in oklab, var(--ink), transparent 85%);
      }
      .j-step:last-child { border-bottom: 0; }
      .j-step-img {
        aspect-ratio: 4/4.4;
        overflow: hidden; background: transparent;
        position: relative;
        animation: morph 14s ease-in-out infinite alternate;
      }
      body[data-motion="still"] .j-step-img { animation: none; }
      .j-step-img img { width: 100%; height: 100%; object-fit: cover; }
      .j-step-text p { margin-top: 18px; font-size: 26px; line-height: 1.6; max-width: 44ch; }
      
      .j-step:nth-child(even) { direction: rtl; }
      .j-step:nth-child(even) > * { direction: ltr; }
      @media (max-width: 1000px) {
        .journey-grid { grid-template-columns: 1fr; }
        .journey-sticky { position: static; }
        .journey-progress { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; margin-top: 24px; }
        .j-prog { grid-template-columns: 1fr; gap: 6px; padding: 8px; background: var(--white); border-radius: 12px; }
        .j-prog .j-prog-title { font-size: 11px; }
      }
      @media (max-width: 700px) {
        .j-step { grid-template-columns: 1fr; gap: 28px; padding: 32px 0; }
        .j-step:nth-child(even) { direction: ltr; }
      }
    `)
  );
}
function HandlingPhilosophy() {
  const h = useExperienceContent().handling;
  const paragraphs = Array.isArray(h.paragraphs) && h.paragraphs.length ? h.paragraphs : [
    "Every session is guided by the individual animal. We pay close attention to body language, comfort levels, and stress signals, adapting our approach as needed. If a pet requires more time, more breaks, or a slower introduction to a particular part of grooming, that is exactly what they receive.",
    "The grooming environment has been designed to minimise unnecessary stress and overstimulation. Appointments are staggered to avoid crowded spaces, excessive noise, and overwhelming activity.",
    "Every pet receives dedicated attention throughout their appointment. We take the time to understand their individual needs, preferences, sensitivities, and comfort levels before and during the grooming process.",
    "Our approach to animal handling has been shaped by years of working with rescue animals, community animals, and pets with a wide range of personalities and experiences. Trust cannot be forced. It is built through patience, consistency, and respect for what an animal is communicating.",
    "For anxious or reactive pets, we assess each pet individually and adapt our handling and grooming approach to suit their comfort levels. We also offer guidance to help owners maintain coat care and hygiene in ways that are appropriate for their individual pet."
  ];
  return /* @__PURE__ */ React.createElement("section", { className: "handling" }, /* @__PURE__ */ React.createElement("div", { className: "container" }, /* @__PURE__ */ React.createElement("div", { className: "handling-grid" }, /* @__PURE__ */ React.createElement("div", { className: "handling-images reveal" }, /* @__PURE__ */ React.createElement("div", { className: "h-img-1 blob-2" }, /* @__PURE__ */ React.createElement("img", { src: h.img1 || "assets/img/pawpad/grooming-snapshot.webp", alt: "Gentle grooming at Pawpad" })), /* @__PURE__ */ React.createElement("div", { className: "h-img-2 blob-1" }, /* @__PURE__ */ React.createElement("img", { src: h.img2 || "assets/img/pawpad/grooming-page-grooming-massage.webp", alt: "A dog calmly relaxing during a gentle massage" })), /* @__PURE__ */ React.createElement("div", { className: "handling-badge" }, /* @__PURE__ */ React.createElement(PawIcon, { size: 20, color: "var(--driftwood)" }), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("strong", null, h.badgeTitle || "Calm"), /* @__PURE__ */ React.createElement("span", null, h.badgeText || "appointment-only care with room to pause")))), /* @__PURE__ */ React.createElement("div", { className: "handling-text reveal" }, /* @__PURE__ */ React.createElement("p", { className: "eyebrow" }, h.eyebrow || "The Pawpad experience"), /* @__PURE__ */ React.createElement("h2", { className: "h-1", style: { marginTop: 18, maxWidth: "15ch" } }, h.title || "Grooming at ", /* @__PURE__ */ React.createElement("em", { className: "italic", style: { color: "var(--driftwood)" } }, h.titleAccent || "your pet's pace")), paragraphs.map((text, i) => /* @__PURE__ */ React.createElement("p", { key: i, style: i === 0 ? { marginTop: 28, maxWidth: "54ch" } : { maxWidth: "54ch" } }, text))))), /* @__PURE__ */ React.createElement("style", null, `
        .handling { background: var(--cream-bg); }
        .handling-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 80px; align-items: center; }
        .handling-images { position: relative; aspect-ratio: 1/1.1; }
        .h-img-1, .h-img-2 { position: absolute; overflow: hidden; }
        .h-img-1 img, .h-img-2 img { width: 100%; height: 100%; object-fit: cover; }
        .h-img-1 { left: 0; top: 0; width: 64%; height: 60%; background: var(--driftwood); }
        .h-img-2 { right: 0; bottom: 0; width: 60%; height: 56%; background: var(--eagle); box-shadow: -20px 30px 60px -30px color-mix(in oklab, var(--ink), transparent 60%); }
        .handling-badge {
          position: absolute; left: 8%; bottom: 8%;
          background: var(--white);
          padding: 16px 22px;
          border-radius: 18px;
          display: flex; align-items: center; gap: 14px;
          z-index: 3;
          box-shadow: 0 24px 40px -20px color-mix(in oklab, var(--ink), transparent 60%);
        }
        body[data-palette="dark"] .handling-badge { background: color-mix(in oklab, var(--champagne), black 5%); }
        .handling-badge strong { display: block; font-family: var(--f-display); font-size: 26px; color: var(--driftwood); line-height: 1; }
        .handling-badge span { font-size: 12px; color: var(--ink-mute); }
        .handling-text p { margin-top: 18px; font-size: 16px; line-height: 1.7; }
        @media (max-width: 900px) {
          .handling-grid { grid-template-columns: 1fr; gap: 36px; }
          .handling-images { aspect-ratio: 1.1/1; }
        }
      `));
}
function ProductsStrip() {
  const pr = useExperienceContent().products;
  const defaultProducts = [
    { name: "Coat-specific care", note: "Products are selected according to each pet's coat type, skin condition, sensitivities, and grooming requirements." },
    { name: "Healthy skin", note: "Shampoos, conditioners, treatments, and coat care products are chosen to support skin and coat maintenance." },
    { name: "Sensitive pets", note: "Particular care is taken when working with pets who have sensitive skin, allergies, coat concerns, or specific grooming needs." },
    { name: "Gentle formulas", note: "We prioritise products that are gentle, safe, and effective without unnecessarily harsh ingredients or overpowering fragrances." },
    { name: "Individual plans", note: "Rather than taking a one-product-fits-all approach, each product choice is tailored to the individual animal." },
    { name: "Long-term comfort", note: "Every product used should contribute positively to your pet's comfort, wellbeing, and long-term coat health." }
  ];
  const products = Array.isArray(pr.items) && pr.items.length ? pr.items : defaultProducts;
  return /* @__PURE__ */ React.createElement("section", { className: "products" }, /* @__PURE__ */ React.createElement("div", { className: "container" }, /* @__PURE__ */ React.createElement("div", { className: "products-head reveal" }, /* @__PURE__ */ React.createElement("p", { className: "eyebrow" }, pr.eyebrow || "Products we use"), /* @__PURE__ */ React.createElement("h2", { className: "h-1", style: { marginTop: 18, maxWidth: "22ch" } }, pr.title || "What we put ", /* @__PURE__ */ React.createElement("em", { className: "italic", style: { color: "var(--driftwood)" } }, pr.titleAccent || "on them"), pr.titleEnd === undefined ? ", matters" : pr.titleEnd), /* @__PURE__ */ React.createElement("p", { className: "lead", style: { marginTop: 24, maxWidth: "54ch" } }, pr.lead || "We use professional-grade grooming products selected according to each pet's individual coat type, skin condition, sensitivities, and grooming requirements.")), /* @__PURE__ */ React.createElement("div", { className: "products-grid reveal" }, products.map((p, i) => /* @__PURE__ */ React.createElement("div", { key: i, className: "product-card", style: { transitionDelay: `${i * 70}ms` } }, /* @__PURE__ */ React.createElement("div", { className: "product-no" }, String(i + 1).padStart(2, "0")), /* @__PURE__ */ React.createElement("h4", { className: "h-3", style: { fontFamily: "var(--f-display)" } }, p.name), /* @__PURE__ */ React.createElement("p", null, p.note))))), /* @__PURE__ */ React.createElement("style", null, `
        .products { background: var(--champagne); }
        .products-head { max-width: 720px; margin-bottom: 56px; }
        .products-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
        .product-card {
          padding: 28px;
          background: var(--cream-bg);
          border-radius: 18px;
          display: flex; flex-direction: column; gap: 6px;
          border: 1px solid color-mix(in oklab, var(--ink), transparent 92%);
          transition: all var(--t-fast) var(--ease);
        }
        body[data-palette="dark"] .product-card { background: color-mix(in oklab, var(--champagne), black 5%); }
        .product-card:hover { transform: translateY(-4px); background: var(--white); }
        body[data-palette="dark"] .product-card:hover { background: color-mix(in oklab, var(--champagne), black 0%); }
        .product-no { font-size: 11px; letter-spacing: .2em; color: var(--driftwood); }
        .product-card p { font-size: 13.5px; color: var(--ink-mute); margin: 0; }
        @media (max-width: 800px) {
          .products-grid { grid-template-columns: 1fr 1fr; }
        }
      `));
}
function ExperiencePage() {
  useReveal();
  return /* @__PURE__ */ React.createElement("div", { className: "page-enter" }, /* @__PURE__ */ React.createElement(ExperienceHero, null), /* @__PURE__ */ React.createElement(StepJourney, null), /* @__PURE__ */ React.createElement(HandlingPhilosophy, null), /* @__PURE__ */ React.createElement(ProductsStrip, null));
}
Object.assign(window, { ExperiencePage });
