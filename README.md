# Systems Plus Computer College — Grading and Academic Evaluation

This site is the college grading sheet and academic evaluation system for Systems Plus Computer College. Faculty encode prelim, midterm, and finals the way the class workbook does. Students see their honor standing, and see the actual scores only after the professor allows it. College offices review the submitted record, record Dean’s List and President’s List decisions, and manage accounts.

The home page opens three portals: Students, Professors, and College offices.

The site runs on Apache, PHP 8.2 or newer, and MySQL or MariaDB. Locally that is XAMPP. On a host, point the domain at this folder over HTTPS.

## Portals

| Portal | Sign-in | Who |
| --- | --- | --- |
| Students | `student/login.php` | Enrolled students |
| Professors | `professor/login.php` | Faculty assigned to a class |
| College offices | `admin/login.php` | System Administrator, Program Chair, College OIC, Secretary |

Office roles that share the administrative pages are `system_admin`, `program_chair`, `college_oic`, and `osa`. Program chairs are limited to the program assigned on their account. The other office roles can see the college.

## Student portal

Students sign in with the student number and the password issued by the office. The starting password for a new portal account is `123`. After the first sign-in, the student changes it under **Profile** at the bottom of the sidebar.

The dashboard shows the student’s classes for the selected term.

- Period scores, the final grade, pass or fail, GWA, and the score breakdown stay hidden until the professor for that class opens the eye on the grade sheet.
- **Subject Result** is always visible. It is **At risk**, **Not at risk**, or **Pending**.
- Under the status, a short explanation names the subject and the areas that produced that standing, such as quizzes or examinations. The explanation does not show the numeric grade.
- The term banner shows the same standing once, in a single colored box. It does not repeat the status in extra cards, and it does not show cutoff numbers.

**Profile** shows the name, student number, program, and recovery email. The recovery email is read-only. The office changes it. The student can replace the current password here. The new password must be at least 6 characters and cannot be `123`.

**Forgot password** is on the sign-in page.

1. The student enters the student number and completes the reCAPTCHA.
2. If a recovery email is on the record, a 6-digit code is sent from the college Gmail account. The code expires in 15 minutes.
3. The student enters the code in six separate boxes, then chooses a new password.
4. Five wrong attempts invalidate the code. The student must request a new one.

If mail is not configured, the page tells the student to ask the office. The office can reset the portal password to `123` from the student record.

## Professor portal

A professor sees only the classes assigned to that faculty account. Opening a class shows the grading sheet for prelim, midterm, or finals.

Each score box accepts whole numbers from 0 up to the maximum printed under that column. A column marked `/10` accepts 0–10. A column marked `/20` accepts 0–20. A higher digit is not entered, and the server rejects a score outside that range.

**Student grade visibility** sits on the class sheet. Ten students are listed at a time, with page numbers when the class is longer.

- A closed eye hides that student’s grades.
- An open eye lets that student see the grades for this class.
- **Allow all** and **Hide all** apply to the whole class.

The eye changes one student immediately. Students still see At risk or Not at risk while the numbers are hidden.

A professor can install the site from the browser. After a class is opened online, scores typed while offline stay on the device as drafts and sync when the connection returns. A period should be submitted only after those drafts have synced. Category percents still need a connection.

## College offices

| Area | What it is for |
| --- | --- |
| Dashboard | Counts for the office’s scope |
| Review queue | Sheets waiting for the current office |
| DL / PL | Honor evaluations |
| Recognition | Dean’s List and President’s List workflow |
| Students | Masterlist, filters, and **View Info** |
| Faculty | Professors and class assignments |
| Academics | Programs, sections, subjects, and terms |
| DL / PL criteria | Honor rules for a term |
| Grade equivalent | Percent-to-equivalent scale |
| System settings | Workflow options |
| Import | CSV import into the student masterlist |
| User management | Office accounts and new student portal accounts |
| Scholarships | Discount assignments after an official honor evaluation |
| Reports | Exported views of the workflow |
| Audit | Activity log |

**User management** is where a student portal account is created. The student asks an office user, and provides a Gmail or school email. The form asks for the student number, name, recovery email, program, year, section, and assigned professor. The starting password is `123`. Saving the account also places the student on that professor’s grade sheets for the selected section when a matching class exists.

The Students page lists records that already exist. **View Info** opens the student. The office can update the academic record and the recovery email, and can reset the portal password to `123`.

## How a grade is computed

The sheet matches the faculty workbook.

