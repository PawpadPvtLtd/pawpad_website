/**
 * Grooming slot booking, shared by the checkout and the "Book a session" window.
 *
 * The Pawpad server (api.pawpad.in) sends each day's hourly start times and the
 * time the team (bather, groomer, Leena) is already busy, from bookings, admin
 * blocks and the info@pawpad.in calendar. Only the times a service fits are shown
 * (schedule-rules.js); the server checks everything again when the booking is
 * saved, so the same time can never be given twice.
 */

const { useState: useStateSlots, useEffect: useEffectSlots } = React;

// Items that take a grooming slot. Boarding and courses stay as enquiries.
const SLOT_CATEGORIES = ["Grooming", "Wellness"];
// Used when someone books "Grooming" without choosing the exact service yet.
const UNSPECIFIED_GROOMING_SERVICE = "grooming-unspecified";

const PawpadSlots = {
  _cached: null,
  _cachedAt: 0,

  /** The next 30 days: { ok, days: [{ date, starts, minStart, busy }], plans, defaultPlan, onlineOff, closeMin, error }. */
  async load(force) {
    if (!force && this._cached && Date.now() - this._cachedAt < 30000) return this._cached;
    if (!window.PawpadApi || !window.PawpadApi.isEnabled()) {
      return { ok: false, days: [], error: "Online booking is switched off." };
    }
    const result = await window.PawpadApi.call("booking_availability", {});
    const value = result.ok
      ? { ok: true, ...result.data, days: result.data.days || [], error: "" }
      : {
          ok: false,
          days: [],
          error: (result.data && result.data.error) || "Online booking is not available right now. Please WhatsApp us on +91 91484 43330 to book."
        };
    if (value.ok) {
      this._cached = value;
      this._cachedAt = Date.now();
    }
    return value;
  },

  forget() {
    this._cached = null;
  },

  takesSlot(item) {
    return Boolean(item) && SLOT_CATEGORIES.includes(item.category);
  },

  /**
   * Start times on a date that fit one service, after the other pets of the same
   * order (excluded: [{ date, time, serviceId }]).
   */
  timesFor(availability, date, serviceId, excluded) {
    if (!availability || !availability.ok || !window.PawpadSchedule) return [];
    const day = availability.days.find((d) => d.date === date);
    if (!day) return [];
    const others = (excluded || []).filter((x) => x && x.date === date && x.time);
    return window.PawpadSchedule.freeStarts(availability, day, serviceId || UNSPECIFIED_GROOMING_SERVICE, others);
  },

  /** "Takes about 2 hours" for a service, from the server's list. */
  durationText(availability, serviceId) {
    if (!availability || !availability.ok || !window.PawpadSchedule) return "";
    return window.PawpadSchedule.durationLabel(window.PawpadSchedule.planFor(availability, serviceId || UNSPECIFIED_GROOMING_SERVICE));
  },

  formatTime(time) {
    const [h, m] = String(time).split(":").map(Number);
    const hour12 = h % 12 === 0 ? 12 : h % 12;
    return `${hour12}:${String(m).padStart(2, "0")} ${h < 12 ? "AM" : "PM"}`;
  },

  /** "2026-09-25" as a Date at local midnight (not UTC, so the day never shifts). */
  toDate(dateStr) {
    const [y, mo, d] = String(dateStr).split("-").map(Number);
    return new Date(y, mo - 1, d);
  },

  formatDate(dateStr, opts) {
    return this.toDate(dateStr).toLocaleDateString("en-IN", opts || { weekday: "short", day: "numeric", month: "short" });
  },

  /** What is paid online for one service: its full price (0 when online payment is off). The server charges its own price. */
  depositFor(availability, serviceId, price) {
    const d = availability && availability.ok && availability.deposit;
    if (!d || !d.active) return 0;
    const known = (d.knownPrices || {})[serviceId];
    const p = typeof price === "number" && price > 0 ? price : known;
    return typeof p === "number" && p > 0 ? p : 0;
  },

  /** Loads Razorpay Checkout (only when a deposit is paid). */
  loadCheckout() {
    if (window.Razorpay) return Promise.resolve(true);
    if (!this._checkoutLoading) {
      this._checkoutLoading = new Promise((resolve) => {
        const s = document.createElement("script");
        s.src = "https://checkout.razorpay.com/v1/checkout.js";
        s.onload = () => resolve(Boolean(window.Razorpay));
        s.onerror = () => { this._checkoutLoading = null; resolve(false); };
        document.head.appendChild(s);
      });
    }
    return this._checkoutLoading;
  },

  /** Opens Razorpay Checkout for the deposit. Resolves to { paid, response }. */
  async payDeposit(payment) {
    if (!(await this.loadCheckout())) return { paid: false, error: "The payment window could not open. Please check your internet connection and try again." };
    return new Promise((resolve) => {
      let done = false;
      const rzp = new window.Razorpay({
        key: payment.keyId,
        order_id: payment.orderId,
        amount: payment.amount,
        currency: payment.currency || "INR",
        name: payment.name || "Pawpad",
        description: payment.description,
        prefill: payment.prefill || {},
        timeout: payment.timeout,
        theme: { color: "#B18D4E" },
        handler: (response) => { done = true; resolve({ paid: true, response }); },
        modal: { ondismiss: () => { if (!done) resolve({ paid: false }); }, confirm_close: true }
      });
      rzp.open();
    });
  },

  /**
   * Books one time per pet. With deposits on, the times are held while the customer
   * pays in Razorpay Checkout, and the booking is confirmed once the payment is verified.
   * Resolves to { ok, ref, bookings, depositPaid, error, taken, notPaid }.
   */
  async book(payload) {
    const key = JSON.stringify({ ...payload, botcheck: undefined });
    let start = this._pending && this._pending.key === key && Date.now() < this._pending.until ? this._pending.start : null;
    if (!start) {
      const result = await window.PawpadApi.call("create_booking", payload);
      this.forget();
      if (!result.ok) {
        return {
          ok: false,
          taken: (result.data && result.data.taken) || [],
          error: (result.data && result.data.error) || "Could not reach the Pawpad server. Please check your internet connection, or WhatsApp us on +91 91484 43330."
        };
      }
      if (!result.data.payment) return { ok: true, ...result.data };
      start = result.data;
      // Pressing the button again within the hold opens the same payment (no second hold).
      this._pending = { key, start, until: Date.now() + (start.holdMinutes || 15) * 60000 };
    }
    const paid = await this.payDeposit(start.payment);
    if (!paid.paid) {
      const left = Math.max(1, Math.round((this._pending.until - Date.now()) / 60000));
      return { ok: false, notPaid: true, error: paid.error || `The payment wasn't made, so the booking isn't confirmed yet. Your time is held for about ${left} more minute${left === 1 ? "" : "s"}: press the button again to pay.` };
    }
    const r = paid.response;
    const confirm = await window.PawpadApi.call("confirm_payment", { orderId: r.razorpay_order_id, paymentId: r.razorpay_payment_id, signature: r.razorpay_signature });
    this._pending = null;
    this.forget();
    if (!confirm.ok) {
      return { ok: false, error: (confirm.data && confirm.data.error) || "Your payment went through, but we couldn't confirm it here. Don't pay again: we'll confirm by email, or WhatsApp us on +91 91484 43330." };
    }
    return { ok: true, ...confirm.data };
  }
};

