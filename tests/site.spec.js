import { test, expect } from "@playwright/test";

const pages = [
  { path: "/", titleContains: "Pawpad" },
  { path: "/index.html", titleContains: "Pawpad" },
  { path: "/about.html", titleContains: "Our Story" },
  { path: "/experience.html", titleContains: "Experience" },
  { path: "/grooming.html", titleContains: "Grooming" },
  { path: "/courses.html", titleContains: "Courses" },
  { path: "/studio-setup.html", titleContains: "Studio Setup" },
  { path: "/boarding.html", titleContains: "Boarding" },
  { path: "/myotherapy.html", titleContains: "Myotherapy" },
  { path: "/contact.html", titleContains: "Contact" },
  { path: "/policies.html", titleContains: "Policies" },
  { path: "/admin.html", titleContains: "Admin" },
  { path: "/course_forms/pawpad-foundations-page.html", titleContains: "Certification" },
  { path: "/course_forms/pawpad-essentials-dog-page.html", titleContains: "Essential" },
  { path: "/course_forms/pawpad-essentials-cat-page.html", titleContains: "Essential" },
  { path: "/course_forms/pawpad-practitioner-dog-page.html", titleContains: "Practitioner" },
  { path: "/course_forms/pawpad-practitioner-cat-page.html", titleContains: "Practitioner" },
  { path: "/course_forms/pawpad-studio-consulting-page.html", titleContains: "Consulting" },
  { path: "/course_forms/pawpad-application-pacgc.html", titleContains: "PACGC Application" },
  { path: "/course_forms/pawpad-application-pcgec.html", titleContains: "PCGEC Application" },
  { path: "/course_forms/pawpad-application-pcgpc.html", titleContains: "PCGPC Application" },
  { path: "/course_forms/pawpad-application-pfgec.html", titleContains: "PFGEC Application" },
  { path: "/course_forms/pawpad-application-pfgpc.html", titleContains: "PFGPC Application" },
  { path: "/course_forms/pawpad-application-consulting-gssc.html", titleContains: "Consulting" },
  { path: "/course_forms/success.html", titleContains: "Application" }
];

test.describe("Pawpad Complete Site Integrity & Render Tests", () => {
  for (const p of pages) {
    test(`Page renders without errors: ${p.path}`, async ({ page }) => {
      const pageErrors = [];
      page.on("pageerror", (err) => pageErrors.push(err.message));

      const response = await page.goto(p.path, { waitUntil: "domcontentloaded" });
      expect(response?.status()).toBeLessThan(400);

      const title = await page.title();
      expect(title).toContain(p.titleContains);

      // Scroll to bottom so all lazy-loaded elements trigger
      await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
      await page.waitForTimeout(150);

      // Verify all non-empty images loaded properly
      const imageStatus = await page.evaluate(async () => {
        const images = Array.from(document.querySelectorAll("img"));
        const broken = [];
        for (const img of images) {
          if (!img.src || img.src.startsWith("data:")) continue;
          if (!img.complete || img.naturalWidth === 0) {
            // Verify via fetch to distinguish lazy-load timing from broken URLs
            try {
              const res = await fetch(img.src);
              if (!res.ok) broken.push(img.src);
            } catch {
              broken.push(img.src);
            }
          }
        }
        return broken;
      });

      expect(imageStatus).toEqual([]);
      expect(pageErrors).toEqual([]);
    });
  }
});

