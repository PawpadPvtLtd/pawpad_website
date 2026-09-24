/**
 * Pawpad Forms Handler
 * Automatically binds to course application forms and standard forms,
 * persisting submissions into PawpadApplicationsStore and coordinating FormSubmit email delivery.
 */

(function() {
  function initCourseForms() {
    const forms = document.querySelectorAll("form");
    if (!forms.length) return;

    forms.forEach((form) => {
      // Avoid attaching twice
      if (form.dataset.pawpadBound) return;
      form.dataset.pawpadBound = "true";

      form.addEventListener("submit", async function(e) {
        e.preventDefault();
        // Ignore a second Enter / click while the first submission is still being saved
        if (form.dataset.pawpadSubmitting) return;
        form.dataset.pawpadSubmitting = "true";

        // Extract course name from document title or h1
        const h1 = document.querySelector("h1");
        const eyebrow = document.querySelector(".eyebrow");
        const courseName = h1 ? h1.innerText.trim() : document.title.replace(" Application", "");
        
        let courseCode = "";
        let courseKey = "";

        // 1. Check eyebrow e.g. "Application · PCGEC"
        if (eyebrow && eyebrow.innerText) {
          const eyeMatch = eyebrow.innerText.match(/[·\-\|]\s*([A-Za-z0-9]+)/);
          if (eyeMatch) courseCode = eyeMatch[1].trim().toUpperCase();
        }

        // 2. Check _subject hidden input e.g. "New PCGEC Application"
        const subjInput = form.querySelector('input[name="_subject"]');
        if (subjInput && subjInput.value) {
          const subjMatch = subjInput.value.match(/New\s+([A-Za-z0-9]+)(?:\s+\(.*?\))?\s+Application/i);
          if (subjMatch && !courseCode) courseCode = subjMatch[1].trim().toUpperCase();
        }

        // 3. Check document pathname e.g. "pawpad-application-pcgec.html"
        const pathMatch = window.location.pathname.match(/pawpad-application-([a-z0-9\-]+)\.html/i);
        if (pathMatch) {
          courseKey = pathMatch[1].replace(/^consulting-/, "");
          if (!courseCode) courseCode = courseKey.toUpperCase();
        }

        if (!courseKey) {
          courseKey = (courseCode || (eyebrow ? eyebrow.innerText : document.title)).toLowerCase().replace(/[^a-z0-9]/g, "-");
        }

        // Extract course fee if present in sub heading
        const sub = document.querySelector(".sub");
        let courseFee = "₹95,000";
        if (sub && sub.innerText.includes("₹")) {
          const m = sub.innerText.match(/₹[\d,]+/);
          if (m) courseFee = m[0];
        }
        // Consulting has two formats with different fees; use the one the applicant picked
        const chosenFormat = form.querySelector('input[name="consultation_format"]:checked');
        if (chosenFormat) {
          const fm = chosenFormat.value.match(/₹[\d,]+/);
          if (fm) courseFee = /\/\s*day/i.test(chosenFormat.value) ? `${fm[0]} / day` : fm[0];
        }

        const formData = new FormData(form);
        const acks = {};
        const responses = {};

        formData.forEach((val, key) => {
          if (key.startsWith("ack_") || key.startsWith("ack")) {
            acks[key] = true;
          } else {
            responses[key] = val;
          }
        });

        const appData = {
          courseKey: courseKey,
          courseCode: courseCode,
          courseName: courseName,
          courseFee: courseFee,
          name: formData.get("name") || "",
          phone: formData.get("phone") || "",
          email: formData.get("email") || "",
          city: formData.get("city") || "",
          why: formData.get("why") || "",
          experience: formData.get("experience") || "",
          handling: formData.get("handling") || "",
          careerFit: formData.get("career_fit") || formData.get("careerFit") || "yes",
          healthDisclosure: formData.get("health_disclosure") || formData.get("health") || "",
          acknowledgments: acks
        };

        // Visual feedback on submit button
        const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
        if (submitBtn) {
          submitBtn.disabled = true;
          if (submitBtn.tagName === "BUTTON") {
            submitBtn.textContent = "Submitting Application...";
          } else {
            submitBtn.value = "Submitting Application...";
          }
        }

        const coursePrefix = window.PawpadApplicationsStore
          ? window.PawpadApplicationsStore.getCourseAbbreviation(courseCode || courseKey || courseName, appData)
          : (courseCode || "APP").toUpperCase();

        // Old behaviour, used only when the server can't be reached: keep a copy in this browser.
        const saveLocally = () => {
          let localId = "";
          if (window.PawpadApplicationsStore) {
            const created = window.PawpadApplicationsStore.submitApplication(appData);
            localId = created ? created.id : "";
            console.log("Pawpad: Application captured in local store with ID:", localId);
          }
          return localId || `${coursePrefix} - 001`;
        };

        // Save on the Pawpad server, which gives the real sequential application ID.
        const saveOnServer = async () => {
          if (!window.PawpadApi || !window.PawpadApi.isEnabled()) return "";
          const serverResponses = {
            why: appData.why,
            experience: appData.experience,
            handling: appData.handling,
            careerFit: appData.careerFit,
            healthDisclosure: appData.healthDisclosure
          };
          const skip = ["name", "email", "phone", "city", "why", "experience", "handling", "career_fit", "careerFit", "health_disclosure", "health", "botcheck"];
          Object.keys(responses).forEach((key) => {
            if (!key.startsWith("_") && !skip.includes(key) && typeof responses[key] === "string") {
              serverResponses[key] = responses[key];
            }
          });
          const result = await window.PawpadApi.call("submit_application", {
            courseKey: courseKey,
            courseCode: coursePrefix,
            courseName: courseName,
            courseFee: courseFee,
            applicant: { name: appData.name, phone: appData.phone, email: appData.email, city: appData.city },
            responses: serverResponses,
            acknowledgments: acks,
            botcheck: ""
          });
          if (result.ok && result.data.id) {
            console.log("Pawpad: Application saved on the server with ID:", result.data.id);
            return result.data.id;
          }
          return "";
        };

        const createdId = (await saveOnServer()) || saveLocally();

        const nextInput = form.querySelector('input[name="_next"]');
        const nextTarget = (nextInput && nextInput.value) ? nextInput.value : "success.html";
        const encodedAppId = encodeURIComponent(createdId);
        const dest = nextTarget.includes("?") 
          ? `${nextTarget}&app_id=${encodedAppId}` 
          : `${nextTarget}?app_id=${encodedAppId}`;

        const storeCoursesKey = window.PawpadContentStore && window.PawpadContentStore.get("courses")?.web3FormsAccessKey;
        const web3Key = (storeCoursesKey && storeCoursesKey !== "YOUR_ACCESS_KEY_HERE" && storeCoursesKey !== "ce70cafb-d84c-42f7-b57e-d320ff768866") 
          ? storeCoursesKey 
          : "a9a21b4b-47ee-4889-b709-9f101c59874d";

        const web3FormData = new FormData();
        web3FormData.append("access_key", web3Key);
        web3FormData.append("subject", `New Application: ${courseName} - ${appData.name || "Applicant"} (${createdId})`);
        web3FormData.append("from_name", "Pawpad Academy Applications");
        web3FormData.append("application_id", createdId);
        web3FormData.append("course", courseName);
        web3FormData.append("fee", courseFee);
        web3FormData.append("name", appData.name);
        web3FormData.append("email", appData.email);
        web3FormData.append("phone", appData.phone);
        web3FormData.append("city", appData.city);
        web3FormData.append("why_apply", appData.why);
        web3FormData.append("experience", appData.experience);
        web3FormData.append("handling_comfort", appData.handling);
        web3FormData.append("career_fit", appData.careerFit);
        web3FormData.append("health_disclosure", appData.healthDisclosure);
        web3FormData.append("acknowledgments", Object.keys(acks).join(", ") || "Confirmed");

        // Forward every other submitted field (consulting details, physical capability, etc.)
        // so nothing the applicant filled in is dropped. Fields already sent above are skipped.
        const alreadySent = ["name", "email", "phone", "city", "why", "experience", "handling", "career_fit", "careerFit", "health_disclosure", "health", "botcheck", "access_key"];
        Object.keys(responses).forEach((key) => {
          if (key.startsWith("_") || alreadySent.includes(key)) return;
          const value = responses[key];
          if (typeof value === "string" && value.trim() !== "") {
            web3FormData.append(key, value);
          }
        });
        web3FormData.append("botcheck", "");

        // Submit to Web3Forms API
        fetch("https://api.web3forms.com/submit", {
          method: "POST",
          body: web3FormData
        })
        .then((res) => {
          if (!res.ok) {
            throw new Error("Web3Forms response status: " + res.status);
          }
          return res.json();
        })
        .then((data) => {
          console.log("Pawpad: Web3Forms submission result:", data);
        })
        .catch((err) => {
          console.warn("Pawpad: Web3Forms submit notice, attempting email fallback:", err);
          // Fallback to FormSubmit to guarantee email delivery
          const fallbackUrl = "https://formsubmit.co/ajax/courses@pawpad.in";
          return fetch(fallbackUrl, {
            method: "POST",
            headers: { "Accept": "application/json" },
            body: formData
          }).catch((fErr) => {
            console.warn("Pawpad: Fallback submit warning:", fErr);
          });
        })
        .finally(() => {
          window.location.href = dest;
        });
      });
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initCourseForms);
  } else {
    initCourseForms();
  }
})();