/**
 * Loads availability once when shown; returns [availability, reload].
 */
function useSlotAvailability(active) {
  const [availability, setAvailability] = useStateSlots(null);
  const reload = async (force) => {
    setAvailability(null);
    setAvailability(await PawpadSlots.load(force));
  };
  useEffectSlots(() => {
    if (active) reload(false);
  }, [active]);
  return [availability, reload];
}

/**
 * Date + time picker showing only free slots for one service.
 * value: { date, time }; excluded: slots already chosen by other pets in the same order.
 */
function SlotPicker({ availability, serviceId, value, onChange, excluded, onRetry }) {
  // A time chosen earlier can stop fitting (another pet in the order moved onto it): clear it.
  const chosen = value || {};
  const stillFits = !chosen.date || !chosen.time || !availability || !availability.ok
    || PawpadSlots.timesFor(availability, chosen.date, serviceId, excluded).includes(chosen.time);
  useEffectSlots(() => {
    if (!stillFits) onChange({ date: chosen.date, time: null });
  }, [stillFits]);
  if (!availability) {
    return React.createElement("p", { className: "lead-sm slot-status" }, "Checking free times…");
  }
  if (!availability.ok) {
    return React.createElement(
      "div",
      { className: "slot-status slot-error", role: "alert" },
      React.createElement("p", null, availability.error),
      React.createElement(
        "div",
        { style: { display: "flex", gap: 10, flexWrap: "wrap", marginTop: 10 } },
        onRetry && React.createElement("button", { type: "button", className: "btn btn-ghost", onClick: onRetry }, "Try again"),
        React.createElement("a", { className: "btn btn-primary", href: "https://wa.me/919148443330?text=" + encodeURIComponent("Hi Pawpad, I would like to book a grooming slot."), target: "_blank", rel: "noopener" }, "WhatsApp us")
      )
    );
  }

  const selected = value || {};
  const times = selected.date ? PawpadSlots.timesFor(availability, selected.date, serviceId, excluded) : [];
  const duration = PawpadSlots.durationText(availability, serviceId);

  return React.createElement(
    "div",
    { className: "slot-picker" },
    React.createElement(
      "div",
      { className: "date-picker-wrap" },
      React.createElement("label", { className: "sub-label" }, "Select Date (next 30 days)"),
      React.createElement(
        "div",
        { className: "date-chip-grid" },
        availability.days.map((day) => {
          const d = PawpadSlots.toDate(day.date);
          const isClosed = d.getDay() === 4;
          const free = PawpadSlots.timesFor(availability, day.date, serviceId, excluded);
          const disabled = isClosed || free.length === 0;
          const isSel = selected.date === day.date;
          return React.createElement(
            "button",
            {
              type: "button",
              key: day.date,
              className: "date-chip " + (isSel ? "active " : "") + (disabled ? "closed" : ""),
              onClick: () => !disabled && onChange({ date: day.date, time: null }),
              disabled,
              "data-date": day.date,
              title: isClosed ? "Closed on Thursdays" : (free.length === 0 ? "Fully booked" : undefined)
            },
            React.createElement("span", { className: "d-day" }, d.toDateString() === new Date().toDateString() ? "Today" : d.toLocaleDateString("en-IN", { weekday: "short" })),
            React.createElement("strong", { className: "d-num" }, d.getDate()),
            React.createElement("span", { className: "d-mon" }, d.toLocaleDateString("en-IN", { month: "short" })),
            disabled && React.createElement("span", { className: "d-closed" }, isClosed ? "Closed" : "Full")
          );
        })
      )
    ),
    selected.date && React.createElement(
      "div",
      { className: "time-picker-wrap", style: { marginTop: 20 } },
      React.createElement("label", { className: "sub-label" }, `Free times on ${PawpadSlots.formatDate(selected.date, { weekday: "long", day: "numeric", month: "long" })}`),
      times.length === 0
        ? React.createElement("p", { className: "lead-sm" }, "No free times left on this day. Please pick another date.")
        : React.createElement(
            "div",
            { className: "time-chip-grid" },
            times.map((t) =>
              React.createElement(
                "button",
                {
                  type: "button",
                  key: t,
                  "data-time": t,
                  className: "time-chip " + (selected.time === t ? "active" : ""),
                  onClick: () => onChange({ date: selected.date, time: t })
                },
                PawpadSlots.formatTime(t)
              )
            )
          ),
      duration && React.createElement("p", { className: "lead-sm slot-duration", style: { marginTop: 10, fontSize: 13 } },
        `This service takes about ${duration}. Only start times that finish by 8:00 PM are shown.`)
    )
  );
}

Object.assign(window, { PawpadSlots, SlotPicker, useSlotAvailability, UNSPECIFIED_GROOMING_SERVICE });
