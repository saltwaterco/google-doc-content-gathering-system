# Question 3 — Does the block model survive a Word .docx round-trip?

**Goal.** The client edits content in Microsoft Word, so docs will leave Google Docs
as `.docx`, get edited/saved in Word, and come back. We need to know whether OUR
extraction (the block model `walk_doc.php` produces) still comes out the same after
that trip. Visual fidelity does NOT matter here — only whether the block model is
identical.

**Definition of "survives":** the block-model JSON is the same before and after —
same block count, same `type_slug`s in the same order, same field keys, same content
HTML. `compare_blocks.php` decides this objectively.

> Automation of the export/re-import is intentionally out of scope for now. This is a
> manual, one-time measurement. If it passes, automating it later is easy; if it fails,
> automation would only have automated a broken result.

---

## The procedure

### 1. Capture the BEFORE model
Run the walker on the original Google Doc and keep its output file:
```sh
cd spike
php walk_doc.php <DOC_ID>
# writes out/<Doc Title>.blocks.json  — rename it so it won't be overwritten:
mv "out/WuXi_Biology_Page_Template_Text_Only_Testing.blocks.json" "out/WuXi_Biology_Page_Template_Text_Only_Testing.BEFORE.blocks.json"
```

### 2. Export to .docx (Google side)
In Google Docs: **File → Download → Microsoft Word (.docx)**.

### 3. The step that actually matters — open and save in real Word
Open the downloaded `.docx` in **Microsoft Word** (desktop), then **Save** (Ctrl/Cmd-S,
keeping .docx format). This is the true test: Word's save is what rewrites the
document's internal structure, and it's where our fragile spots (cell paragraph
splitting, lists, links) are most likely to change. Optionally make a small edit like
a real writer would, to mimic the workflow.

### 4. Re-import to Google Docs (must become a NATIVE Google Doc)
The tool reads via the Docs API, which rejects stored `.docx` files
(`400 FAILED_PRECONDITION: "must not be an Office file"`). So the re-imported file has
to be a native Google Doc, not a parked Word file. Reliable ways:

- **Best / scalable:** enable **Drive Settings → General → "Convert uploads"** once
  (see SETUP.md step 5b), then just upload the `.docx` — it converts on arrival. This is
  also how bulk client uploads work.
- **One-off, if the setting is off:** open the uploaded file and use **File → Open**
  *from inside Google Docs* to force a converted copy. (Note: "Open with → Google Docs"
  from Drive's right-click menu may edit the Office file in place instead of converting —
  don't rely on it.)

Put the resulting Google Doc in your shared folder so the service account can read it,
and note its new DOC_ID. Run `php list_docs.php` to confirm it appears — if it doesn't,
it's still a Word file and wasn't converted.

### 5. Capture the AFTER model
```sh
php walk_doc.php <NEW_DOC_ID>
mv "out/<New Title>.blocks.json" "out/<Doc Title>.AFTER.blocks.json"
```

### 6. Compare — the objective verdict
```sh
php compare_blocks.php "out/<Doc Title>.BEFORE.blocks.json" "out/<Doc Title>.AFTER.blocks.json"
```
- **Exit 0 / "IDENTICAL"** → the block model survives the round-trip. Q3 answered: yes.
- **Exit 1 / list of differences** → each line is a place the pipeline would break or
  drift. Read them; they tell you exactly what Word changed.

---

## What to watch for (predicted fragile spots)

These are the parts most likely to differ, roughly in order of risk. If the diff flags
one of these, it's expected — not a mystery:

1. **Cell paragraph splitting.** Our label/guidance split depends on the label and its
   guidance being SEPARATE PARAGRAPHS in column 0. If Word merges them into one
   paragraph (or re-splits differently), the field `key` changes — the diff shows a
   MISSING key + a NEW key for the same field. This is the single most likely break.
2. **Lists.** Numbered/bulleted list nesting and numbering can change; if any content
   value is a list, watch for altered HTML.
3. **Links.** Occasionally a link URL or its wrapping changes; content HTML would differ.
4. **The "Writing Begins Beneath This Line" marker.** If Word alters that paragraph's
   text, `walk_doc.php` won't trim the preamble (you'd see many extra blocks). Verify the
   marker text is intact.
5. **Bold/italic on the block-type / field labels.** We rely on plain text of column 0,
   not its formatting, so this is low risk — but noted.

## If it doesn't survive cleanly

Options, in increasing robustness (for the refinement phase, not now):
- Loosen the parser (e.g. tolerate label+guidance in one paragraph).
- Lock column 0 in the template so Word round-tripping can't reshape it.
- Encode field identity with an explicit stable token instead of the human label
  (removes reliance on paragraph structure entirely).
