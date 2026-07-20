# WordPress / Elementor side

This covers the second half of the spike: taking the block model extracted from Google
Docs (see `SETUP.md` for the Docs side) and turning it into **Elementor pages** — created,
placed in the sitemap, and populated with real, editable Elementor blocks.

All of this runs as **standalone PHP scripts** (no plugin) that boot this repo's
WordPress via `wp-load.php`.

---

## Prerequisites

- The Google Docs side already works (`SETUP.md` done, `spike/.env` filled).
- This repo's WordPress runs locally (MAMP), with **Elementor active**.
- A page already exists whose title matches each Drive parent folder name — new pages
  nest under it. (The scripts STOP if a matching parent page is missing.)

### The CLI database gotcha (important)

`wp-config.php` sets `DB_HOST = 'localhost'`. In the browser that's fine, but from the
**command line** PHP resolves `localhost` to the wrong MySQL socket and WordPress fails
with a "Database Error" / "Error establishing a database connection".

We do **not** edit `wp-config.php` (it's shared with the working web setup). Instead
`wp_bootstrap.php` defines `DB_HOST` to the MAMP socket *before* WordPress loads:

```
localhost:/Applications/MAMP/tmp/mysql/mysql.sock
```

If your stack differs, override it per-run:

```sh
WP_CLI_DB_HOST='127.0.0.1:3306' php render_pages.php --dry-run
```

---

## The scripts (in order of capability)

### `wp_bootstrap.php`
Not run directly — it's the shared bootstrap the others `require`. `boot_wordpress()`
applies the DB-host fix, loads WordPress, and confirms Elementor is active.

### `create_elementor_page.php` — prove the mechanic
Creates ONE blank, Elementor-editable page.

```sh
php create_elementor_page.php "My Page Title"
```

An "Elementor page" is just a normal WP page plus meta:

| Meta | Value | Purpose |
|------|-------|---------|
| `_elementor_edit_mode` | `builder` | edit with Elementor, not the classic editor |
| `_elementor_data` | JSON string (`[]` = empty) | the widget tree |
| `_elementor_version` | e.g. `4.1.4` | for Elementor data migrations |
| `_wp_page_template` | `elementor_canvas` | blank canvas, no theme header/footer |

Prints an "Edit in Elementor" URL — open it to confirm the empty builder loads.

### `create_pages_from_docs.php` — titles + sitemap placement
For each doc in the Drive folder: reads its **parent folder name**, extracts the page
title, and creates/updates a page **nested under the WP page matching that folder name**.
Does NOT render blocks (empty `_elementor_data`).

```sh
php create_pages_from_docs.php --dry-run   # preview, no writes
php create_pages_from_docs.php             # create/update
```

### `render_pages.php` — the full thing: pages + populated blocks
Same as above, but also fills a real Elementor block from doc content.

```sh
php render_pages.php --dry-run
php render_pages.php
```

Current field mapping:

| Elementor widget | ← Doc block / field | Format |
|------------------|---------------------|--------|
| `e-heading` (atomic) | `page_title_h1` from any `hero*` block | plain text |
| `text-editor` (classic) | `body_content` from `text_introduction` | **HTML** (bold/italic/links preserved) |

---

## How block rendering works (the template-fill approach)

We do **not** hand-write Elementor's widget JSON — it's proprietary and version-sensitive.
Instead:

1. Build the block once in the Elementor editor, save it, and **export** it
   (Templates → export, or read a page's `_elementor_data`).
2. Drop the export in `templates/` (currently `templates/hero_texteditor.json`).
3. `render_pages.php` loads that export's inner `content` array as a **skeleton**,
   clones it per page, injects doc content into the right widgets (matched by
   `widgetType`), regenerates element IDs, and writes it to `_elementor_data`.

To change the design, re-export from Elementor and replace the template file — no code
change needed for layout tweaks.

---

## Two findings that will bite you if forgotten

### 1. `_elementor_data` MUST be `wp_slash()`-ed before saving
It's a JSON string. `update_post_meta()` runs `wp_unslash()` on the value, which strips
`json_encode`'s `\"` escapes and **corrupts the JSON** as soon as content contains a
quote — e.g. a link `<a href="...">`. Plain `<strong>`/`<em>` won't expose it; a link
will. Always:

```php
update_post_meta($pageId, '_elementor_data', wp_slash($json));
```

This is how Elementor itself stores the meta.

### 2. Atomic vs. classic widgets store content differently
This template mixes both (they coexist fine):

- **Atomic** (`e-heading`, `e-paragraph`, version 0.4): content is a structured
  `html-v3` object at `settings.<slot>.value.content.value` (a plain string).
  Inline bold/italic here is **not** yet supported (would need the `children` format).
- **Classic** (`text-editor`): content is a simple **HTML string** at `settings.editor`.
  This is why the Text Editor is the right widget for rich text (bold/italic/links) —
  we inject our doc HTML straight in. Google's link underline (`<a><u>…</u></a>`) is
  stripped to just `<a>`.

If you need rich formatting in a field, map it to a **classic Text Editor**, not an
atomic paragraph.

---

## Idempotency

Every page is linked to its source doc via a `_gdoc_id` post meta. Re-running any script
**updates** the existing page instead of creating a duplicate. Safe to run repeatedly.

## Templates on disk

| File | Widgets | Notes |
|------|---------|-------|
| `templates/hero_intro.json` | `e-heading` + `e-paragraph` (atomic) | earlier version; plain-text paragraph |
| `templates/hero_texteditor.json` | `e-heading` + `text-editor` (classic) | current; Text Editor carries rich HTML |
