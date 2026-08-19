# piplet

piplet is a small, self-modifying wiki. Its PHP, HTML, CSS, JavaScript, and every note live in one deployable file. There is no database, package install, build step, or network dependency.

```text
piplet.php
├── PHP persistence and HTTP API
├── HTML, editable CSS, and browser UI
├── __halt_compiler();
├── PIPLET-DATA/1
└── { versioned JSON notes and appearance }
```

The browser edits one note at a time and shows a live preview. Save rewrites only the JSON snapshot; the executable prefix is copied byte for byte. Readers see either the complete old file or the complete new file.

## Run it

PHP 8.1 or newer is required.

```sh
PIPLET_ALLOW_PASSWORDLESS=1 php -S 127.0.0.1:8080
```

Then open `http://127.0.0.1:8080/piplet.php`.

Apache and Nginx/PHP-FPM deployments are intentionally not turnkey: configure `PIPLET_PASSWORD` in the PHP worker environment, route every request for the piplet and its temporary `.php` names through PHP, deny dotfiles and names containing `.piplet-tmp-`, and use TLS. The PHP process must be able to read the file and write its containing directory because saves create a temporary snapshot beside the file and atomically rename it into place. The exact virtual-host/FPM syntax is server- and distribution-specific; do not deploy until those four requirements are verified.

Password-free access is off by default. `PIPLET_ALLOW_PASSWORDLESS=1` enables it only for PHP's built-in server with a loopback peer and a strict loopback `Host`; use that switch only for a server bound directly to loopback. piplet cannot distinguish a local browser from a loopback reverse proxy, so the operator must never pass that switch through a proxy. Apache, PHP-FPM, reverse proxies, and remote access must set a password. For example, run the backend on loopback and put it behind a TLS-terminating proxy:

```sh
PIPLET_PASSWORD='choose-a-long-password' php -S 127.0.0.1:8080
```

That uses HTTP Basic authentication. Use HTTPS whenever traffic leaves your machine. piplet is intended for one person or a small trusted group; it does not have accounts, roles, or a merge editor.

## Use it

- `New note` creates an in-place editor.
- `Ctrl/⌘ S` saves; `Esc` cancels; `/` searches; `N` creates; `E` edits the first open note.
- Drafts whose serialized recovery record is at most 524,288 JavaScript string units survive an accidental navigation in `sessionStorage`, subject to the browser's own quota. Only an explicit save changes the PHP file. If recovery storage is full or a draft is larger, piplet keeps that editor open and asks you to save before switching. A later read-only launch still exposes an available browser recovery draft for copying.
- Note bodies support headings, lists, block quotes, fenced code, `**bold**`, inline backticks, and `[[label|note-id]]` links.
- `Appearance` previews and saves a coordinated palette, reading font, text size, line length, and an optional custom stylesheet.
- `Download a snapshot` exports a runnable backup: restoring it restores both app and notes.

Two editors can safely change different notes. Each note and the shared appearance carry revisions. A stale note save returns HTTP 409 with explicit keep/replace choices; a stale appearance save keeps the local preview and asks you to cancel or save again. New-note requests carry a stable creation token, so retrying after a lost response does not create a second slug while the original note exists.

## Change the appearance

Choose `Appearance` in the top bar. Every choice previews across the whole page immediately; `Cancel` restores the saved look, while `Restore defaults` previews the original design until you save. Palette, reading font, text size, and line length are stored in the PHP file so they travel with a downloaded snapshot. System/light/dark is kept on the current device and never rewrites the file by itself.

The advanced **Custom CSS** section is a complete override stylesheet, not a variables-only form. It accepts selectors, declarations, media queries, and custom properties up to 32 KiB. It comes after the built-in stylesheet, so it can restyle any part of the interface without copying the base theme.

Custom CSS can also hide or rearrange the editor. Add `?safe=1` to the page URL to load piplet with the stored stylesheet disabled; the CSS remains available in Appearance so it can be fixed or cleared. piplet assigns the stylesheet through a text node rather than HTML, and its Content Security Policy blocks remote styles, fonts, and image URLs. Scripts in CSS text do not become executable markup.

There are no remote fonts, icons, images, or CSS frameworks. The default theme is an editorial notebook rather than a generic dashboard: warm paper, ink, deep teal, serif reading type, hairline-separated notes, and a responsive story river.

