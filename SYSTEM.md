# Systems Plus Computer College — System, Backend, and Machine Learning

This document explains how the grading site is built: the screens a person uses, the PHP that computes and stores grades, the MySQL database, and the linear-regression models that estimate a result before the term is finished.

The training files are synthetic. They were made for development and testing. They are not validated official school records. A prediction on the sheet is an advisory figure. The official grade is the one written when the Secretary finalizes the class.

## How the pieces fit

A browser opens a PHP page. That page reads and writes MySQL through PDO. The grading sheet also calls two small JSON endpoints so a score can be saved without reloading the whole class. Predictions do not call an outside machine-learning service. PHP reads a saved model file and applies the linear equation itself.

```text
Browser (HTML, CSS, JavaScript)
        |
        |  pages and form posts
        v
PHP (portals, grade math, workflow, mail, auth)
        |
        +--> MySQL
        |
        +--> ml/*.json   (saved linear-regression coefficients)
        |
        +--> Gmail SMTP and Google reCAPTCHA
```

## User interface

The interface is server-rendered HTML. Shared layout, navigation, and icons live in `includes/layout.php`. Visual design lives in `assets/css/app.css`.

The home page offers three entries:

| Screen | Address | Person |
| --- | --- | --- |
| Students | `student/login.php` | Student |
| Professors | `professor/login.php` | Faculty |
| College offices | `admin/login.php` | Administrator, Program Chair, College OIC, Secretary |

After sign-in, the left sidebar stays on the screen. On a small device the menu opens from the top button, and Profile or Password and Sign out stay pinned at the bottom of that menu.

What each person sees:

- A student sees the term dashboard, a hidden or released grade row for each subject, an At risk or Not at risk result with a written explanation, and a profile page for the password.
- A professor sees the workbook sheet: prelim, midterm, and finals, with attendance, recitation, activities, quizzes, project, and exam. Each score box stops at the maximum printed under that column. An eye on the class releases or hides that student's numeric grades.
- An office user sees the review queue, student masterlist, faculty, academics, honor rules, grade scale, user management, scholarships, reports, and the audit log.

## Front end

The front end is the browser layer. It does not contain the grade formula or the regression coefficients.

| File | Role |
| --- | --- |
| `assets/css/app.css` | Layout, portals, sheet, sidebar, alerts |
| `assets/js/gradebook.js` | Score entry, column maximum, period total, save |
| `assets/js/drafts.js` | Offline draft scores and later sync |
| `assets/js/app.js` | Menu, dialogs, and small page behaviors |
| `sw.js` | Installable app cache for the professor sheet |
| `student/forgot.php` | Six separate boxes for the recovery code |

`gradebook.js` posts to `api/score.php`. A blank cell is left out of the total. A typed zero counts. If the typed number would pass the column maximum, the extra digit is refused in the browser, and the server refuses it again.

`drafts.js` keeps unsynced scores on the device and sends them when the browser is online again. Category percents still need a connection.

## Back end

The back end is PHP 8. There is no separate application server. Each portal page loads `includes/bootstrap.php`, which starts the session, loads `.env`, and opens the database.

| Area | Files | Responsibility |
| --- | --- | --- |
| Configuration | `config/config.php`, `config/database.php`, `.env` | School constants, database connection, mail and reCAPTCHA settings |
| Sign-in and roles | `includes/auth.php` | Office users, student accounts, permissions, program scope |
| Grade math | `includes/grades.php`, `includes/gradebook.php` | Category percent, period percent, equivalent, remarks, visibility |
| Workflow | `includes/workflow.php` | Submit, return, endorse, and finalize |
| Honor lists | `includes/recognition.php` | Dean's List and President's List cases |
| Prediction | `includes/grades.php`, `includes/ml_gwa.php` | Apply the saved linear models |
| Mail | `includes/mail.php` | SMTP client for the 6-digit recovery code. This is not PHPMailer |
| Captcha | `includes/captcha.php` | Google reCAPTCHA on student sign-in and recovery |
| JSON endpoints | `api/score.php`, `api/draft.php` | Save a score or confirm a professor session |

Office accounts live in `users`. Student portal accounts live on `students`, with `password_hash` and `email`. A new student account is created in User management. The starting password is `123`. The student changes it under Profile. A forgotten password is replaced only after the emailed 6-digit code is entered. The code is stored as a hash, expires in 15 minutes, and is discarded after five wrong tries.

Grade visibility is `enrollments.grades_visible`. It defaults to hidden. The professor's eye sets it for one student, or for the whole class.

## Database

MySQL holds the academic record. A new install imports `database/database.sql`. An existing database receives new columns the first time a page calls `ensure_student_auth_columns()`.

People and structure:

| Table | Holds |
| --- | --- |
| `roles`, `users`, `user_roles` | Office and professor sign-in, and which program a chair may see |
| `professors` | Faculty row linked to a user |
| `programs`, `academic_years`, `semesters`, `subjects`, `sections` | Catalog |
| `students` | Masterlist, recovery email, and portal password |
| `professor_assignments` | One professor, subject, section, and term |
| `enrollments` | A student in that class, plus whether grades are visible |

Grades and review:

| Table | Holds |
| --- | --- |
| `grading_scale` | Percent band to equivalent, from 1.00 through 5.00 |
| `default_category_weights`, `category_weights`, `period_weights` | Category shares and prelim, midterm, and finals shares |
| `assessment_components` | Columns such as Q1 or the exam, including `max_score` |
| `assessment_scores` | The score typed for one student in one column |
| `score_adjustments` | Manual adjustment on a period |
| `grade_submissions`, `submission_reviews`, `submission_snapshots` | Submit, return, and the frozen sheet |
| `official_grades` | The locked grade after the Secretary finalizes the class |

