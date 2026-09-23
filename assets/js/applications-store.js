/**
 * PawpadApplicationsStore - Course Applications & Admissions Store
 * Handles storage, status lifecycle, notes, filtering, and export for student course applications.
 */

(function(window) {
  const STORAGE_KEY = "pawpad_course_applications_v1";
  const SEQUENCE_KEY = "pawpad_course_application_sequence_v1";

  const INITIAL_SEED_APPLICATIONS = [];
  const LEGACY_PLACEHOLDER_IDS = new Set(["APP-829104", "APP-740192"]);

  class ApplicationsStore {
    constructor() {
      this.applications = this._load();
    }

    _load() {
      try {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved) {
          const parsed = JSON.parse(saved);
          if (Array.isArray(parsed)) {
            const filtered = parsed.filter(app => !LEGACY_PLACEHOLDER_IDS.has(app.id));
            if (filtered.length !== parsed.length) {
              try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(filtered));
              } catch (e) {}
            }
            return filtered;
          }
        }
      } catch (e) {
        console.warn("PawpadApplicationsStore: Could not load applications", e);
      }
      return [];
    }

    _save() {
      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(this.applications));
        window.dispatchEvent(new CustomEvent("pawpad-applications-updated", { detail: this.applications }));
      } catch (e) {
        console.error("PawpadApplicationsStore: Failed to save applications", e);
      }
    }

    /**
     * Extracts a clean uppercase abbreviation/key for a course (e.g. PCGEC, PFGEC, PACGC, CSM)
     * Directly uses the Course Code / Key configured in the admin panel box.
     */
    getCourseAbbreviation(courseKeyOrName, courseData) {
      const inputStr = String(courseKeyOrName || "").trim();

      // 1. First & Top Priority: Match course in PawpadContentStore (Admin Panel configuration)
      try {
        const contentStore = window.PawpadContentStore;
        if (contentStore && typeof contentStore.get === "function") {
          const coursesConfig = contentStore.get("courses");
          if (coursesConfig && Array.isArray(coursesConfig.courseList)) {
            const targetKey = String(courseData?.courseKey || inputStr || "").trim().toLowerCase();
            const targetName = String(courseData?.courseName || inputStr || "").trim().toLowerCase();
            const targetEnroll = String(courseData?.enrollUrl || (typeof window !== "undefined" && window.location ? window.location.pathname : "")).trim().toLowerCase();

            const found = coursesConfig.courseList.find((c) => {
              if (!c) return false;
              const cKey = (c.key || "").trim().toLowerCase();
              const cTitle = (c.title || "").trim().toLowerCase();
              const cEnroll = (c.enrollUrl || "").trim().toLowerCase();
              const cKnowMore = (c.knowMoreUrl || "").trim().toLowerCase();

              if (cKey && targetKey && (cKey === targetKey || targetKey.includes(cKey) || cKey.includes(targetKey.replace(/[^a-z0-9]/g, "")))) {
                return true;
              }
              if (cEnroll && targetEnroll && (targetEnroll.includes(cEnroll) || cEnroll.includes(targetEnroll.split("/").pop()))) {
                return true;
              }
              if (cKnowMore && targetEnroll && (targetEnroll.includes(cKnowMore) || cKnowMore.includes(targetEnroll.split("/").pop()))) {
                return true;
              }
              if (cTitle && targetName && (cTitle === targetName || cTitle.includes(targetName) || targetName.includes(cTitle))) {
                return true;
              }
              return false;
            });

            if (found) {
              // Directly use the Course Code / Key entered by admin in the box
              const adminBoxValue = found.key || found.code || found.shortCode;
              if (adminBoxValue && typeof adminBoxValue === "string" && adminBoxValue.trim()) {
                const clean = adminBoxValue.trim().replace(/[^A-Za-z0-9_\-]/g, "").toUpperCase();
                if (clean.length >= 1) return clean;
              }
            }
          }
        }
      } catch (e) {}

      // 2. Explicit code/key passed in courseData
      if (courseData && typeof courseData === "object") {
        if (typeof courseData.courseCode === "string" && courseData.courseCode.trim()) {
          return courseData.courseCode.trim().replace(/[^A-Za-z0-9_\-]/g, "").toUpperCase();
        }
        if (typeof courseData.code === "string" && courseData.code.trim()) {
          return courseData.code.trim().replace(/[^A-Za-z0-9_\-]/g, "").toUpperCase();
        }
        if (typeof courseData.shortCode === "string" && courseData.shortCode.trim()) {
          return courseData.shortCode.trim().replace(/[^A-Za-z0-9_\-]/g, "").toUpperCase();
        }
        if (typeof courseData.key === "string" && courseData.key.trim() && !courseData.key.startsWith("course-")) {
          const cleanKey = courseData.key.trim().replace(/[^A-Za-z0-9_\-]/g, "").toUpperCase();
          if (cleanKey.length >= 1) {
            return cleanKey;
          }
        }
      }

      // 3. Check for parentheses in input string e.g. "Canine Grooming (PCGEC)"
      const parenMatch = inputStr.match(/\(([A-Za-z0-9_\-]{2,10})\)/);
      if (parenMatch) {
        return parenMatch[1].toUpperCase();
      }

      // 4. Dot or separator syntax e.g. "Application · PCGEC" or "Booking · GSSC"
      const dotMatch = inputStr.match(/[·\-\|]\s*([A-Za-z0-9_\-]{2,10})(?:\s*$|\s*[·\-\|])/);
      if (dotMatch) {
        return dotMatch[1].toUpperCase();
      }

      // 5. If input string itself is a clean short code (2 to 8 characters)
      const cleanInput = inputStr.replace(/[^A-Za-z0-9_\-]/g, "").toUpperCase();
      if (cleanInput.length >= 2 && cleanInput.length <= 8 && !cleanInput.startsWith("COURSE")) {
        return cleanInput;
      }

      // 6. Generate initials/acronym from words in course title (filtering out common filler words)
      const stopWords = new Set(["AND", "&", "OF", "IN", "THE", "FOR", "ON", "A", "AN", "AT", "TO", "APPLICATION", "PROGRAMME", "PROGRAM", "COURSE"]);
      const words = inputStr.split(/[\s\-_]+/).filter((w) => w.length > 0 && !stopWords.has(w.toUpperCase()));
      if (words.length >= 2) {
        const acronym = words.map((w) => w[0]).join("").toUpperCase();
        if (acronym.length >= 2 && acronym.length <= 8) {
          return acronym;
        }
      }

      return cleanInput.slice(0, 6) || "APP";
    }

    getNextApplicationId(courseKeyOrName, courseData) {
      const prefix = this.getCourseAbbreviation(courseKeyOrName, courseData) || "APP";
      const safePrefix = prefix.replace(/[^A-Za-z0-9_\-]/g, "").toUpperCase() || "APP";
      const seqKey = `pawpad_course_seq_${safePrefix.toLowerCase().replace(/[^a-z0-9]/g, "_")}`;

      let maxNum = 0;
      try {
        const savedSeq = localStorage.getItem(seqKey);
        if (savedSeq) {
          const parsed = parseInt(savedSeq, 10);
          if (!isNaN(parsed) && parsed > 0) {
            maxNum = Math.max(maxNum, parsed - 1);
          }
        }
      } catch (e) {}

      if (Array.isArray(this.applications)) {
        const escaped = safePrefix.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
        const pattern = new RegExp(`^${escaped}\\s*-\\s*(\\d+)`, "i");
        for (const app of this.applications) {
          if (app && typeof app.id === "string") {
            const match = app.id.match(pattern);
            if (match) {
              const val = parseInt(match[1], 10);
              if (!isNaN(val) && val < 100000 && val > maxNum) {
                maxNum = val;
              }
            }
          }
        }
      }

      const nextNum = maxNum + 1;
      try {
        localStorage.setItem(seqKey, String(nextNum + 1));
      } catch (e) {}

      return `${safePrefix} - ${String(nextNum).padStart(3, "0")}`;
    }

    getAll() {
      return [...this.applications].sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt));
    }

    getById(id) {
      return this.applications.find((a) => a.id === id) || null;
    }

    submitApplication(formData) {
      const courseKeyOrName = formData.courseCode || formData.courseKey || formData.courseName || "";
      const prefix = this.getCourseAbbreviation(courseKeyOrName, formData);
      const id = this.getNextApplicationId(courseKeyOrName, formData);
      const newApp = {
        id: id,
        courseKey: formData.courseKey || prefix.toLowerCase(),
        courseCode: prefix,
        courseName: formData.courseName || "Pawpad Grooming Certification",
        courseFee: formData.courseFee || "₹95,000",
        createdAt: new Date().toISOString(),
        status: "pending_review",
        interviewDate: "",
        applicant: {
          name: formData.name || "",
          phone: formData.phone || "",
          email: formData.email || "",
          city: formData.city || ""
        },
        responses: {
          why: formData.why || "",
          experience: formData.experience || "",
          handling: formData.handling || "",
          careerFit: formData.careerFit || "yes",
          healthDisclosure: formData.healthDisclosure || ""
        },
        acknowledgments: formData.acknowledgments || {},
        staffNotes: [
          {
            author: "System",
            date: new Date().toISOString(),
            text: "Application submitted online."
          }
        ]
      };

      this.applications.unshift(newApp);
      this._save();
      return newApp;
    }

    updateStatus(id, newStatus, noteText = "", interviewDate = "") {
      const app = this.getById(id);
      if (!app) return null;

      const oldStatus = app.status;
      app.status = newStatus;

      if (interviewDate !== undefined) {
        app.interviewDate = interviewDate;
      }

      if (!app.staffNotes) app.staffNotes = [];

      const statusLabels = {
        pending_review: "Pending Review",
        interview_scheduled: "Interview Scheduled",
        approved: "Approved & Accepted",
        rejected: "Rejected / Declined",
        enrolled: "Enrolled & Deposit Confirmed"
      };

      const logText = `Status changed from '${statusLabels[oldStatus] || oldStatus}' to '${statusLabels[newStatus] || newStatus}'.` + (noteText ? ` Note: ${noteText}` : "");

      app.staffNotes.push({
        author: "Admin",
        date: new Date().toISOString(),
        text: logText
      });

      this._save();
      return app;
    }

    addNote(id, noteText, author = "Admin") {
      const app = this.getById(id);
      if (!app) return null;
      if (!app.staffNotes) app.staffNotes = [];
      app.staffNotes.push({
        author: author,
        date: new Date().toISOString(),
        text: noteText
      });
      this._save();
      return app;
    }

    deleteApplication(id) {
      this.applications = this.applications.filter((a) => a.id !== id);
      this._save();
      return true;
    }

    deleteMultiple(ids) {
      if (!Array.isArray(ids) || ids.length === 0) return true;
      const idSet = new Set(ids);
      this.applications = this.applications.filter((a) => !idSet.has(a.id));
      this._save();
      return true;
    }

    formatInterviewDate(dateStr) {
      if (!dateStr) return "To be mutually coordinated";
      try {
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString("en-IN", {
          weekday: "long",
          year: "numeric",
          month: "long",
          day: "numeric",
          hour: "2-digit",
          minute: "2-digit",
          hour12: true
        });
      } catch {
        return dateStr;
      }
    }

    getCoursesAccessKey() {
      const storeCoursesKey = window.PawpadContentStore && window.PawpadContentStore.get("courses")?.web3FormsAccessKey;
      return (storeCoursesKey && storeCoursesKey !== "YOUR_ACCESS_KEY_HERE" && storeCoursesKey !== "ce70cafb-d84c-42f7-b57e-d320ff768866")
        ? storeCoursesKey
        : "a9a21b4b-47ee-4889-b709-9f101c59874d";
    }

    generateInterviewEmail(app, interviewDate, customNote = "") {
      const candidateName = app.applicant?.name || "Applicant";
      const courseName = app.courseName || "Pawpad Grooming Certification";
      const formattedDate = this.formatInterviewDate(interviewDate || app.interviewDate);
      const subject = `Interview Scheduled: ${courseName} - Pawpad Academy (${app.id})`;

      const body = `Dear ${candidateName},

Thank you for applying for the ${courseName} at Pawpad Academy (Application Reference: ${app.id}).

We are pleased to invite you for an admissions interaction & interview. Here are your scheduled interview details:

• Course: ${courseName}
• Scheduled Date & Time: ${formattedDate}
• Format: 15–20 minute video call / admissions interaction
• Meeting Link / Venue: The video meeting link will be shared with you via email and WhatsApp shortly prior to the session.
${customNote ? `• Note from Admissions: ${customNote}\n` : ""}
What to Expect:
- Discussion on your background, interest in professional grooming/pet care, and career goals.
- Review of program format, practical hands-on structure, and course expectations.
- Answering any questions you may have regarding the curriculum, equipment, or schedule.

If you need to reschedule or have any questions in the meantime, please reply to this email or message our admissions desk on WhatsApp at +91 98451 23456.

Warm regards,
Admissions Team
Pawpad Academy
Bengaluru, India
Website: https://pawpad.in
Email: courses@pawpad.in`;

      const candidateEmail = app.applicant?.email || "";
      const mailtoUrl = `mailto:${encodeURIComponent(candidateEmail)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
      const gmailUrl = `https://mail.google.com/mail/?view=cm&fs=1&to=${encodeURIComponent(candidateEmail)}&su=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;

      return {
        subject,
        body,
        recipient: candidateEmail,
        recipientName: candidateName,
        formattedDate,
        mailtoUrl,
        gmailUrl
      };
    }

    generateApprovalEmail(app, customNote = "") {
      const candidateName = app.applicant?.name || "Applicant";
      const courseName = app.courseName || "Pawpad Grooming Certification";
      const fee = app.courseFee || "₹95,000";
      const subject = `Congratulations! Your Application for ${courseName} is Approved — Pawpad Academy (${app.id})`;

      const body = `Dear ${candidateName},

Congratulations! We are delighted to inform you that your application for the ${courseName} has been formally approved by the Pawpad Admissions Committee!

Application & Course Details:
• Program: ${courseName}
• Application Reference: ${app.id}
• Total Program Tuition / Fee: ${fee}
• Location: Pawpad Academy, Bengaluru
• Status: Approved & Accepted for Enrollment
${customNote ? `• Note from Admissions: ${customNote}\n` : ""}
Next Steps to Secure Your Seat:
1. Seat Reservation Deposit: To confirm your seat in the upcoming cohort, please submit the seat reservation deposit.
2. Batch & Schedule Alignment: Our admissions desk will reach out via WhatsApp / phone (+91 98451 23456) to align your preferred batch dates and training schedule.
3. Student Kit & Preparation Guide: Once the deposit is received, your student onboarding packet and preparation guidelines will be issued.

If you have any questions or are ready to proceed with the seat deposit, please reply to this email or message our admissions desk directly on WhatsApp (+91 98451 23456).

We are thrilled to welcome you to Pawpad Academy!

Warmest regards,
Admissions & Training Committee
Pawpad Academy
Bengaluru, India
Website: https://pawpad.in
Email: courses@pawpad.in`;

      const candidateEmail = app.applicant?.email || "";
      const mailtoUrl = `mailto:${encodeURIComponent(candidateEmail)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
      const gmailUrl = `https://mail.google.com/mail/?view=cm&fs=1&to=${encodeURIComponent(candidateEmail)}&su=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;

      return {
        subject,
        body,
        recipient: candidateEmail,
        recipientName: candidateName,
        fee,
        mailtoUrl,
        gmailUrl
      };
    }

    async sendCandidateEmailViaWeb3(payloadData) {
      const accessKey = this.getCoursesAccessKey();
      const formData = new FormData();
      formData.append("access_key", accessKey);
      formData.append("subject", payloadData.subject || "Pawpad Academy Notification");
      formData.append("from_name", "Pawpad Academy Admissions");
      formData.append("to_candidate", payloadData.recipient || "");
      formData.append("candidate_name", payloadData.recipientName || "");
      formData.append("candidate_email", payloadData.recipient || "");
      formData.append("name", payloadData.recipientName || "");
      formData.append("email", payloadData.recipient || "");
      formData.append("replyto", "courses@pawpad.in");
      formData.append("application_id", payloadData.applicationId || "");
      formData.append("course", payloadData.courseName || "");
      formData.append("notification_type", payloadData.type || "course_notification");
      formData.append("message", payloadData.body || "");
      formData.append("botcheck", "");

      try {
        const response = await fetch("https://api.web3forms.com/submit", {
          method: "POST",
          body: formData
        });
        const json = await response.json().catch(() => ({}));
        return { success: response.ok, data: json };
      } catch (err) {
        console.warn("PawpadApplicationsStore: Web3Forms candidate email notice:", err);
        return { success: false, error: err.message };
      }
    }

    async scheduleInterviewWithEmail(id, interviewDate, customNote = "") {
      const app = this.getById(id);
      if (!app) return { success: false, error: "Application not found" };

      app.status = "interview_scheduled";
      app.interviewDate = interviewDate;

      const emailData = this.generateInterviewEmail(app, interviewDate, customNote);
      
      // Dispatch via Web3Forms courses access key
      const web3Result = await this.sendCandidateEmailViaWeb3({
        applicationId: app.id,
        courseName: app.courseName,
        recipient: emailData.recipient,
        recipientName: emailData.recipientName,
        subject: emailData.subject,
        body: emailData.body,
        type: "interview_scheduled_notification"
      });

      if (!app.staffNotes) app.staffNotes = [];
      const noteEntry = {
        author: "Admin / System",
        date: new Date().toISOString(),
        text: `Interview scheduled for ${emailData.formattedDate}. Candidate notification email dispatched to ${emailData.recipient || "applicant"} via Courses Web3Forms.` + (customNote ? ` Note: ${customNote}` : "")
      };
      app.staffNotes.push(noteEntry);

      if (!app.communications) app.communications = [];
      app.communications.push({
        type: "interview_scheduled",
        date: new Date().toISOString(),
        recipient: emailData.recipient,
        subject: emailData.subject,
        body: emailData.body,
        web3Status: web3Result.success ? "sent" : "dispatched_client_fallback"
      });

      this._save();
      return { success: true, app, emailData, web3Result };
    }

    async approveApplicationWithEmail(id, customNote = "") {
      const app = this.getById(id);
      if (!app) return { success: false, error: "Application not found" };

      app.status = "approved";

      const emailData = this.generateApprovalEmail(app, customNote);
      
      // Dispatch via Web3Forms courses access key
      const web3Result = await this.sendCandidateEmailViaWeb3({
        applicationId: app.id,
        courseName: app.courseName,
        recipient: emailData.recipient,
        recipientName: emailData.recipientName,
        subject: emailData.subject,
        body: emailData.body,
        type: "application_approved_notification"
      });

      if (!app.staffNotes) app.staffNotes = [];
      const noteEntry = {
        author: "Admin / System",
        date: new Date().toISOString(),
        text: `Application formally approved. Confirmation & enrollment details email dispatched to ${emailData.recipient || "applicant"} via Courses Web3Forms.` + (customNote ? ` Note: ${customNote}` : "")
      };
      app.staffNotes.push(noteEntry);

      if (!app.communications) app.communications = [];
      app.communications.push({
        type: "application_approved",
        date: new Date().toISOString(),
        recipient: emailData.recipient,
        subject: emailData.subject,
        body: emailData.body,
        web3Status: web3Result.success ? "sent" : "dispatched_client_fallback"
      });

      this._save();
      return { success: true, app, emailData, web3Result };
    }

    getStats() {
      const total = this.applications.length;
      const pending = this.applications.filter((a) => a.status === "pending_review").length;
      const interview = this.applications.filter((a) => a.status === "interview_scheduled").length;
      const approved = this.applications.filter((a) => a.status === "approved").length;
      const rejected = this.applications.filter((a) => a.status === "rejected").length;
      const enrolled = this.applications.filter((a) => a.status === "enrolled").length;

      return { total, pending, interview, approved, rejected, enrolled };
    }

    exportCSV() {
      const headers = ["Application ID", "Date", "Status", "Course", "Candidate Name", "Phone", "Email", "City", "Why Apply", "Experience", "Handling Philosophy"];
      const rows = this.getAll().map((app) => [
        `"${app.id}"`,
        `"${new Date(app.createdAt).toLocaleDateString()}"`,
        `"${app.status}"`,
        `"${(app.courseName || "").replace(/"/g, '""')}"`,
        `"${(app.applicant?.name || "").replace(/"/g, '""')}"`,
        `"${(app.applicant?.phone || "").replace(/"/g, '""')}"`,
        `"${(app.applicant?.email || "").replace(/"/g, '""')}"`,
        `"${(app.applicant?.city || "").replace(/"/g, '""')}"`,
        `"${(app.responses?.why || "").replace(/"/g, '""')}"`,
        `"${(app.responses?.experience || "").replace(/"/g, '""')}"`,
        `"${(app.responses?.handling || "").replace(/"/g, '""')}"`
      ]);

      return [headers.join(","), ...rows.map((r) => r.join(","))].join("\n");
    }
  }

  window.PawpadApplicationsStore = new ApplicationsStore();

})(window);