The built-in presets are coordinated and contrast-aware; custom CSS is deliberately unconstrained and can override them. Manual source edits are also supported: keep the `__halt_compiler()` call and everything beneath it in place, and deploy source changes while no save is in flight. Stored appearance records tolerate newly added or retired settings and fall back to current defaults; older layout-token records are converted to equivalent CSS. After PHP recompiles the edited prefix, later saves preserve that new prefix exactly. If OPcache timestamp validation is disabled, invalidate OPcache once after changing application code. Ordinary saves do not require invalidation because the compiled prefix never changes.

## How a save works

1. Open this PHP file and take an exclusive advisory lock.
2. Compare the open descriptor's device/inode with the path's current device/inode. If another save replaced it while this process waited, retry on the current file.
3. Read and validate the marked JSON trailer while holding the live-file lock.
4. Check the note or appearance base revision and apply one mutation.
5. Write the unchanged code prefix plus new JSON to a random, same-directory `.php` temporary file; flush and `fsync` it; preserve its mode bits.
6. Revalidate the target inode and atomically `rename()` the temporary snapshot over the original.

The inode retry is the small but important detail that allows one durable filesystem entry without a permanent lock sidecar. A naïve `flock(__FILE__)` is incorrect after `rename()`: a waiting writer may acquire a lock on the old, unlinked inode and overwrite a newer save.

Stored note text is never evaluated as PHP and is added to the page with DOM text nodes. The JSON embedded in HTML uses the `JSON_HEX_*` escapes, mutations require JSON plus a same-origin CSRF header/cookie pair, and the response ships a restrictive Content Security Policy.

## Honest limits

- Supported deployment: PHP 8.1+ on a local POSIX filesystem. NFS/SMB, multi-host writers, serverless/immutable filesystems, hard-linked aliases, and Windows have not been claimed or tested.
- The whole file is copied on every save. The configured ceiling is 8 MiB; a few megabytes is the intended scale, not a busy multi-user site.
- Rendering is deliberately bounded: the story keeps at most 20 open notes and the index shows the newest 40 matches. Search still reaches older notes.
- The containing directory is briefly home to one high-entropy temporary `.php` file during a save. It starts at mode `0600`, is removed on handled failure, and is renamed on success. A hard process kill at any point after temporary-file creation can leave a hidden orphan; after verifying the canonical piplet, that orphan can be deleted.
- Atomic rename prevents torn reads and `fsync` protects the temporary contents, but portable PHP cannot `fsync` the containing directory. A sudden power loss can lose the latest rename even though it will not expose a half-written canonical file.
- Atomic replacement can change ownership to the PHP process and does not preserve ACLs or extended attributes. Ordinary permission bits are preserved.
- A web-writable PHP file is intentionally unusual. Keep backups and do not deploy it where policy or hardening rules forbid self-modifying code.

## Unsafe teaching version

[`piplet-unsafe.php`](piplet-unsafe.php) keeps only the core trick: multiple reading pages, a separate editor with live preview, and JSON after `__halt_compiler()`. It rewrites itself directly. It has no authentication, CSRF protection, validation, atomic replacement, crash recovery, or conflict handling. Keep it on loopback, use a disposable copy, and keep a backup.

```sh
php -S 127.0.0.1:8080
```

Then open `http://127.0.0.1:8080/piplet-unsafe.php`.

## Test it

The dependency-free test runner always copies the application to a unique temporary directory before mutating it:

```sh
php tests/run.php
php tests/unsafe-run.php
```

It covers CRUD, idempotent creates, appearance and legacy-token migration, stale revisions, private temporary files, hostile/Unicode text and CSS, immutable-prefix preservation, PHP linting after saves, HTTP/auth/CSRF behavior, multi-megabyte snapshots, and concurrent writers queued across inode replacements. When Chrome or Chromium is available, it also runs real browser regressions for held/double saves, conflict and read-only draft recovery, unavailable browser storage, bounded story/index rendering, theme-only saves, full CSS previews, safe appearance mode, and mobile focus. Without Chrome, the runner reports that those dynamic browser scenarios were skipped and performs only static guard checks for their critical code paths.
