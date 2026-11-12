# PUP eRecords
 
**PUP eRecords** is a web-based student records management system for the **Polytechnic University of the Philippines – Biñan Campus**. It helps the Admission and Registration Office store, check and archive student admission documents, track requirement completeness, and keep students informed by email.
 
The system runs locally on **XAMPP (Apache + MySQL + PHP)** on Windows. Its main features keep working without an internet connection.

---
 
## User Roles
 
| Role | Description |
|---|---|
| **Super Admin** | Manages Admin and OJT Intern accounts (User Management), programs offered, document requirements, the report template, activity logs, the dashboard and database backups. Cannot open student documents. |
| **Admin** | Registrar staff. Manages student records, documents, scanning, reports and the archive. |
| **OJT Intern** | Limited access. Can search, add and view students, update student records, and upload, scan or replace documents. |

---

## Features

### Student records
- **Student Directory** with search, filters and pagination (20 records per page).
- **Search & Add Student** page for OJT Interns.
- **Admission tracks**: regular (SHS / ALS), shiftee and transferee, each with its own list of required documents.
- **Student status**: Active, Graduate, Withdrawn, Leave of Absence and Transferred.
- **Archived Records** with course, year and status filters.

### Documents
- **Upload or scan** student documents as PDF.
- **Automatic document type detection** using local OCR (Tesseract). Each requirement has keywords stored in the database. Keywords for new requirements are generated automatically from the label and AI hint.
- **AI extraction** reads student details from SAR, PSA birth certificate, Form 2, Health Information Form, Route and Approval Slip, Honorable Dismissal, TOR and Shifting Form. AI is also used when OCR cannot identify a document.
- **Offline mode**: when there is no internet, documents are still uploaded and the type is chosen manually. The Health Information Form is not uploaded offline.
- **Student photo** taken automatically from the SAR form or Health Information Form, with a Remove photo button.
- **Requirement completeness** shown for each student based on the admission track.

### Scanning
- Scan directly from a connected scanner (watch folder or NAPS2).
- Add pages, rescan a single page, **remove pages**, and scan several documents in one session.
- Black scanner borders are trimmed automatically.

### Accounts and security
- **Sign-in lock**: 3 wrong passwords lock the email for 5 minutes. The same message is shown whether or not the email has an account.
- **Two-step verification** on sign-in:
  - A 6-digit **email code** (valid for 10 minutes).
  - **Google Authenticator** as the backup when there is no internet. It is set up once on the first sign-in.
  - **Trust this computer for 30 days** so codes are not asked every time. Trusted computers are cleared when the password changes.
- **First sign-in password change** for new Admin and OJT Intern accounts.
- **Change password** for the Super Admin (5 wrong attempts lock it for 5 minutes).
- **Deactivate / Activate** accounts. A deactivated user is signed out automatically.
- **Activity logs** for sign-ins, record changes, emails and account actions.
- Sessions do not time out on their own.

---

## Requirements

| Software | Purpose |
|---|---|
| **XAMPP** (PHP 8, Apache, MySQL/MariaDB) | Runs the system |
| **Tesseract OCR** | Local document type detection |
| **Poppler (`pdftoppm`, `pdftotext`)** | Reads and renders PDF pages |
| **NAPS2** (optional) | Scanner support |
| **PHPMailer 7.0.1** (included) | Sends emails through Gmail |
| **Google Authenticator** (phone app) | Sign-in code when there is no internet |

The PHP GD extension is **not** required.

---

## First sign-in of each account

Every account, including the Super Admin, should sign in once **while connected to the internet**:

1. Enter the email and password.
2. Enter the code sent to the email.
3. Scan the QR code with Google Authenticator and enter the code from the app.

After this, the account can sign in without internet using Google Authenticator.
