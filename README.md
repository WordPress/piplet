# phplet

phplet is a small, self-modifying wiki. Its PHP, HTML, CSS, JavaScript, and every note live in one deployable file. There is no database, package install, build step, or network dependency.

```text
phplet.php
├── PHP persistence and HTTP API
├── HTML, editable CSS tokens, and browser UI
├── __halt_compiler();
├── PIPLET-DATA/1
└── { versioned JSON notes }
```

The browser edits one note at a time and shows a live preview. Save rewrites only the JSON snapshot; the executable prefix is copied byte for byte. Readers see either the complete old file or the complete new file.

## Run it

PHP 8.1 or newer is required.

```sh
php -S 127.0.0.1:8080
```

Then open `http://127.0.0.1:8080/phplet.php`.

For Apache or PHP-FPM, place `phplet.php` in a PHP-enabled directory. The PHP process must be able to read the file and write its containing directory because saves create a temporary snapshot beside the file and atomically rename it into place.

Configure the web server to deny dotfiles and names containing `.phplet-tmp-`; every normal request for a PHP filename must also be routed through PHP. This keeps an orphaned commit file from being served as source after an uncatchable process kill.

Password-free access is limited to PHP's built-in server on a loopback address. Apache, PHP-FPM, reverse proxies, and remote access fail closed unless a password is configured:

```sh
PHPLET_PASSWORD='choose-a-long-password' php -S 0.0.0.0:8080
```

That uses HTTP Basic authentication. Use HTTPS whenever traffic leaves your machine. phplet is intended for one person or a small trusted group; it does not have accounts, roles, or a merge editor.

## Use it

- `New note` creates an in-place editor.
- `Ctrl/⌘ S` saves; `Esc` cancels; `/` searches; `N` creates; `E` edits the first open note.
- Drafts up to 512 KiB survive an accidental navigation in `sessionStorage`, but only an explicit save changes the PHP file. Larger drafts remain in the open editor until saved.
- Note bodies support headings, lists, block quotes, fenced code, `**bold**`, inline backticks, and `[[label|note-id]]` links.
- `Download a snapshot` exports a runnable backup: restoring it restores both app and notes.

Two editors can safely change different notes. Each note carries a revision; a stale same-note save returns HTTP 409 and preserves the browser draft instead of overwriting newer text.

## Change the appearance

Open `phplet.php` and search for `CHANGE THE LOOK HERE`. The semantic CSS tokens in that block control the palette, typography, spacing, reading width, sidebar width, radii, and motion. Component styles are grouped below it in CSS cascade layers; persistence code does not know anything about presentation.

There are no remote fonts, icons, images, or CSS frameworks. The default theme is an editorial notebook rather than a generic dashboard: warm paper, ink, deep teal, serif reading type, hairline-separated notes, and a responsive story river. A dark palette is beside the light tokens.

Manual source edits are supported: keep the `__halt_compiler()` call and everything beneath it in place, and deploy source changes while no note save is in flight. After PHP recompiles the edited prefix, later saves preserve that new prefix exactly. If OPcache timestamp validation is disabled, invalidate OPcache once after changing application code. Ordinary note saves do not require invalidation because the compiled prefix never changes.

## How a save works

1. Open this PHP file and take an exclusive advisory lock.
2. Compare the open descriptor's device/inode with the path's current device/inode. If another save replaced it while this process waited, retry on the current file.
3. Read and validate the marked JSON trailer while holding the live-file lock.
4. Check the note's base revision and apply one mutation.
5. Write the unchanged code prefix plus new JSON to a random, same-directory `.php` temporary file; flush and `fsync` it; preserve its mode bits.
6. Revalidate the target inode and atomically `rename()` the temporary snapshot over the original.

The inode retry is the small but important detail that allows one durable filesystem entry without a permanent lock sidecar. A naïve `flock(__FILE__)` is incorrect after `rename()`: a waiting writer may acquire a lock on the old, unlinked inode and overwrite a newer save.

Stored note text is never evaluated as PHP and is added to the page with DOM text nodes. The JSON embedded in HTML uses the `JSON_HEX_*` escapes, mutations require JSON plus a same-origin CSRF header/cookie pair, and the response ships a restrictive Content Security Policy.

## Honest limits

- Supported deployment: PHP 8.1+ on a local POSIX filesystem. NFS/SMB, multi-host writers, serverless/immutable filesystems, hard-linked aliases, and Windows have not been claimed or tested.
- The whole file is copied on every save. The configured ceiling is 8 MiB; a few megabytes is the intended scale, not a busy multi-user site.
- The containing directory is briefly home to one high-entropy temporary `.php` file during a save. It starts at mode `0600`, is removed on handled failure, and is renamed on success. A process kill at the final commit instant can leave a hidden orphan; after verifying the canonical phplet, that orphan can be deleted.
- Atomic rename prevents torn reads and `fsync` protects the temporary contents, but portable PHP cannot `fsync` the containing directory. A sudden power loss can lose the latest rename even though it will not expose a half-written canonical file.
- Atomic replacement can change ownership to the PHP process and does not preserve ACLs or extended attributes. Ordinary permission bits are preserved.
- A web-writable PHP file is intentionally unusual. Keep backups and do not deploy it where policy or hardening rules forbid self-modifying code.

## Test it

The dependency-free test runner always copies the application to a unique temporary directory before mutating it:

```sh
php tests/run.php
```

It covers CRUD and stale revisions, hostile/Unicode text, immutable-prefix preservation, PHP linting after saves, HTTP/CSRF behavior, and concurrent multi-process writers queued across inode replacements.
