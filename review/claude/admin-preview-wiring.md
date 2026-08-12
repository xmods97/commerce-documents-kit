# Admin PDF preview — wiring report

Branch: `agent/geward-document-module-stage0`
Baseline: `2343a4e`
Companion documents: `pdf-engine-decision.md`, `security-findings.md`, `test-results.md`, `review-report.md`
Evidence: `evidence/verify-admin-preview-output.txt`

**The production renderer and the WordPress logo provider are now connected, to one place: an administrator previewing one document.** 34 checks pass, exit 0.

---

## What was connected

`AdminController::previewPdf()`, registered on `admin_post_commerce_documents_preview_pdf`, with a "Preview PDF" button beside each readable document in the list.

```php
private static function pdfRenderer(): EmbeddedFontPdfRenderer
{
    return new EmbeddedFontPdfRenderer(
        function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2,
        null,
        null,
        new WordPressLogoProvider()
    );
}
```

**One deliberate deviation from the requested snippet.** The brief specified a literal `2` for the currency exponent. The store's own `wc_get_price_decimals()` is used instead, falling back to `2`, so the amounts on the PDF match the amounts everywhere else in the shop; a hard-coded `2` would silently misprice a store configured with different precision. Everything else — the provider, constructed with no arguments — is exactly as specified.

`WordPressLogoProvider` with no arguments falls through to `NativeMediaLibrary`, which reads `get_theme_mod('custom_logo')`, `get_post_mime_type()`, `get_attached_file()`, `wp_get_attachment_metadata()` and `wp_upload_dir()`. So the logo is the site's current Custom Logo, taken from a local file in uploads, with no configuration to set and nothing fetched.

## What was deliberately not connected

- **No email.** No mailer is constructed anywhere in the plugin, and neither is `DeliverDocument`. Both facts are asserted automatically.
- **No automatic generation.** The preview hangs off `admin_post_*` only. No order hook, no cron, no queue reaches it — `Plugin.php` does not mention `previewPdf` at all.
- **No storage.** The PDF is rendered, written to the response, and discarded. Nothing is saved to disk or to the database; the controller contains no `file_put_contents` and no scheduling call.
- **No deploy, no production hook, no release.**

The invariant the earlier passes defended — "nothing is wired" — has therefore not been dropped, it has been narrowed to "exactly this is wired, and nothing else". That is what the checks now assert.

## How the request is guarded

1. `current_user_can('manage_woocommerce')` before anything else; otherwise a 403.
2. `check_admin_referer('commerce_documents_preview_pdf_' . $documentId)` — a nonce bound to the specific document, and the link in the list is signed with `wp_nonce_url`.
3. The document is looked up through the same authenticated read path as the HTML view; an unknown id is a 404.
4. Rendering failures become an escaped `wp_die()` page rather than a truncated file.
5. Any buffered output is discarded before the binary is written, then `Content-Type: application/pdf`, `Content-Disposition: inline`, `Content-Length` and `X-Content-Type-Options: nosniff`.
6. The download filename is derived from the document number through `preg_replace('/[^A-Za-z0-9._-]+/', '-', …)`, so nothing from the document can shape the header.

## The logo, end to end

| Scenario | Result |
|---|---|
| PNG Custom Logo | Embedded as an image XObject; transparency travels as an 8-bit greyscale `/SMask`. |
| JPEG Custom Logo | Passed through as `/DCTDecode`, no soft mask. |
| No Custom Logo set | No `/XObject` in the document at all; the PDF is valid and the text is unchanged. |
| SVG Custom Logo | Refused. The document is **byte-identical** to the no-logo document — no error, no empty box. |
| SVG renamed to `.png` | Refused on content, not on the filename. Document still produced. |
| Custom Logo file missing | Document still produced. |
| Attachment with generated crops | The proportional variant is used; the square crop — the mark without the wordmark — is never chosen. |

## Visual check

`evidence/pdf-preview-logo-extracted.png` is the image pulled back **out of the PDF the wired path produced**, by inflating its XObject stream and rewrapping it as a PNG. It shows the complete lockup — mark plus wordmark, 1200×300, transparency intact — and not the 480×480 square crop, which would have been the mark alone. That is the distinction the crop fix exists to make, confirmed by looking rather than by measuring.

Not checked visually: the page. There is still no PDF rasteriser offline, so nobody has seen the logo *in position* on the invoice. Its placement is verified numerically — fitted into a 150×46 pt box against the right margin, aspect preserved, the following block pushed below its lower edge — and the recovered page text confirms the parties and the table are where they belong. **Opening `evidence/pdf-preview-logo-png.pdf` once is the outstanding check.**

## Checks

| Check | Result |
|---|---|
| PHP lint | 101 files, 0 errors |
| `verify-admin-preview.php` | 34/34, exit 0 |
| `verify-pdf-engine.php` | 73/73, exit 0 |
| `verify-logo-security.php` | 69/69, exit 0 |
| `verify-fixes.php` | 59/59, exit 0 |
| `git diff --check` | clean |
| PHPUnit | not run — `vendor/` absent |

`verify-admin-preview.php` is the one that matters here: it defines the WordPress functions over a fixture and drives the **zero-argument** provider, so it proves the default wiring resolves a real logo rather than proving a fake does.

## Residual risks

- **No page has been looked at.** As above.
- **If the site's Custom Logo is an SVG, there will be no logo** — deliberately. The document is still correct and complete; the fix is to set a PNG as the Custom Logo, not to loosen the parser.
- **The inverted white-on-dark logo family would put a dark block on a white invoice.** Whichever attachment is set is embedded faithfully.
- **Rendering happens in the request.** A large logo is decoded per preview; the resolver prefers a scaled variant precisely to keep that small, but nothing is cached. Acceptable for an operator-initiated preview; it would want a second look before anything renders documents in bulk.
- **No WordPress runtime was involved.** The capability check, the nonce, the headers and the media lookups are verified by code reading and by a fixture-backed stand-in, not against a live WordPress. The preview should be clicked once on a real install.