- Columns are Attendance, Recitation, Activities, Quizzes, Project, and Exam, repeated for Prelim, Midterm, and Finals.
- A blank cell is not encoded yet and is left out of the total. A typed `0` is a real zero.
- A category percentage is points earned divided by points possible, then limited by that column’s maximum score.
- The period percentage is the weighted sum of those categories. The demo weights are 10 / 10 / 10 / 15 / 15 / 40.
- The term percentage uses the term shares. The demo default is prelim 30, midterm 30, and finals 40. That split is a demo default, not a confirmed college rule.
- The equivalent table on the sheet runs from 98–100% = 1.00 Excellent through 75–76% = 3.00 Passed. Below 75% is 5.00 Failed.
- Remarks are PASSED, FAILED, or INC.

## Honor standing

GWA is the average of subject equivalents. A subject result of 2.00 or higher, or a failing mark, puts that subject at risk. GWA 1.00–1.25 is Possible PL. GWA 1.26–1.75 is Possible DL. Regular and irregular students use the same ranges.

Students do not see those cutoff numbers. They see **At risk**, **Not at risk**, or **Pending**, plus an explanation built from the categories that are actually weak or strong in that subject.

GWA and pass or fail counts on the student dashboard stay hidden until every class in the term has been released. A partial release does not reveal an average that would include hidden classes.

## Review path

The professor submits a complete class. It then moves to the Program Chair, the College OIC, and the Secretary. A return sends the sheet back to the professor with a required note. The Secretary’s final approval writes the official grade and locks the sheet.

A scholarship percentage can be assigned only after the official evaluation is Possible DL, Possible PL, or Final Approved.

## Accounts and mail

Office accounts are created in **User management** with a temporary password of at least 8 characters. Professors are created from **Faculty**, not from that form.

Student recovery mail uses Gmail SMTP. In `.env`:

```
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USER=the-sending-account@gmail.com
MAIL_PASS=the-app-password
MAIL_FROM=the-sending-account@gmail.com
MAIL_FROM_NAME=Systems Plus Computer College
```

`MAIL_USER` and `MAIL_FROM` are the office Gmail that sends the code. They are not the student’s recovery address. The app password is created on that same Google account, with the spaces removed. The student’s own email is stored on the student record and is where the code is delivered.

reCAPTCHA keys are `RECAPTCHA_SITE_KEY` and `RECAPTCHA_SECRET_KEY`. Leave them empty only for a local install that should skip the check.

## Install

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Open phpMyAdmin at `http://localhost/phpmyadmin`.
3. Create and open a database named `spcc_academic`, then import `database/database.sql`. The file does not create the database, because shared hosts block that.
4. Copy `.env.example` to `.env` and set the database values.
5. Open `http://localhost/Grade%20Predict/`.

On an existing database, the first page that loads student sign-in or an office page adds any missing columns for recovery email, grade visibility, and password-reset codes.

## Hosting

1. Upload the full project folder, including `.env`. Shared hosts sometimes skip hidden files, so confirm `.env` is on the server.
2. In the host phpMyAdmin, open the database you were given, then import `database/database.sql` into that open database.
3. Keep `APP_DEBUG=false` on the host.
4. Serve the site with HTTPS. The installable app and offline drafts need a secure address.
5. Use SMTP port 587 with TLS. Other mail ports are often blocked.

## Endpoints used by the pages

| Endpoint | Use |
| --- | --- |
| `api/score.php` | Saves a score, a category percent, or a batch of scores from the grading sheet |
| `api/draft.php` | Confirms the professor session before offline drafts sync |
| `admin/audit.php?live=1` | Returns new audit rows for the activity page |
| Google reCAPTCHA | Checks “I’m not a robot” on student sign-in and forgot password |
| Gmail SMTP | Sends the 6-digit recovery code |

## Demo accounts

Every sample account uses the password `123`. These are sample records, not official college data.

| Portal | Username | Who |
| --- | --- | --- |
| Student | `04-24-32733` | Dela Cruz, Reena DC (BSIT 4th Year) |
| Student | `08-15-41258` | Agpoon, Cyrus (BSIT 4th Year, irregular) |
| Student | `01-26-10001` | Santos, Andrea D. (BSIT 4th Year) |
| Student | `02-26-20001` | Navarro, Pia D. (BSHM 1st Year) |
| Professor | `fquiza` | Mr. Febrick Quiza, BSIT 401-C |
| Professor | `psantos` | BSHM section only |
| Administrative | `admin` | System Administrator |
| Administrative | `chair.bsit` | BSIT Program Chair |
| Administrative | `chair.bshm` | BSHM Program Chair |
| Administrative | `oic` | College Department OIC |
| Administrative | `secretary` | Secretary |

A professor cannot open another professor’s classes. College office accounts share the same administrative pages, with program chairs limited to their program.
