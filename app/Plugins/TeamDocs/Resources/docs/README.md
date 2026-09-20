# TeamDocs resource documents

Runtime snapshot of the three canonical documents in the repository root:

- `docs/cost-tracking-rules.md`
- `docs/leantime-zh-guide.md`
- `docs/leantime-zh-glossary.md`

`docs/` is the editable source of truth; this directory is what ships in the
image (the Docker build excludes the repository `docs/`). After editing a
canonical file, sync and verify in one step:

```bash
php scripts/check-teamdocs-docs.php --sync
```

Without `--sync` the same script only verifies. `tests/Unit/app/Plugins/TeamDocsResourceSyncTest.php`
compares SHA-256 hashes and fails when the two copies drift.

## Where the styling lives

Not in this plugin. The documents are rendered by `Leantime\Core\Support\MarkdownRenderer`
and styled by two core stylesheets that `public/assets/less/main.less` pulls into
the site-wide `main.css`:

- `public/assets/css/components/markdown.css` — the `md-body` typography the
  renderer's output depends on (headings, tables, callouts, footnotes, TOC)
- `public/assets/less/markdown-modal.less` — the dialog chrome around it

Documentation written here should stick to GFM, which is what the renderer
implements: tables, task lists, strikethrough, autolinks, footnotes, and the
`> [!NOTE]` / `[!TIP]` / `[!IMPORTANT]` / `[!WARNING]` / `[!CAUTION]` alert
syntax that becomes a coloured callout. Raw HTML in a document is escaped and
shown as text, not rendered.