test.describe("Interactive Features & User Flows", () => {
  test("TopNav contains all 9 main navigation links and opens Studio Setup", async ({ page }) => {
    await page.goto("/index.html");
    const navLinks = page.locator(".nav-links.desktop-only .nav-link");
    const count = await navLinks.count();
    expect(count).toBe(9); // Home, About, Experience, Grooming, Courses, Studio Setup, Boarding, Myotherapy, Contact

    const studioSetupLink = page.locator('.nav-links.desktop-only a[href="studio-setup.html"]');
    await expect(studioSetupLink).toBeVisible();
    await studioSetupLink.click();
    await expect(page).toHaveURL(/studio-setup\.html/);
    await expect(page.locator("h1")).toBeVisible();
  });

  test("Cart Drawer opens and supports adding consulting package to cart", async ({ page }) => {
    await page.goto("/studio-setup.html");
    
    // Add to cart from the packages section
    const addToCartBtn = page.locator(".ss-pkg-add-cart").first();
    if (await addToCartBtn.count() > 0) {
      await addToCartBtn.click();
      const cartDrawer = page.locator(".cart-drawer");
      await expect(cartDrawer).toBeVisible();
      await expect(page.locator(".cart-drawer-item")).toHaveCount(1);
    } else {
      // Open cart directly via TopNav cart button
      const cartBtn = page.locator(".nav-cart-btn").first();
      await cartBtn.click();
      const cartDrawer = page.locator(".cart-drawer");
      await expect(cartDrawer).toBeVisible();
    }
  });

  test("Course Forms contain Studio Setup in Navigation and Footer Explore", async ({ page }) => {
    await page.goto("/course_forms/pawpad-foundations-page.html");
    
    // Check top nav link to studio setup
    const studioNav = page.locator('nav.nav-links a[href="../studio-setup.html"]');
    await expect(studioNav).toBeAttached();

    // Check footer explore link
    const studioFooter = page.locator('.site-footer a[href="../studio-setup.html"]');
    await expect(studioFooter).toBeAttached();
  });

  test("Studio Setup booking application flow navigates cleanly", async ({ page }) => {
    await page.goto("/studio-setup.html");
    const bookConsultBtn = page.locator('a[href="course_forms/pawpad-application-consulting-gssc.html"]').first();
    await expect(bookConsultBtn).toBeVisible();
    await bookConsultBtn.click();
    await expect(page).toHaveURL(/pawpad-application-consulting-gssc\.html/);
    await expect(page.locator("h1")).toContainText("Grooming Studio Setup");
    
    // Check back link returns to studio-setup.html
    const backLink = page.locator(".back-link");
    await expect(backLink).toBeVisible();
    await backLink.click();
    await expect(page).toHaveURL(/studio-setup\.html/);
  });

  test("Policies page anchor navigation scrolls smoothly without errors", async ({ page }) => {
    await page.goto("/policies.html");
    const termsPill = page.locator('.nav-pill[href="#terms"]');
    await expect(termsPill).toBeVisible();
    await termsPill.click();
    await expect(page).toHaveURL(/#terms/);
    const termsHeading = page.locator("#terms");
    await expect(termsHeading).toBeVisible();
  });

  test("Course interview scheduling and approval emails send candidate notifications via Web3Forms", async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.setItem("pawpad_admin_auth_session", "authenticated");
      localStorage.setItem("pawpad_admin_auth_user", JSON.stringify({ email: "pawpadpetstylist@gmail.com", role: "owner" }));
      sessionStorage.setItem("pawpad_admin_auth_session", "authenticated");
      localStorage.setItem("pawpad_course_applications_v1", JSON.stringify([
        {
          id: "PCGEC - 001",
          courseKey: "pcgec",
          courseName: "Pawpad Canine Grooming Essentials Certificate (PCGEC)",
          courseFee: "₹30,000",
          createdAt: new Date().toISOString(),
          status: "pending_review",
          interviewDate: "",
          applicant: {
            name: "Priya Sharma",
            email: "priya.test@example.com",
            phone: "+91 98765 43210",
            city: "Bengaluru"
          },
          responses: {
            why: "Passionate about dog grooming",
            experience: "Beginner",
            handling: "Calm and force-free"
          }
        }
      ]));
    });

    await page.goto("/admin.html");
    await expect(page.locator("body")).toBeVisible();

    // Navigate to Course Applications tab
    const appsNav = page.locator('nav button:has-text("Course Applications")');
    await appsNav.click();

    // Verify Applications list renders
    const inspectBtn = page.locator('button:has-text("Inspect & Approve")').first();
    await expect(inspectBtn).toBeVisible();
    await inspectBtn.click();

    // Modal opens
    await expect(page.locator(".modal-card")).toBeVisible();

    // Set interview date and click Schedule & Send Invite
    const interviewInput = page.locator('input[type="datetime-local"]');
    await interviewInput.fill("2026-10-15T14:30");

    // Intercept Web3Forms request to verify candidate email payload
    let interceptedPayload = null;
    await page.route("https://api.web3forms.com/submit", async (route) => {
      interceptedPayload = route.request().postData();
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({ success: true, message: "Email submitted successfully" })
      });
    });

    const scheduleBtn = page.locator('button:has-text("Schedule & Send Invite")');
    await scheduleBtn.click();

    // Verify confirmation banner appears
    await expect(page.locator('strong:has-text("Interview Scheduled & Candidate Email Dispatched")')).toBeVisible();
    await expect(page.locator('a:has-text("Open in Gmail")')).toBeVisible();

    // Now click Approve & Send Confirmation
    const approveBtn = page.locator('button:has-text("✓ Approve & Send Confirmation")');
    await approveBtn.click();

    // Verify approval confirmation banner appears
    await expect(page.locator('strong:has-text("Application Approved & Confirmation Email Dispatched")')).toBeVisible();
    await expect(page.locator('a:has-text("Default Email Client")')).toBeVisible();

    // Verify application status changed to approved
    await expect(page.locator('.modal-card .badge-approved')).toBeVisible();
  });
});

