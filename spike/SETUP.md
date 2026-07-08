# Spike setup — Google Cloud credentials & test folder

This gets you from zero to "the PHP spike can read a Drive folder and its docs."
Everything here is for the **feasibility spike**, not production. ~15 minutes.

We use a **service account** (not interactive OAuth). Rationale: this tool runs
unattended/batch, reads Docs the client owns, and never needs to *act as* a specific
human. A service account has its own identity + email; you share the Drive folder
with that email, exactly like sharing with a coworker. No browser consent flow, no
refresh-token handling.

---

## 1. Create (or pick) a Google Cloud project

1. Go to <https://console.cloud.google.com/>.
2. Top bar → project dropdown → **New Project**. Name it e.g. `gdoc-elementor-spike`.
3. Wait for it to create, then make sure it's the selected project.

## 2. Enable the two APIs

In the console, for the selected project, enable both:

- **Google Docs API** — <https://console.cloud.google.com/apis/library/docs.googleapis.com>
- **Google Drive API** — <https://console.cloud.google.com/apis/library/drive.googleapis.com>

Click **Enable** on each. (Drive is needed to *list* the folder; Docs is needed to
read a document's structure.)

## 3. Create a service account

1. Go to **APIs & Services → Credentials**
   (<https://console.cloud.google.com/apis/credentials>).
2. **Create credentials → Service account**.
3. Name it e.g. `spike-runner`. Skip the optional "grant access" steps → **Done**.
4. You now have a service account with an email like
   `spike-runner@gdoc-elementor-spike.iam.gserviceaccount.com`. **Copy that email.**

## 4. Create a JSON key for it

1. Click the service account → **Keys** tab → **Add key → Create new key**.
2. Choose **JSON** → **Create**. A `.json` file downloads.
3. Move it somewhere OUTSIDE this repo (it's a secret). Suggested:
   ```
   ~/.config/gdoc-spike/service-account.json
   ```
   ```sh
   mkdir -p ~/.config/gdoc-spike
   mv ~/Downloads/gdoc-elementor-spike-*.json ~/.config/gdoc-spike/service-account.json
   ```
   > The repo `.gitignore` in `spike/` already ignores `*.json` and `.env`, but
   > keeping the key out of the repo entirely is safest.

## 5. Make a test Drive folder and share it

1. In Google Drive, create a folder, e.g. **`Spike — Site Content`**.
2. Put **2–4 sample Google Docs** in it. For the round-trip test to be meaningful,
   make at least one doc exercise the features you'll depend on:
   - Title + several `Heading 1` / `Heading 2` headings (these are your section markers)
   - Normal paragraphs, **bold**/*italic*, a bulleted list AND a numbered list
   - A table (even 2x2)
   - An inline image
   - If you plan to use any explicit marker convention (e.g. a line like
     `[SECTION: Hero]`), include one.
3. Share the folder with the **service account email** from step 3
   (Share → paste the email → **Viewer** is enough for reading).
4. Get the **folder ID**: open the folder in Drive; the URL is
   `https://drive.google.com/drive/folders/XXXXXXXXXXXX` — the `XXXX` part is the ID.

## 6. Point the spike at your credentials

Copy `.env.example` to `.env` in this `spike/` folder and fill it in:

```sh
cp spike/.env.example spike/.env
```
```
GOOGLE_APPLICATION_CREDENTIALS=/Users/bfield/.config/gdoc-spike/service-account.json
DRIVE_FOLDER_ID=XXXXXXXXXXXX
```

## 7. Install the PHP client and run

```sh
cd spike
composer install
php list_docs.php          # Question 1: list docs in the folder
php walk_doc.php <DOC_ID>  # Question 2: walk one doc's structure
```

`<DOC_ID>` is any document ID printed by `list_docs.php`.

---

## Troubleshooting

- **403 `insufficientPermissions` / file not found when listing** — the folder isn't
  shared with the service account email, or you shared a different folder. Re-check step 5.
- **403 `SERVICE_DISABLED`** — an API isn't enabled on the *selected* project (step 2).
- **`invalid_grant` / clock errors** — your machine clock is skewed; JWT auth is
  time-sensitive. Sync your system time.
