# phplet

phplet is a small, self-modifying wiki. Its PHP, HTML, CSS, JavaScript, and every note live in one deployable file. There is no database, package install, build step, or network dependency.

```text
phplet.php
├── PHP persistence and HTTP API
├── HTML, editable CSS tokens, and browser UI
├── __halt_compiler();
├── PIPLET-DATA/1
└── { versioned JSON notes and appearance }
```

The browser edits one note at a time and shows a live preview. Save rewrites only the JSON snapshot; the executable prefix is copied byte for byte. Readers see either the complete old file or the complete new file.

## Run it

PHP 8.1 or newer is required.

```sh
PHPLET_ALLOW_PASSWORDLESS=1 php -S 127.0.0.1:8080
```

Then open `http://127.0.0.1:8080/phplet.php`.

Apache and Nginx/PHP-FPM deployments are intentionally not turnkey: configure `PHPLET_PASSWORD` in the PHP worker environment, route every request for the phplet and its temporary `.php` names through PHP, deny dotfiles and names containing `.phplet-tmp-`, and use TLS. The PHP process must be able to read the file and write its containing directory because saves create a temporary snapshot beside the file and atomically rename it into place. The exact virtual-host/FPM syntax is server- and distribution-specific; do not deploy until those four requirements are verified.

Password-free access is off by default. `PHPLET_ALLOW_PASSWORDLESS=1` enables it only for PHP's built-in server with a loopback peer and a strict loopback `Host`; use that switch only for a server bound directly to loopback. phplet cannot distinguish a local browser from a loopback reverse proxy, so the operator must never pass that switch through a proxy. Apache, PHP-FPM, reverse proxies, and remote access must set a password. For example, run the backend on loopback and put it behind a TLS-terminating proxy:

```sh
PHPLET_PASSWORD='choose-a-long-password' php -S 127.0.0.1:8080
```

That uses HTTP Basic authentication. Use HTTPS whenever traffic leaves your machine. phplet is intended for one person or a small trusted group; it does not have accounts, roles, or a merge editor.

## Use it

- `New note` creates an in-place editor.
- `Ctrl/⌘ S` saves; `Esc` cancels; `/` searches; `N` creates; `E` edits the first open note.
- Drafts whose serialized recovery record is at most 524,288 JavaScript string units survive an accidental navigation in `sessionStorage`, subject to the browser's own quota. Only an explicit save changes the PHP file. If recovery storage is full or a draft is larger, phplet keeps that editor open and asks you to save before switching. A later read-only launch still exposes an available browser recovery draft for copying.
- Note bodies support headings, lists, block quotes, fenced code, `**bold**`, inline backticks, and `[[label|note-id]]` links.
- `Appearance` previews and saves a coordinated palette, reading font, text size, line length, and optional typed CSS layout tokens.
- `Download a snapshot` exports a runnable backup: restoring it restores both app and notes.

Two editors can safely change different notes. Each note and the shared appearance carry revisions. A stale note save returns HTTP 409 with explicit keep/replace choices; a stale appearance save keeps the local preview and asks you to cancel or save again. New-note requests carry a stable creation token, so retrying after a lost response does not create a second slug while the original note exists.

## Change the appearance

Choose `Appearance` in the top bar. Every choice previews across the whole page immediately; `Cancel` restores the saved look, while `Restore defaults` previews the original design until you save. Palette, reading font, text size, and line length are stored in the PHP file so they travel with a downloaded snapshot. System/light/dark is kept on the current device and never rewrites the file by itself.

The advanced **Design tokens** section edits a small, allowlisted CSS-variable module inside the app. Token overrides take precedence over related presets until removed. The complete module is:

| Token | Accepted range |
| --- | --- |
| `--story-width` | 32–80 `rem` |
| `--measure` | 42–90 `ch` |
| `--sidebar` | 12–28 `rem` |
| `--radius` | 0–24 `px` |
| `--radius-sm` | 0–16 `px` |
| `--copy-size` | 0.8–1.6 `rem` |
| `--title-size` | 1.5–4 `rem` |

The immutable base stylesheet remains separate, and arbitrary selectors, URLs, declarations, and scripts are rejected. Color stays in the coordinated light/dark palettes so a light override cannot make the dark theme unreadable.

There are no remote fonts, icons, images, or CSS frameworks. The default theme is an editorial notebook rather than a generic dashboard: warm paper, ink, deep teal, serif reading type, hairline-separated notes, and a responsive story river. A dark palette is beside the light tokens.

The interface exposes coordinated, contrast-aware color presets plus typed layout variables instead of accepting arbitrary CSS. Manual source edits are still supported: keep the `__halt_compiler()` call and everything beneath it in place, and deploy source changes while no save is in flight. Stored appearance records tolerate newly added or retired settings and fall back to current defaults, so ordinary source upgrades do not brick an existing file. After PHP recompiles the edited prefix, later saves preserve that new prefix exactly. If OPcache timestamp validation is disabled, invalidate OPcache once after changing application code. Ordinary saves do not require invalidation because the compiled prefix never changes.

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
- The containing directory is briefly home to one high-entropy temporary `.php` file during a save. It starts at mode `0600`, is removed on handled failure, and is renamed on success. A hard process kill at any point after temporary-file creation can leave a hidden orphan; after verifying the canonical phplet, that orphan can be deleted.
- Atomic rename prevents torn reads and `fsync` protects the temporary contents, but portable PHP cannot `fsync` the containing directory. A sudden power loss can lose the latest rename even though it will not expose a half-written canonical file.
- Atomic replacement can change ownership to the PHP process and does not preserve ACLs or extended attributes. Ordinary permission bits are preserved.
- A web-writable PHP file is intentionally unusual. Keep backups and do not deploy it where policy or hardening rules forbid self-modifying code.

## Test it

The dependency-free test runner always copies the application to a unique temporary directory before mutating it:

```sh
php tests/run.php
```

It covers CRUD, idempotent creates, appearance migration and tokens, stale revisions, private temporary files, hostile/Unicode text, immutable-prefix preservation, PHP linting after saves, HTTP/auth/CSRF behavior, multi-megabyte snapshots, and concurrent writers queued across inode replacements. When Chrome or Chromium is available, it also runs real browser regressions for held/double saves, conflict and read-only draft recovery, unavailable browser storage, bounded story/index rendering, theme-only saves, and live appearance previews. Without Chrome, the runner reports that those dynamic browser scenarios were skipped and performs only static guard checks for their critical code paths.