Honor standing, prediction, and system records:

| Table | Holds |
| --- | --- |
| `eligibility_rules` | Dean's List and President's List cutoffs for a term |
| `dl_pl_evaluations` | Computed or official honor status |
| `recognition_cases`, `recognition_events` | Recognition workflow |
| `scholarship_categories`, `scholarship_assignments` | Discount after an official honor result |
| `historical_training_data`, `model_runs`, `prediction_results`, `legacy_predictions` | Stored training rows and advisory predictions |
| `student_password_resets` | Hashed recovery code, expiry, and attempt count |
| `notifications`, `audit_logs`, `school_settings` | Notices, the activity log, and settings |

The category percentage is points earned divided by points possible. The period percentage is the weighted sum of those categories. The equivalent is read from `grading_scale`. Below 75 percent is 5.00 Failed. From 75 percent upward the bands run through 1.00 Excellent.

## Machine learning and linear regression

Two predictions are used.

1. **Subject percent.** From the six category percentages, estimate the final subject percentage. The saved file is `ml/linear_model.json`. PHP applies it in `preserved_linear_prediction()`.
2. **Term GWA.** From the prelim GWA, or from prelim plus midterm, estimate the final GWA. The saved files are `ml/prelim_model.json` and `ml/midterm_model.json`. PHP applies them in `ml_gwa_predict()`.

Training is offline Python, using scikit-learn `LinearRegression`. The scripts are `ml/train_linear_regression.py`, `ml/train_prelim_model.py`, and `ml/train_midterm_model.py`. Each script keeps 20 percent of the rows as a test set (`random_state` 42), measures MAE and R-squared on that test set, and writes the coefficients to JSON. The website does not retrain during a page view. `includes/ml.php` can launch the subject-percent script when an operator runs it.

A linear regression finds one weight for each input so that:

```text
prediction = intercept + (coefficient1 × feature1) + (coefficient2 × feature2) + ...
```

For the subject-percent model the features are the category percentages for attendance, recitation, activities, quizzes, project, and exam. The result is clamped between 0 and 100, then converted to an equivalent with the grade scale.

For the GWA models the features are GWA numbers, where 1.00 is the strongest result. The result is clamped between 1.00 and 5.00.

Prelim only:

```text
predicted final GWA = 0.418375 + (0.796799 × prelim GWA)
```

After midterm:

```text
predicted final GWA = -0.012177 + (0.392166 × prelim GWA) + (0.615116 × midterm GWA)
```

`ml_student_semester_prediction()` chooses the stage:

- No complete period yet: pending.
- Only prelim is complete: the prelim model.
- A later period average exists: the prelim-plus-midterm model.
- Every final grade is in: the model is not used. The actual GWA is the average of the final equivalents, and a subject at or above 2.00 disqualifies honor standing.

The candidate labels from a predicted GWA are Possible PL at 1.00–1.25, Possible DL at 1.26–1.75, and otherwise not a predicted candidate. Students do not see these cutoffs. They see At risk, Not at risk, or Pending.

## MAE (Mean Absolute Error)

MAE is the average size of the miss on the test rows. The sign is ignored, so a prediction that is 0.20 too strong and one that is 0.20 too weak count the same.

```text
MAE = ( |actual1 − predicted1| + |actual2 − predicted2| + ... + |actualn − predictedn| ) / n
```

A smaller MAE is closer to the actual values. The unit is the same as the target: percentage points for the subject model, and GWA points for the GWA models.

| Model | Test rows | MAE | How to read it |
| --- | --- | --- | --- |
| Subject percent | 24 of 120 | 1.5061 | The typical miss is about 1.51 percentage points |
| Prelim GWA | 200 of 1000 | 0.1644 | The typical miss is about 0.16 GWA points |
| Prelim + midterm GWA | 200 of 1000 | 0.1198 | The typical miss is about 0.12 GWA points |

Adding midterm lowers the GWA error, which is the expected direction: the model has seen more of the term.

## R-squared (R²)

R-squared says how much of the variation in the actual results is matched by the predictions. It compares the model's errors with the errors you would get by always guessing the average.

```text
R² = 1 − (sum of squared prediction errors / sum of squared errors from the average)
```

| R² | Meaning |
| --- | --- |
| 1.00 | Every test prediction matches the actual value |
| 0.00 | The model is no better than predicting the average every time |
| Below 0 | The model is worse than predicting the average |

These test scores:

| Model | R² |
| --- | --- |
| Subject percent | 0.8564 |
| Prelim GWA | 0.8361 |
| Prelim + midterm GWA | 0.9096 |

An R² of 0.86 means about 86 percent of the variation in the test results is accounted for by the linear equation. The remaining variation is what the six category percentages, or the period GWAs, do not explain. The midterm model, at 0.91, fits the test set more closely than the prelim-only model.

R-squared does not by itself prove the model is safe to use as an official grade. These figures were computed on synthetic rows. Replacing the CSV with real historical grades and training again is required before the numbers describe this college's students.

## What is stored after a prediction

`store_advisory_predictions()` writes the subject advisory percent and equivalent into `prediction_results` with the label `advisory-sheet`. That row is not the official grade. `official_grades` is written only when the review path finishes.

The review path is professor submit, Program Chair, College OIC, then Secretary. A return sends the sheet back to the professor with a note. Final approval locks the class.
