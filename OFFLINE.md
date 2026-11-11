# How offline access works

The grading site can be installed from the browser and can open pages that this device has already loaded. Scores typed while the connection is down stay on the device. They are sent to the server only after the connection returns. Nothing is stored in MySQL until that sync succeeds.

This is not a separate offline server and it does not use a third-party sync API. The browser features are the Service Worker, the Cache Storage, IndexedDB, and the Fetch API. The only application endpoints involved in the score sync are `api/draft.php` and `api/score.php`.

Offline mode needs a secure address. The service worker is registered only when the page is HTTPS, or when it is local `localhost`. A plain `http://` address on another computer will not install the offline app.

## What a person does

1. Open the site once while online and sign in.
2. Install it from the browser if wanted: Add to Home Screen, or Install app. The install record is `manifest.php`. The installed name is SPCC Grades. It opens `index.php`.
3. Open the classes or office pages that should be available later. Opening a page is what saves that page on the device.
4. If the connection drops, those saved pages still open. A score typed on a saved class sheet is marked as a draft on the device.
5. When the device is online again, the drafts are posted to the server. Submit a period only after that bar has cleared. The submit button warns if drafts are still only on the device.

A page that was never opened on this device cannot be invented while offline. The screen then says the page is not on this device yet.

## What is saved on the device

Three browser stores are used. They belong to this browser on this device. They are not the MySQL database.

| Store | Browser feature | What it keeps |
| --- | --- | --- |
| `spcc-grades-v5` | Cache Storage, filled by `sw.js` | HTML, CSS, JavaScript, images, and pages already opened |
| `spcc-grade-drafts` | IndexedDB | Unsent scores in the `scores` store, and unsent office form posts in the `records` store |
| `spcc-device-login` | IndexedDB | A professor or office sign-in this browser has already completed, so a later sync can sign the session back in |

The current browser tab also keeps the username and password in `sessionStorage` under `spcc-sync-auth` after a professor or office sign-in. That copy is only so a sync can log in again if the server session expired. It is cleared when that sign-in is cleared. It is not sent anywhere except back to this site's own login page.

## How a page opens without internet

`assets/js/drafts.js` registers `sw.js` from the `sw-url` meta tag. The worker's scope is the application folder.

On install, the worker caches a small shell:

- `index.php`
- the three login pages
- `manifest.php`
- `assets/css/app.css`
- `assets/js/app.js`, `drafts.js`, and `gradebook.js`
- the logo and the home-screen icons

After sign-in, the page prints a list of addresses in `#offlinePages`. For a professor that list is every assigned class, including the sheet, summary, final, settings, and equivalent views for prelim, midterm, and finals. For an office user it is the dashboard and the office pages that role may open, plus up to 100 student records in scope. `drafts.js` requests those addresses while online so the service worker stores the responses.

On a later GET:

1. The service worker ignores anything under `/api/`. Score posts are never served from the cache.
2. It also does not cache the captcha script path.
3. If the browser reports that it is offline and a copy exists, that copy is returned.
4. If the browser is online, the worker tries the network and stores a successful copy for next time. A navigation that already has a copy waits at most 2.5 seconds, then uses the copy if the network does not answer.
5. If there is no copy and the network fails, a navigation shows the "not on this device yet" page.

Class sheets are matched by class id, view, and period, so a saved sheet can still open when the query string is not identical.

## How a score is kept offline

On the grading sheet, `gradebook.js` tries to POST the cell to `api/score.php`. If `navigator.onLine` is false, or the request fails, the cell is not discarded. It is written to IndexedDB through `SpccDrafts.put`, the cell is marked as a draft, and the status line says the draft was saved on this device.

The saved record contains the enrollment, the component, the score or attendance mark, and the score URL. The period total on screen is recalculated in the browser from the numbers already on the sheet. That on-screen total is not the official database total until the sync succeeds.

The same pattern is used for office forms marked `data-offline-save`, such as a new student account or a catalog change. Those posts go into the `records` store instead of the score store. They are replayed later as a normal form POST to the original office page.

## What happens when the connection returns

`drafts.js` listens for the browser `online` event and also tries a sync when the page loads.

Score drafts:

1. `GET api/draft.php` with the existing session cookie. The response is JSON. If the professor session is still valid, the body is `{ "ok": true, "csrf": "..." }`. If not, the status is 401.
2. A 401 tries to sign in again with the professor or office account remembered for this tab, then calls `api/draft.php` once more.
3. The drafts are sent in one POST to `api/score.php` with `kind` set to `batch` and `scores` set to a JSON list of the queued cells. The CSRF token from step 1 is included.
4. Each result that the server accepts is deleted from IndexedDB. A result the server rejects stays on the device and is marked failed, with the server message.
5. Accepted cells on the open sheet lose the draft mark. The sheet can then be submitted.

Office drafts are not sent to `api/score.php`. Each one is posted to the office page it came from, after that page is loaded to read a fresh CSRF token.

`api/draft.php` does not store scores. It only checks that a professor is signed in and issues the token the batch needs. `api/score.php` is the write. It checks the professor session, the CSRF token, the column maximum, and whether that period is still editable. A score is written to `assessment_scores` only at that point.

## What still needs the network

- The first visit to any page, because the device has no copy yet.
- Creating the recovery email code, reCAPTCHA, and Gmail. Those calls leave the device.
- Student sign-in against the live database. A student page opens offline only if that exact page was opened on this device while online.
- Changing category percents. The sheet script still sends those to `api/score.php` and does not queue them the way a score cell is queued.
- Submitting a period, returning a sheet, and finalizing a grade. Those change the workflow in MySQL and are refused while score drafts are still unsynced.
- Any class or student that this browser has never opened.

The cache name is `spcc-grades-v5`. When that name changes, the service worker deletes older caches on activate so the device does not keep a stale shell forever.
