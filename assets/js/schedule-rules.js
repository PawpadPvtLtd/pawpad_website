/**
 * Team scheduling on the website and in the admin panel: which start times fit a
 * service, given who is already busy. The same rules run on the Pawpad server
 * (api/lib/schedule.php), which checks again when a booking is saved.
 *
 * A "day" from the server: { starts: ["11:00", …], minStart, busy: { bather, groomer,
 * leena, studio: [[startMin, endMin], …] } }. A plan: [["bather", 60], ["groomer", 60]].
 * "either" = the bather or the groomer, whoever is free (the bather first).
 */
(function (root, factory) {
  if (typeof module === "object" && module.exports) module.exports = factory();
  else root.PawpadSchedule = factory();
})(typeof self !== "undefined" ? self : this, function () {
  const CLOSE_MIN = 20 * 60;
  const LABELS = { bather: "Bather", groomer: "Groomer", leena: "Leena", either: "Bather or groomer" };

  const toMin = (t) => {
    const [h, m] = String(t).split(":").map(Number);
    return h * 60 + (m || 0);
  };
  const toHm = (min) => `${String(Math.floor(min / 60)).padStart(2, "0")}:${String(min % 60).padStart(2, "0")}`;
  const clock = (min) => {
    const h = Math.floor(min / 60);
    return `${h % 12 === 0 ? 12 : h % 12}:${String(min % 60).padStart(2, "0")} ${h < 12 ? "AM" : "PM"}`;
  };
  const overlaps = (list, s, e) => (list || []).some((iv) => iv[0] < e && iv[1] > s);

  /** The plan for a service, from the server's list (an unknown service: 1 hour, either). */
  function planFor(info, serviceId) {
    const plans = (info && info.plans) || {};
    return plans[serviceId || "grooming-unspecified"] || (info && info.defaultPlan) || [["either", 60]];
  }

  function planMinutes(plan) {
    return plan.reduce((sum, step) => sum + Number(step[1]), 0);
  }

  /** "1 hour", "2½ hours", "20 minutes". */
  function durationLabel(plan) {
    const m = planMinutes(plan);
    if (m < 60) return `${m} minutes`;
    const h = Math.floor(m / 60);
    const rest = m % 60;
    const half = rest === 30 ? "½" : rest ? ` h ${rest} min` : "";
    return `${h}${half} hour${h === 1 && !half ? "" : "s"}`;
  }

  /**
   * Fits a plan at a start time. extra: time already taken by other pets in the same
   * order ({ bather: [[s, e]], … }). Returns the segments [[who, s, e]] or null.
   */
  function fit(day, plan, start, extra, closeMin) {
    if (!day) return null;
    const busy = day.busy || {};
    let t = start;
    const segments = [];
    for (const [role, minutes] of plan) {
      const end = t + Number(minutes);
      if (overlaps(busy.studio, t, end)) return null;
      const candidates = role === "either" ? ["bather", "groomer"] : [role];
      const who = candidates.find((c) => !overlaps(busy[c], t, end) && !overlaps((extra || {})[c], t, end));
      if (!who) return null;
      segments.push([who, t, end]);
      t = end;
    }
    return t <= (closeMin || CLOSE_MIN) ? segments : null;
  }

  function addExtra(extra, segments) {
    const next = { ...(extra || {}) };
    (segments || []).forEach(([who, s, e]) => {
      next[who] = [...(next[who] || []), [s, e]];
    });
    return next;
  }

  /**
   * The website's start times on a day that fit a service, after the other pets
   * of the same order (others: [{ serviceId, time }] on this day).
   */
  function freeStarts(info, day, serviceId, others) {
    if (!day) return [];
    const close = (info && info.closeMin) || CLOSE_MIN;
    let extra = {};
    (others || []).forEach((o) => {
      const segs = fit(day, planFor(info, o.serviceId), toMin(o.time), extra, close);
      if (segs) extra = addExtra(extra, segs);
    });
    const plan = planFor(info, serviceId);
    return (day.starts || []).filter((t) => toMin(t) >= (day.minStart || 0) && fit(day, plan, toMin(t), extra, close));
  }

  /** "Bather 11:00 AM–12:00 PM, then Groomer 12:00 PM–1:00 PM". */
  function segmentsLabel(segments) {
    return (segments || []).map((s) => {
      const [who, a, b] = Array.isArray(s) ? s : [s.staff, s.start, s.end];
      return `${LABELS[who] || who} ${clock(a)}–${clock(b)}`;
    }).join(", then ");
  }

  /** "Bather 1 hour, then groomer 1½ hours". */
  function planLabel(plan) {
    return plan.map(([role, m], i) => `${i ? (LABELS[role] || role).toLowerCase() : LABELS[role] || role} ${durationLabel([[role, m]])}`).join(", then ");
  }

  return { toMin, toHm, clock, overlaps, planFor, planMinutes, durationLabel, fit, addExtra, freeStarts, segmentsLabel, planLabel, LABELS, CLOSE_MIN };
});
