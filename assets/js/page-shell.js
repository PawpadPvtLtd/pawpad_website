
const { useState: useStateShell } = React;
function PawpadShell({ route, page: PageComponent }) {
  const [bookingOpen, setBookingOpen] = useStateShell(false);
  const [bookingService, setBookingService] = useStateShell(null);
  const [cartOpen, setCartOpen] = useStateShell(false);
  const [checkoutOpen, setCheckoutOpen] = useStateShell(false);

  const openBooking = (service) => {
    setBookingService(typeof service === "string" ? service : null);
    setBookingOpen(true);
  };

  const openCart = () => setCartOpen(true);
  const closeCart = () => setCartOpen(false);

  const openCheckout = () => {
    setCartOpen(false);
    setCheckoutOpen(true);
  };
  const closeCheckout = () => setCheckoutOpen(false);

  // Expose global actions so any page button can trigger cart
  window.openCart = openCart;
  window.openCheckout = openCheckout;
  window.addToCart = (item) => {
    if (window.PawpadCartStore) {
      window.PawpadCartStore.addItem(item);
      openCart();
    }
  };

  return /* @__PURE__ */ React.createElement(
    React.Fragment,
    null,
    /* @__PURE__ */ React.createElement(CursorTrail, null),
    /* @__PURE__ */ React.createElement(TopNav, { route, onBook: openBooking, onOpenCart: openCart }),
    /* @__PURE__ */ React.createElement("main", null, /* @__PURE__ */ React.createElement(PageComponent, { onBook: openBooking, onAddToCart: window.addToCart })),
    /* @__PURE__ */ React.createElement(Footer, { onBook: openBooking }),
    /* @__PURE__ */ React.createElement(WhatsAppFloat, null),
    /* @__PURE__ */ React.createElement(
      BookingModal,
      {
        open: bookingOpen,
        onClose: () => setBookingOpen(false),
        initialService: bookingService
      }
    ),
    /* @__PURE__ */ React.createElement(
      CartDrawer,
      {
        open: cartOpen,
        onClose: closeCart,
        onCheckout: openCheckout
      }
    ),
    /* @__PURE__ */ React.createElement(
      CheckoutModal,
      {
        open: checkoutOpen,
        onClose: closeCheckout
      }
    )
  );
}
/**
 * A link like about.html#handling: the page is built after the browser looks for
 * #handling, so jump to it once it exists (tries for up to 3 seconds).
 */
function scrollToHash() {
  const id = decodeURIComponent(String(window.location.hash || "").slice(1));
  if (!id || !/^[A-Za-z][\w-]*$/.test(id)) return;
  let tries = 0;
  const look = () => {
    const el = document.getElementById(id);
    if (el) return el.scrollIntoView({ block: "start" });
    if (++tries < 30) setTimeout(look, 100);
  };
  setTimeout(look, 50);
}

function mountPage(route, PageComponent) {
  const render = () => {
    const root = ReactDOM.createRoot(document.getElementById("app"));
    root.render(/* @__PURE__ */ React.createElement(PawpadShell, { route, page: PageComponent }));
    scrollToHash();
  };
  // Wait briefly for the latest published content so visitors don't see old text flash first.
  // If the server is slow, show the page anyway; it updates itself when the content arrives.
  const ready = window.PawpadContentStore && window.PawpadContentStore.ready;
  if (!ready) return render();
  let rendered = false;
  const once = () => {
    if (!rendered) {
      rendered = true;
      render();
    }
  };
  Promise.resolve(ready).then(once, once);
  setTimeout(once, 1500);
}
Object.assign(window, { PawpadShell, mountPage });

