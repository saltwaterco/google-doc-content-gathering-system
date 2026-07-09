# Google Docs → WordPress/Elementor: Feasibility Report

**Status:** Proof of concept complete. All three feasibility questions answered **YES**.
**Date:** 2026-07-08
**Scope:** This validates the *content-gathering* half of the pipeline (reading Google
Docs and turning them into a structured content model). It intentionally does **not**
yet build the Elementor import — see [What this does NOT cover](#what-this-does-not-cover).

---

## 1. The solution in general

### The problem
The client is authoring their new site's content in **Google Docs**, one doc per page,
using a shared template. We need to programmatically read those docs and populate the
content into **WordPress pages built with Elementor**.

### Why it's non-trivial
Elementor doesn't store pages as HTML — it stores a proprietary JSON widget tree. And
the client's Google Doc template isn't free-form prose: the real content lives inside a
series of **2-column tables**, where **column 0 is labels/instructions** (for the human
writer and for us to identify block type) and **column 1 is the actual page content**.
So the core work is reliably reading that table structure and separating identifiers
from content.

### The approach we validated
A small PHP tool authenticates to Google, reads a doc via the **Google Docs API**
(which returns structured JSON, not HTML), and walks it like a DOM to produce a clean
**block model**. Each content table becomes one typed block:

```
[0] Hero - Title & Buttons  (slug: hero_title_and_buttons)
     ● Page Title (H1)   [page_title_h1]   <strong>Text for testing</strong>
```

- **`hero_title_and_buttons`** — a block-type identifier derived from the table's header.
  In the full pipeline this selects *which Elementor block* to create.
- **`page_title_h1`** — a field identifier derived from the row label. This selects
  *which slot inside that block* the content goes into.
- **`<strong>Text for testing</strong>`** — the actual content from column 1, captured
  as inline HTML so bold/italic/link formatting is preserved for import.

Column 0 is used **only** to route content; it never becomes page content itself.

### The three questions, answered

| # | Question | Result | How proven |
|---|----------|--------|------------|
| 1 | Can we programmatically list all docs in a Drive folder? | **Yes** | `list_docs.php` — Drive API `files.list`, paginated, handles Shared Drives |
| 2 | Can we walk one doc like a DOM and extract specific content? | **Yes** | `walk_doc.php` — Docs API `documents.get`; produces the block model, preserving inline formatting as HTML |
| 3 | Does the content survive an export to .docx and re-import to Google Docs? | **Yes** | Manual round-trip through **Microsoft Word**, then `compare_blocks.php` diffed the before/after block models — **no differences** |

**Question 3 is the important de-risk:** the client edits in Word, so docs will make a
`.docx` round-trip as part of the real workflow. We tested it with actual Word (not just
Google's own export) and the block model came out **identical** — meaning a Word edit
does not break our extraction.

### The POC toolkit (all PHP, ~5 small scripts)
| File | Role |
|------|------|
| `bootstrap.php` | Google authentication (service account, read-only) |
| `list_docs.php` | **Q1** — list docs in a folder |
| `doc_model.php` | The doc walker: structure → block model, with inline-HTML formatting |
| `walk_doc.php` | **Q2** — run the walker on one doc, output the block model JSON |
| `compare_blocks.php` | **Q3** — objectively diff two block models (before/after round-trip) |
| `SETUP.md`, `ROUNDTRIP.md` | Setup and round-trip test procedures |

**Dependencies:** PHP 8.1+, `google/apiclient`, `vlucas/phpdotenv` (via Composer).

---

## 2. Steps taken outside the codebase

These are the manual/config steps required to make the POC run — the parts that live in
Google's console and Drive, not in the repo.

### A. Google Cloud project + APIs
1. Created a **Google Cloud project** (console.cloud.google.com).
2. Enabled two APIs on that project:
   - **Google Docs API** (reads a document's structure)
   - **Google Drive API** (lists the folder's documents)

### B. Service account authentication
We use a **service account** rather than interactive OAuth — the right choice for an
unattended/batch tool that reads docs the client owns and never needs to act *as* a
specific human user.

3. Created a **service account** in the project (Credentials → Create → Service account).
   It has its own email, e.g. `spike-runner@<project>.iam.gserviceaccount.com`.
4. Generated a **JSON key** for it and stored it **outside the repo** (secret; the repo
   is configured to ignore it).
5. Scopes used are **read-only** (`documents.readonly`, `drive.readonly`) — the POC only
   reads; it never writes.

### C. Google Drive setup
6. Created a **test Drive folder** and populated it with the client's template docs.
7. **Shared that folder with the service account's email** (Viewer access) — this is how
   the tool is granted access, exactly like sharing with a coworker.
8. Recorded the **folder ID** (from the Drive URL) for the tool's config.
9. **Enabled "Convert uploads"** (Drive → Settings → General → "Convert uploaded files to
   Google Docs editor format"). This is **required**: the tool reads via the Docs API,
   which only works on native Google Docs, not stored `.docx` files. With this on, Word
   files (including bulk/multi-select uploads) auto-convert to Google Docs on arrival —
   this is how ~100 client Word docs get ingested without per-file manual conversion.
   *Note:* it's a per-account setting, so it must be enabled on whichever account uploads
   the files (e.g. the client's, if they upload into their own Drive and share).

### D. The Word round-trip test (Question 3)
Performed manually, once, to measure fidelity:
9. Exported a template doc from Google Docs: **File → Download → Microsoft Word (.docx)**.
10. Opened the `.docx` in **Microsoft Word (desktop)** and saved it (Word's save is what
    actually rewrites the document's internal structure — the true test).
11. Re-imported to Drive and opened it **as a Google Doc**.
12. Ran the walker on both versions and diffed them → **identical**.

### Local configuration
- A `.env` file (from `.env.example`, git-ignored) holds the key path and folder ID.
- `composer install` pulls the Google client library.

---

## What this does NOT cover

To set accurate expectations for the next phase:

- **The Elementor import is not built.** The POC produces the content model; it does not
  yet create WordPress pages or Elementor widgets. That mapping — from our block/field
  identifiers to specific custom Elementor blocks — is the next major piece of work.
- **The naming convention is doc-derived today.** Field keys are currently generated from
  the doc's label text. In the refinement phase we plan to invert this: define **custom
  Elementor blocks first**, establish a canonical naming convention from them, and build
  the doc template to match. This removes the risk of label drift across docs.
- **Batch/scale hardening.** Running against ~100 docs is well within range, but the
  production version needs three things we've scoped but not built: **idempotency**
  (re-runs update pages instead of duplicating them), **per-doc error isolation** (one
  bad doc doesn't sink the batch), and **batching/resumability** on the WordPress write
  side. None are hard; they're deliberate design decisions for the build phase.
- **Images.** The current template is text-only. If docs include inline images, those
  need a download-from-Google / upload-to-WP-media step (the most failure-prone per-doc
  operation) — flagged for when it becomes relevant.

---

## Recommendation

The content-gathering approach is **validated and low-risk**. The Google Docs side
behaves reliably, the table-based template parses cleanly into a structured model,
formatting is preserved, and — critically — the model survives the client's real Word
editing workflow unchanged. The recommended next step is to design the **custom
Elementor blocks and their naming convention**, which becomes the contract that both the
doc template and the import script are built against.
