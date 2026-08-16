<?php
declare(strict_types=1);

/*
 * phplet — a tiny, self-contained wiki.
 *
 * Everything before __halt_compiler() is the application. Everything after it
 * is the data. A save copies the application bytes unchanged, appends fresh
 * JSON, and atomically replaces this file.
 *
 * Runtime: PHP 8.1+, a local POSIX filesystem, and write access to this file's
 * directory. Set PHPLET_PASSWORD when serving beyond localhost.
 */

const PHPLET_DATA_HEADER = "\nPIPLET-DATA/1\n";
const PHPLET_FORMAT = 1;
const PHPLET_MAX_FILE_BYTES = 8 * 1024 * 1024;
const PHPLET_MAX_REQUEST_BYTES = 5 * 1024 * 1024;
const PHPLET_MAX_TITLE_BYTES = 240;
const PHPLET_MAX_TAG_BYTES = 48;
const PHPLET_MAX_TAGS = 12;

// A half-written temporary copy must never behave as the live application.
if (str_contains(basename(__FILE__), '.phplet-tmp-')) {
    http_response_code(503);
    exit('Save in progress.');
}

final class PhpletConflict extends RuntimeException
{
    public function __construct(public readonly ?array $current)
    {
        parent::__construct('This note changed after you opened it.');
    }
}

final class PhpletHttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

function phplet_path(): string
{
    static $path;
    if ($path === null) {
        $path = realpath(__FILE__);
        if ($path === false) {
            throw new RuntimeException('Cannot resolve the application file.');
        }
    }
    return $path;
}

function phplet_code_offset(): int
{
    return __COMPILER_HALT_OFFSET__;
}

function phplet_read_stream($handle): string
{
    if (!rewind($handle)) {
        throw new RuntimeException('Cannot rewind the application file.');
    }

    $raw = '';
    while (!feof($handle)) {
        $chunk = fread($handle, 1024 * 1024);
        if ($chunk === false) {
            throw new RuntimeException('Cannot read the application file.');
        }
        $raw .= $chunk;
        if (strlen($raw) > PHPLET_MAX_FILE_BYTES) {
            throw new RuntimeException('The phplet is larger than its configured limit.');
        }
    }
    return $raw;
}

function phplet_decode(string $raw): array
{
    $offset = __COMPILER_HALT_OFFSET__;
    if (strlen($raw) < $offset + strlen(PHPLET_DATA_HEADER)) {
        throw new RuntimeException('The embedded data section is missing.');
    }

    $trailer = substr($raw, $offset);
    if (!str_starts_with($trailer, PHPLET_DATA_HEADER)) {
        throw new RuntimeException('The embedded data marker is invalid.');
    }

    $json = rtrim(substr($trailer, strlen(PHPLET_DATA_HEADER)), "\r\n");
    if ($json === '') {
        throw new RuntimeException('The embedded data section is empty.');
    }

    try {
        $document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('The embedded data is not valid JSON.', 0, $error);
    }

    if (!is_array($document)) {
        throw new RuntimeException('The embedded data must be a JSON object.');
    }
    phplet_validate_document($document);
    return $document;
}

function phplet_read(): array
{
    $path = phplet_path();
    $handle = @fopen($path, 'rb');
    $stat = $handle === false ? false : fstat($handle);
    if ($handle === false || $stat === false || $stat['size'] > PHPLET_MAX_FILE_BYTES) {
        if (is_resource($handle)) fclose($handle);
        throw new RuntimeException('The phplet cannot be read or is too large.');
    }
    try {
        return phplet_decode(phplet_read_stream($handle));
    } finally {
        fclose($handle);
    }
}

function phplet_validate_document(array $document): void
{
    if (($document['format'] ?? null) !== PHPLET_FORMAT) {
        throw new RuntimeException('Unsupported embedded data format.');
    }
    if (!isset($document['revision']) || !is_int($document['revision']) || $document['revision'] < 0) {
        throw new RuntimeException('Invalid document revision.');
    }
    if (!isset($document['notes']) || !is_array($document['notes'])) {
        throw new RuntimeException('Invalid note collection.');
    }

    foreach ($document['notes'] as $id => $note) {
        if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $id)) {
            throw new RuntimeException('Invalid note identifier.');
        }
        if (!is_array($note) || ($note['id'] ?? null) !== $id) {
            throw new RuntimeException('Invalid note record.');
        }
        foreach (['title', 'body', 'created', 'updated'] as $field) {
            if (!isset($note[$field]) || !is_string($note[$field]) || preg_match('//u', $note[$field]) !== 1) {
                throw new RuntimeException('Invalid note text.');
            }
        }
        if ($note['title'] === '' || strlen($note['title']) > PHPLET_MAX_TITLE_BYTES) {
            throw new RuntimeException('Invalid note title.');
        }
        if (!isset($note['revision']) || !is_int($note['revision']) || $note['revision'] < 1) {
            throw new RuntimeException('Invalid note revision.');
        }
        if ($note['revision'] > $document['revision']) {
            throw new RuntimeException('A note revision is ahead of its document.');
        }
        if (!isset($note['tags']) || !is_array($note['tags']) || !array_is_list($note['tags']) || count($note['tags']) > PHPLET_MAX_TAGS) {
            throw new RuntimeException('Invalid note tags.');
        }
        foreach ($note['tags'] as $tag) {
            if (!is_string($tag) || $tag === '' || strlen($tag) > PHPLET_MAX_TAG_BYTES || preg_match('//u', $tag) !== 1) {
                throw new RuntimeException('Invalid note tag.');
            }
        }
    }
}

function phplet_write_all($handle, string $bytes): void
{
    $length = strlen($bytes);
    $written = 0;
    while ($written < $length) {
        $count = fwrite($handle, substr($bytes, $written));
        if ($count === false || $count === 0) {
            throw new RuntimeException('Could not finish writing the new snapshot.');
        }
        $written += $count;
    }
}

function phplet_persist(string $prefix, array $document, array $lockedStat): void
{
    phplet_validate_document($document);
    try {
        $json = json_encode(
            $document,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    } catch (JsonException $error) {
        throw new RuntimeException('Could not encode the new snapshot.', 0, $error);
    }

    $snapshot = $prefix . PHPLET_DATA_HEADER . $json . "\n";
    if (strlen($snapshot) > PHPLET_MAX_FILE_BYTES) {
        throw new PhpletHttpError(413, 'This save would make the phplet larger than 8 MiB.');
    }

    $path = phplet_path();
    $temp = dirname($path) . '/.' . basename($path) . '.phplet-tmp-' . bin2hex(random_bytes(12)) . '.php';
    $tempHandle = null;
    $committed = false;

    try {
        $tempHandle = @fopen($temp, 'xb');
        if ($tempHandle === false) {
            throw new RuntimeException('Cannot create a snapshot beside the phplet.');
        }
        // Keep partial and most crash-orphaned snapshots private even under a
        // permissive process umask.
        if (!@chmod($temp, 0600)) {
            throw new RuntimeException('Cannot secure the temporary snapshot.');
        }
        phplet_write_all($tempHandle, $snapshot);
        if (!fflush($tempHandle)) {
            throw new RuntimeException('Cannot flush the new snapshot.');
        }
        if (function_exists('fsync') && !fsync($tempHandle)) {
            throw new RuntimeException('Cannot sync the new snapshot.');
        }

        $mode = ((int) ($lockedStat['mode'] ?? 0644)) & 0777;
        if (!@chmod($temp, $mode)) {
            throw new RuntimeException('Cannot preserve the phplet permissions.');
        }
        if (function_exists('fsync') && !fsync($tempHandle)) {
            throw new RuntimeException('Cannot sync the snapshot permissions.');
        }

        if (!fclose($tempHandle)) {
            throw new RuntimeException('Cannot close the new snapshot.');
        }
        $tempHandle = null;

        // Refuse to clobber an out-of-band replacement made while we prepared.
        clearstatcache(true, $path);
        $currentStat = @stat($path);
        if (
            $currentStat === false
            || $currentStat['dev'] !== $lockedStat['dev']
            || $currentStat['ino'] !== $lockedStat['ino']
        ) {
            throw new RuntimeException('The phplet changed during the save; please retry.');
        }

        if (!@rename($temp, $path)) {
            throw new RuntimeException('Cannot atomically replace the phplet.');
        }
        $committed = true;
        clearstatcache(true, $path);
    } finally {
        if (is_resource($tempHandle)) {
            @fclose($tempHandle);
        }
        if (!$committed && is_file($temp)) {
            @unlink($temp);
        }
    }
}

/**
 * Serialize a read-modify-write without a permanent lock sidecar.
 *
 * rename() swaps inodes, so locking the first file we open is not enough: a
 * waiter may have opened the old inode. We lock, compare the descriptor's
 * device/inode with the current path, and retry until we own the live file.
 */
function phplet_mutate(callable $change): array
{
    $path = phplet_path();

    for ($attempt = 0; $attempt < 100; $attempt++) {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open the phplet for saving.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock the phplet for saving.');
            }
            $lockedStat = fstat($handle);
            clearstatcache(true, $path);
            $currentStat = @stat($path);

            if (
                $lockedStat === false
                || $currentStat === false
                || $lockedStat['dev'] !== $currentStat['dev']
                || $lockedStat['ino'] !== $currentStat['ino']
            ) {
                flock($handle, LOCK_UN);
                fclose($handle);
                usleep(random_int(500, 3000));
                continue;
            }
            if (($lockedStat['nlink'] ?? 1) !== 1) {
                throw new RuntimeException('Hard-linked phplets cannot be saved safely.');
            }

            $raw = phplet_read_stream($handle);
            $document = phplet_decode($raw);
            $result = $change($document);
            $document['revision']++;

            $prefix = substr($raw, 0, __COMPILER_HALT_OFFSET__);
            phplet_persist($prefix, $document, $lockedStat);
            return ['result' => $result, 'document' => $document];
        } finally {
            if (is_resource($handle)) {
                @flock($handle, LOCK_UN);
                @fclose($handle);
            }
        }
    }

    throw new RuntimeException('The phplet is busy; please retry.');
}

function phplet_now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function phplet_slug(string $title, array $notes): string
{
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) : $title;
    $ascii = is_string($ascii) ? $ascii : $title;
    $base = strtolower($ascii);
    $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
    $base = trim($base, '-');
    $base = substr($base === '' ? 'note' : $base, 0, 64);
    if (ctype_digit($base)) {
        $base = 'note-' . $base;
    }
    $id = $base;
    $suffix = 2;
    while (isset($notes[$id])) {
        $id = substr($base, 0, 70) . '-' . $suffix++;
    }
    return $id;
}

function phplet_normalize_tags(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value)) {
        throw new PhpletHttpError(422, 'Tags must be a list.');
    }
    if (count($value) > PHPLET_MAX_TAGS) {
        throw new PhpletHttpError(422, 'Use at most 12 tags.');
    }

    $tags = [];
    foreach ($value as $tag) {
        if (!is_string($tag) || preg_match('//u', $tag) !== 1) {
            throw new PhpletHttpError(422, 'Every tag must be valid text.');
        }
        $tag = trim($tag);
        if ($tag === '') {
            continue;
        }
        if (strlen($tag) > PHPLET_MAX_TAG_BYTES) {
            throw new PhpletHttpError(422, 'A tag is too long.');
        }
        $key = strtolower($tag);
        $tags[$key] = $tag;
        if (count($tags) > PHPLET_MAX_TAGS) {
            throw new PhpletHttpError(422, 'Use at most 12 tags.');
        }
    }
    return array_values($tags);
}

function phplet_text(mixed $value, string $name, int $maxBytes = PHPLET_MAX_REQUEST_BYTES): string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) {
        throw new PhpletHttpError(422, "$name must be valid UTF-8 text.");
    }
    if (strlen($value) > $maxBytes) {
        throw new PhpletHttpError(422, "$name is too long.");
    }
    return $value;
}

function phplet_save_note(array $input): array
{
    $id = $input['id'] ?? null;
    if ($id !== null && (!is_string($id) || ctype_digit($id) || !preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $id))) {
        throw new PhpletHttpError(422, 'Invalid note identifier.');
    }
    $baseRevision = $input['baseRevision'] ?? null;
    if (!is_int($baseRevision) || $baseRevision < 0) {
        throw new PhpletHttpError(422, 'Invalid base revision.');
    }
    $title = trim(phplet_text($input['title'] ?? null, 'Title', PHPLET_MAX_TITLE_BYTES));
    if ($title === '') {
        throw new PhpletHttpError(422, 'Give the note a title.');
    }
    $body = phplet_text($input['body'] ?? null, 'Body');
    $tags = phplet_normalize_tags($input['tags'] ?? []);

    return phplet_mutate(function (array &$document) use ($id, $baseRevision, $title, $body, $tags): array {
        $notes = &$document['notes'];
        if ($id === null) {
            if ($baseRevision !== 0) {
                throw new PhpletConflict(null);
            }
            $id = phplet_slug($title, $notes);
            $created = phplet_now();
        } else {
            $current = $notes[$id] ?? null;
            if (!is_array($current) || $current['revision'] !== $baseRevision) {
                throw new PhpletConflict($current);
            }
            $created = $current['created'];
        }

        // A note revision is its globally unique commit number. Reusing a slug
        // after delete therefore cannot let an old rev-1 editor pass (ABA).
        $revision = $document['revision'] + 1;

        $note = [
            'id' => $id,
            'title' => $title,
            'body' => $body,
            'tags' => $tags,
            'revision' => $revision,
            'created' => $created,
            'updated' => phplet_now(),
        ];
        $notes[$id] = $note;
        return $note;
    });
}

function phplet_delete_note(array $input): array
{
    $id = $input['id'] ?? null;
    $baseRevision = $input['baseRevision'] ?? null;
    if (!is_string($id) || ctype_digit($id) || !preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $id) || !is_int($baseRevision)) {
        throw new PhpletHttpError(422, 'Invalid delete request.');
    }

    return phplet_mutate(function (array &$document) use ($id, $baseRevision): array {
        $current = $document['notes'][$id] ?? null;
        if (!is_array($current) || $current['revision'] !== $baseRevision) {
            throw new PhpletConflict($current);
        }
        unset($document['notes'][$id]);
        return ['id' => $id];
    });
}

function phplet_is_local_request(): bool
{
    if (PHP_SAPI === 'cli' && !isset($_SERVER['REMOTE_ADDR'])) {
        return true;
    }
    if (PHP_SAPI !== 'cli-server') {
        return false;
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $loopback = $remote === '127.0.0.1' || $remote === '::1' || str_starts_with($remote, '::ffff:127.');
    if (!$loopback) {
        return false;
    }

    // A loopback peer can be a reverse proxy. Constrain Host as a DNS-rebinding
    // backstop before allowing the password-free local mode.
    $host = strtolower(trim($_SERVER['HTTP_HOST'] ?? ''));
    return preg_match('/^(?:(?:localhost\.?)|127\.0\.0\.1)(?::\d{1,5})?$|^\[::1\](?::\d{1,5})?$/D', $host) === 1;
}

function phplet_is_api(): bool
{
    return isset($_GET['api']);
}

function phplet_json(array $value, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    exit;
}

function phplet_require_access(): void
{
    $password = getenv('PHPLET_PASSWORD');
    $password = is_string($password) ? $password : '';

    if ($password === '' && phplet_is_local_request()) {
        return;
    }

    $provided = $_SERVER['PHP_AUTH_PW'] ?? null;
    if (!is_string($provided) || $provided === '') {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (is_string($authorization) && preg_match('/^Basic ([A-Za-z0-9+\/=]+)$/D', $authorization, $match)) {
            $decoded = base64_decode($match[1], true);
            $parts = is_string($decoded) ? explode(':', $decoded, 2) : [];
            $provided = count($parts) === 2 ? $parts[1] : '';
        } else {
            $provided = '';
        }
    }
    if ($password !== '' && is_string($provided) && hash_equals($password, $provided)) {
        return;
    }

    if ($password !== '') {
        header('WWW-Authenticate: Basic realm="phplet", charset="UTF-8"');
        $message = 'Authentication required.';
        $status = 401;
    } else {
        $message = 'Remote access is disabled until PHPLET_PASSWORD is set.';
        $status = 403;
    }

    if (phplet_is_api()) {
        phplet_json(['ok' => false, 'error' => $message], $status);
    }
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}

function phplet_cookie_name(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? __FILE__;
    return 'phplet_csrf_' . substr(hash('sha256', $script), 0, 10);
}

function phplet_csrf_token(): string
{
    $name = phplet_cookie_name();
    $existing = $_COOKIE[$name] ?? '';
    if (is_string($existing) && preg_match('/^[a-f0-9]{64}$/D', $existing)) {
        return $existing;
    }

    $token = bin2hex(random_bytes(32));
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
    $path = rtrim(dirname($script), '/.');
    $path = ($path === '' ? '' : $path) . '/';
    setcookie($name, $token, [
        'expires' => 0,
        'path' => $path,
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    return $token;
}

function phplet_require_csrf(): void
{
    $cookie = $_COOKIE[phplet_cookie_name()] ?? '';
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($cookie) || !is_string($header) || $cookie === '' || !hash_equals($cookie, $header)) {
        throw new PhpletHttpError(403, 'Refresh the page before saving again.');
    }
}

function phplet_request_json(): array
{
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') {
        throw new PhpletHttpError(415, 'Use application/json.');
    }
    $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declared > PHPLET_MAX_REQUEST_BYTES) {
        throw new PhpletHttpError(413, 'The request is too large.');
    }
    $raw = file_get_contents('php://input', false, null, 0, PHPLET_MAX_REQUEST_BYTES + 1);
    if ($raw === false || strlen($raw) > PHPLET_MAX_REQUEST_BYTES) {
        throw new PhpletHttpError(413, 'The request is too large.');
    }
    try {
        $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new PhpletHttpError(400, 'The request is not valid JSON.');
    }
    if (!is_array($input)) {
        throw new PhpletHttpError(400, 'The request must be a JSON object.');
    }
    return $input;
}

function phplet_handle_api(): never
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        phplet_json(['ok' => false, 'error' => 'This endpoint accepts POST only.'], 405);
    }

    try {
        phplet_require_csrf();
        $input = phplet_request_json();
        $action = $_GET['api'] ?? '';
        $saved = match ($action) {
            'save' => phplet_save_note($input),
            'delete' => phplet_delete_note($input),
            default => throw new PhpletHttpError(404, 'Unknown action.'),
        };
        phplet_json([
            'ok' => true,
            'result' => $saved['result'],
            'documentRevision' => $saved['document']['revision'],
        ]);
    } catch (PhpletConflict $error) {
        phplet_json(['ok' => false, 'error' => $error->getMessage(), 'current' => $error->current], 409);
    } catch (PhpletHttpError $error) {
        phplet_json(['ok' => false, 'error' => $error->getMessage()], $error->status);
    } catch (Throwable $error) {
        error_log('phplet save failed: ' . $error->getMessage());
        phplet_json(['ok' => false, 'error' => 'The save could not be completed. The existing file is unchanged.'], 500);
    }
}

function phplet_download(): never
{
    $path = phplet_path();
    $name = 'phplet-' . gmdate('Y-m-d-His') . '.php';
    $handle = @fopen($path, 'rb');
    $stat = $handle === false ? false : fstat($handle);
    if ($handle === false || $stat === false) {
        http_response_code(500);
        exit('The snapshot could not be opened.');
    }
    header('Content-Type: application/x-httpd-php');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . (string) $stat['size']);
    header('Cache-Control: no-store');
    fpassthru($handle);
    fclose($handle);
    exit;
}

function phplet_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function phplet_render_failure(Throwable $error): never
{
    error_log('phplet read failed: ' . $error->getMessage());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>phplet needs attention</title>
<style>body{margin:0;background:#f3f1ea;color:#22231f;font:17px/1.6 ui-serif,Georgia,serif}.box{max-width:42rem;margin:12vh auto;padding:2rem;border-top:3px solid #9b342f}h1{font-size:2rem}code{font:14px ui-monospace,monospace;background:#e9e6dc;padding:.15rem .35rem}</style>
<main class="box"><h1>The notes are still here, but they cannot be read.</h1><p>The embedded data failed validation, so phplet has stopped instead of replacing it. Restore a known-good copy or inspect the data after <code>__halt_compiler()</code>.</p></main></html><?php
    exit;
}

function phplet_run(): void
{
    phplet_require_access();

    if (isset($_GET['api'])) {
        phplet_handle_api();
    }
    if (isset($_GET['download'])) {
        phplet_download();
    }

    try {
        $document = phplet_read();
    } catch (Throwable $error) {
        phplet_render_failure($error);
    }

    $csrf = phplet_csrf_token();
    $nonce = base64_encode(random_bytes(18));
    $path = phplet_path();
    $pathStat = @stat($path);
    $writable = is_readable($path) && is_writable(dirname($path)) && ($pathStat['nlink'] ?? 1) === 1;
    $boot = [
        'document' => $document,
        'writable' => $writable,
        'bytes' => filesize($path),
    ];
    $bootJson = json_encode(
        $boot,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; style-src 'nonce-$nonce'; script-src 'nonce-$nonce'; connect-src 'self'; img-src data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    ?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="phplet-csrf" content="<?= phplet_h($csrf) ?>">
    <title>phplet — a small place for notes</title>
    <style nonce="<?= phplet_h($nonce) ?>">
        @layer reset, theme, base, layout, components, states;

        @layer reset {
            *, *::before, *::after { box-sizing: border-box; }
            html { -webkit-text-size-adjust: 100%; }
            body, h1, h2, h3, p, blockquote, pre { margin: 0; }
            button, input, textarea { font: inherit; }
            button { color: inherit; }
        }

        /* CHANGE THE LOOK HERE. The rest of the interface uses these tokens. */
        @layer theme {
            :root {
                color-scheme: light;
                --canvas: #f1efe8;
                --paper: #fffef9;
                --ink: #20221f;
                --muted: #666960;
                --faint: #72756c;
                --line: #d7d4c9;
                --line-strong: #b7b4aa;
                --accent: #176b63;
                --accent-hover: #10554f;
                --accent-wash: #e2efeb;
                --accent-ink: #ffffff;
                --danger: #9b342f;
                --danger-wash: #f6e7e3;
                --selection: #bcded7;
                --shadow: 0 18px 50px rgb(31 35 31 / .16);
                --overlay: rgb(10 14 11 / .42);
                --font-ui: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                --font-copy: ui-serif, "Iowan Old Style", "Palatino Linotype", Palatino, Georgia, serif;
                --font-code: ui-monospace, "SFMono-Regular", Consolas, monospace;
                --measure: 68ch;
                --story-width: 54rem;
                --title-size: clamp(2rem, 5vw, 3rem);
                --sidebar: 17.5rem;
                --bar: 3.5rem;
                --radius-sm: 3px;
                --radius: 6px;
                --space-1: .25rem;
                --space-2: .5rem;
                --space-3: .75rem;
                --space-4: 1rem;
                --space-5: 1.5rem;
                --space-6: 2rem;
                --space-7: 3rem;
                --motion: 140ms ease;
            }
            html[data-theme="dark"] {
                color-scheme: dark;
                --canvas: #181b19;
                --paper: #202421;
                --ink: #ecece5;
                --muted: #a9ada5;
                --faint: #858b83;
                --line: #393e39;
                --line-strong: #555b55;
                --accent: #72bdb2;
                --accent-hover: #91cec5;
                --accent-wash: #253d39;
                --accent-ink: #102a26;
                --danger: #e6877e;
                --danger-wash: #412b29;
                --selection: #315e57;
                --shadow: 0 18px 50px rgb(0 0 0 / .35);
            }
        }

        @layer base {
            html { scroll-behavior: smooth; background: var(--canvas); }
            body { min-height: 100vh; overflow-x: hidden; background: var(--canvas); color: var(--ink); font-family: var(--font-ui); }
            ::selection { background: var(--selection); }
            a { color: var(--accent); text-underline-offset: .17em; }
            button, a, input, textarea, summary { outline-color: var(--accent); }
            :focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
            button { border: 0; background: none; cursor: pointer; }
            button:disabled { cursor: not-allowed; opacity: .5; }
            .skip-link { position: fixed; z-index: 100; left: 1rem; top: -5rem; padding: .7rem 1rem; background: var(--ink); color: var(--paper); }
            .skip-link:focus { top: 1rem; }
        }

        @layer layout {
            .app-bar { position: sticky; z-index: 30; top: 0; height: var(--bar); display: grid; grid-template-columns: var(--sidebar) 1fr auto; align-items: center; border-bottom: 1px solid var(--line); background: var(--paper); }
            .brand { height: 100%; display: flex; align-items: center; gap: .65rem; padding: 0 var(--space-5); border-right: 1px solid var(--line); font-family: var(--font-copy); font-size: 1.25rem; font-weight: 700; letter-spacing: -.02em; }
            .brand-mark { width: .7rem; height: .7rem; border: 2px solid var(--accent); transform: rotate(45deg); }
            .bar-context { min-width: 0; padding: 0 var(--space-5); color: var(--muted); font-size: .79rem; letter-spacing: .02em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .bar-actions { display: flex; align-items: center; gap: .35rem; padding-right: var(--space-4); }
            .shell { display: grid; grid-template-columns: var(--sidebar) minmax(0, 1fr); min-height: calc(100vh - var(--bar)); }
            .library { position: sticky; top: var(--bar); align-self: start; height: calc(100vh - var(--bar)); overflow: auto; padding: var(--space-5); border-right: 1px solid var(--line); background: var(--canvas); }
            .story-wrap { min-width: 0; overflow: hidden; background: var(--paper); }
            .story { width: min(100%, var(--story-width)); min-height: calc(100vh - var(--bar)); margin: 0 auto; padding: var(--space-7) clamp(1.25rem, 5vw, 4rem) 18vh; }
            .mobile-only, .drawer-shade { display: none; }
        }

        @layer components {
            .button { min-height: 2.75rem; display: inline-flex; align-items: center; justify-content: center; gap: .45rem; padding: .5rem .8rem; border: 1px solid var(--line-strong); border-radius: var(--radius-sm); color: var(--ink); font-size: .84rem; font-weight: 650; transition: border-color var(--motion), background var(--motion), color var(--motion); }
            .button:hover { border-color: var(--ink); background: var(--canvas); }
            .button-primary { border-color: var(--accent); background: var(--accent); color: var(--accent-ink); }
            .button-primary:hover { border-color: var(--accent-hover); background: var(--accent-hover); }
            .button-quiet { border-color: transparent; color: var(--muted); }
            .button-quiet:hover { border-color: var(--line); color: var(--ink); }
            .button-danger { border-color: var(--danger); color: var(--danger); }
            .icon-button { width: 2.75rem; height: 2.75rem; display: grid; place-items: center; border: 1px solid transparent; border-radius: var(--radius-sm); color: var(--muted); }
            .icon-button:hover { border-color: var(--line); color: var(--ink); background: var(--canvas); }
            .icon-button svg { width: 1.05rem; height: 1.05rem; fill: none; stroke: currentColor; stroke-width: 1.8; }
            .search { position: relative; margin-bottom: var(--space-5); }
            .search label { display: block; margin-bottom: .45rem; color: var(--muted); font-size: .72rem; font-weight: 750; letter-spacing: .1em; text-transform: uppercase; }
            .search input { width: 100%; height: 2.65rem; border: 0; border-bottom: 1px solid var(--line-strong); border-radius: 0; background: transparent; color: var(--ink); }
            .search input::placeholder { color: var(--faint); }
            .library-heading { display: flex; align-items: baseline; justify-content: space-between; margin: var(--space-6) 0 var(--space-2); color: var(--muted); font-size: .72rem; font-weight: 750; letter-spacing: .1em; text-transform: uppercase; }
            .note-count { color: var(--faint); font-variant-numeric: tabular-nums; }
            .library-list { list-style: none; margin: 0; padding: 0; }
            .library-item { width: 100%; display: block; position: relative; padding: .75rem .6rem .75rem .85rem; border-left: 2px solid transparent; text-align: left; }
            .library-item:hover { background: color-mix(in srgb, var(--paper) 58%, transparent); }
            .library-item[aria-current="true"] { border-left-color: var(--accent); background: var(--accent-wash); }
            .library-title { display: block; overflow: hidden; color: var(--ink); font-family: var(--font-copy); font-size: 1rem; font-weight: 650; line-height: 1.25; text-overflow: ellipsis; white-space: nowrap; }
            .library-meta { display: block; margin-top: .3rem; overflow: hidden; color: var(--faint); font-size: .72rem; text-overflow: ellipsis; white-space: nowrap; }
            .library-empty { padding: 1rem .8rem; color: var(--muted); font-size: .85rem; line-height: 1.5; }
            .storage { margin-top: var(--space-6); padding-top: var(--space-4); border-top: 1px solid var(--line); color: var(--muted); font-size: .75rem; line-height: 1.55; }
            .storage strong { color: var(--ink); font-weight: 650; }
            .storage a { display: inline-block; margin-top: .45rem; }
            .note { position: relative; scroll-margin-top: calc(var(--bar) + var(--space-4)); padding: 0 0 var(--space-7); border-top: 1px solid var(--line); }
            .note + .note { margin-top: var(--space-7); }
            .note:first-child { border-top: 0; }
            .note-header { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: var(--space-5); align-items: start; padding-top: var(--space-2); margin-bottom: var(--space-5); }
            .note:first-child .note-header { padding-top: 0; }
            .note-title { max-width: 18ch; font-family: var(--font-copy); font-size: var(--title-size); font-weight: 600; letter-spacing: -.035em; line-height: 1.03; text-wrap: balance; }
            .note-actions { display: flex; gap: .15rem; }
            .note-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .7rem; margin: -.45rem 0 var(--space-5); color: var(--faint); font-size: .75rem; }
            .tag { display: inline-flex; align-items: center; min-height: 1.55rem; padding: .18rem .48rem; border-radius: 999px; background: var(--accent-wash); color: var(--accent); font-size: .72rem; font-weight: 650; }
            button.tag:hover { text-decoration: underline; }
            .prose { min-width: 0; max-width: var(--measure); overflow-wrap: break-word; color: var(--ink); font-family: var(--font-copy); font-size: clamp(1.05rem, 2vw, 1.14rem); line-height: 1.72; }
            .prose > * + * { margin-top: 1.05em; }
            .prose h1, .prose h2, .prose h3 { margin-top: 1.55em; font-weight: 650; line-height: 1.2; letter-spacing: -.02em; }
            .prose h1 { font-size: 1.7em; } .prose h2 { font-size: 1.4em; } .prose h3 { font-size: 1.18em; }
            .prose ul, .prose ol { padding-left: 1.25em; }
            .prose li + li { margin-top: .3em; }
            .prose blockquote { padding-left: 1em; border-left: 2px solid var(--accent); color: var(--muted); font-style: italic; }
            .prose code { overflow-wrap: anywhere; padding: .12em .32em; border-radius: var(--radius-sm); background: var(--canvas); font-family: var(--font-code); font-size: .82em; }
            .prose pre { overflow: auto; padding: 1rem; border: 1px solid var(--line); background: var(--canvas); font: .83rem/1.55 var(--font-code); }
            .prose pre code { padding: 0; background: none; font: inherit; }
            .prose hr { margin: 1.8em 0; border: 0; border-top: 1px solid var(--line); }
            .empty-story { max-width: 32rem; padding: 12vh 0; }
            .empty-story .kicker { margin-bottom: var(--space-3); color: var(--accent); font-size: .72rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
            .empty-story h2 { margin-bottom: var(--space-4); font-family: var(--font-copy); font-size: clamp(1.8rem, 6vw, 2.6rem); font-weight: 550; letter-spacing: -.035em; line-height: 1.05; }
            .empty-story p { color: var(--muted); line-height: 1.6; }
            .empty-story .button { margin-top: var(--space-5); }
            .editor { padding-top: var(--space-2); }
            .editor-grid { display: grid; gap: var(--space-5); }
            .field label, .preview-label { display: flex; justify-content: space-between; margin-bottom: .5rem; color: var(--muted); font-size: .72rem; font-weight: 760; letter-spacing: .09em; text-transform: uppercase; }
            .field input, .field textarea { width: 100%; border: 1px solid var(--line-strong); border-radius: var(--radius); background: var(--paper); color: var(--ink); transition: border-color var(--motion), box-shadow var(--motion); }
            .field input:focus, .field textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 16%, transparent); }
            .field-title input { padding: .55rem 0; border-width: 0 0 1px; border-radius: 0; font-family: var(--font-copy); font-size: clamp(2rem, 6vw, 3rem); font-weight: 600; letter-spacing: -.03em; }
            .field textarea { min-height: 15rem; resize: vertical; padding: 1rem; font: .94rem/1.65 var(--font-code); tab-size: 2; }
            .field-tags input { min-height: 2.7rem; padding: .6rem .75rem; }
            .field-hint { margin-top: .45rem; color: var(--faint); font-size: .75rem; line-height: 1.45; }
            .editor-preview { padding: var(--space-5) 0; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
            .editor-actions { position: sticky; z-index: 5; bottom: 0; display: flex; align-items: center; gap: .6rem; padding: .8rem 0; background: var(--paper); }
            .save-status { min-width: 0; margin-left: .3rem; color: var(--muted); font-size: .8rem; }
            .save-status[data-kind="error"] { color: var(--danger); }
            .delete-row { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; margin-top: var(--space-5); padding: .8rem; border-left: 3px solid var(--danger); background: var(--danger-wash); font-size: .84rem; }
            .delete-row span { margin-right: auto; }
            .global-status { color: var(--muted); }
            .global-status[data-kind="error"] { color: var(--danger); }
            .visually-hidden { position: absolute !important; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
            noscript { display: block; padding: 2rem; font-family: var(--font-ui); }
        }

        @layer states {
            [hidden] { display: none !important; }
            .icon-button.mobile-only { display: none; }
            @media (max-width: 760px) {
                .app-bar { grid-template-columns: auto 1fr auto; }
                .brand { padding: 0 .8rem; border-right: 0; }
                .brand-mark { display: none; }
                .bar-context { padding: 0 .4rem; text-align: center; }
                .bar-actions { padding-right: .5rem; }
                .bar-actions .button-primary { width: 2.75rem; padding: 0; font-size: 0; }
                .bar-actions .button-primary::after { content: "+"; font-size: 1.35rem; font-weight: 400; }
                .icon-button.mobile-only { display: grid; }
                .shell { display: block; }
                .library { position: fixed; z-index: 50; inset: var(--bar) auto 0 0; width: min(88vw, 20rem); height: calc(100vh - var(--bar)); height: calc(100dvh - var(--bar)); visibility: hidden; pointer-events: none; transform: translateX(-102%); transition: transform var(--motion), visibility 0s linear var(--motion); box-shadow: var(--shadow); }
                body[data-drawer="open"] { overflow: hidden; }
                body[data-drawer="open"] .library { visibility: visible; pointer-events: auto; transform: translateX(0); transition-delay: 0s; }
                .drawer-shade { position: fixed; z-index: 40; inset: var(--bar) 0 0; display: block; border: 0; background: var(--overlay); opacity: 0; pointer-events: none; transition: opacity var(--motion); }
                body[data-drawer="open"] .drawer-shade { opacity: 1; pointer-events: auto; }
                .story { padding: var(--space-6) 1.2rem 20vh; }
                .note-header { gap: .5rem; }
                .note-title { font-size: 2.35rem; }
                .note-actions { flex-direction: column; }
                .editor-actions { flex-wrap: wrap; padding-bottom: calc(.8rem + env(safe-area-inset-bottom)); }
                .save-status { order: 2; flex-basis: 100%; margin-left: 0; }
                .field input, .field textarea { font-size: 16px; }
            }
            @media (prefers-reduced-motion: reduce) {
                html { scroll-behavior: auto; }
                *, *::before, *::after { transition-duration: .01ms !important; animation-duration: .01ms !important; }
            }
            @media (forced-colors: active) {
                .library-item[aria-current="true"] { border-left-width: 4px; }
                .tag, .button { border: 1px solid currentColor; }
            }
        }
    </style>
</head>
<body>
    <a class="skip-link" href="#main">Skip to notes</a>
    <header class="app-bar">
        <div class="brand"><button class="icon-button mobile-only" id="menu-button" aria-label="Open note index" aria-expanded="false" aria-controls="library"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><span class="brand-mark" aria-hidden="true"></span><span>phplet</span></div>
        <div class="bar-context"><span class="global-status" id="global-status" aria-live="polite">one file · <span id="file-size"></span></span></div>
        <div class="bar-actions">
            <button class="icon-button" id="theme-button" aria-label="Use dark theme" title="Change theme"><svg viewBox="0 0 24 24"><path d="M12 3v2m0 14v2M3 12h2m14 0h2M5.64 5.64l1.42 1.42m9.88 9.88 1.42 1.42m0-12.72-1.42 1.42M7.06 16.94l-1.42 1.42"/><circle cx="12" cy="12" r="4"/></svg></button>
            <button class="button button-primary" id="new-button">New note</button>
        </div>
    </header>
    <div class="shell">
        <aside class="library" id="library" aria-label="Note index">
            <div class="search"><label for="search-input">Find a note</label><input id="search-input" type="search" placeholder="Title, text, or #tag" autocomplete="off"></div>
            <div class="library-heading"><span>Notes</span><span class="note-count" id="note-count"></span></div>
            <nav aria-label="All notes"><ul class="library-list" id="library-list"></ul></nav>
            <div class="storage"><strong id="storage-state"></strong><br><a href="?download=1">Download a snapshot</a></div>
        </aside>
        <button class="drawer-shade" id="drawer-shade" aria-label="Close note index" aria-hidden="true" tabindex="-1"></button>
        <main class="story-wrap" id="main" tabindex="-1"><h1 class="visually-hidden">phplet notes</h1><div class="story" id="story"></div></main>
    </div>
    <noscript>phplet needs JavaScript for its live editor. The data remains ordinary JSON inside this file.</noscript>
    <script type="application/json" id="phplet-state" nonce="<?= phplet_h($nonce) ?>"><?= $bootJson ?></script>
    <script nonce="<?= phplet_h($nonce) ?>">
    (() => {
        'use strict';

        const boot = JSON.parse(document.querySelector('#phplet-state').textContent);
        const csrf = document.querySelector('meta[name="phplet-csrf"]').content;
        const notes = new Map(Object.entries(boot.document.notes || {}));
        const els = Object.fromEntries([
            'story', 'library-list', 'search-input', 'note-count', 'new-button',
            'global-status', 'file-size', 'storage-state', 'theme-button',
            'menu-button', 'drawer-shade', 'library', 'main'
        ].map(id => [id, document.getElementById(id)]));
        const storageScope = `phplet:${location.pathname}:`;
        const mobileViewport = matchMedia('(max-width: 760px)');

        let openNotes = readOpenNotes();
        let editing = null;
        let pendingDelete = null;
        let query = '';
        let draftTimer = null;
        let previewTimer = null;
        let searchTimer = null;

        function sessionRead(key) {
            try { return window.sessionStorage.getItem(key); }
            catch (_) { return null; }
        }

        function sessionWrite(key, value) {
            try { window.sessionStorage.setItem(key, value); return true; }
            catch (_) { return false; }
        }

        function sessionRemove(key) {
            try { window.sessionStorage.removeItem(key); }
            catch (_) {}
        }

        function localRead(key) {
            try { return window.localStorage.getItem(key); }
            catch (_) { return null; }
        }

        function localWrite(key, value) {
            try { window.localStorage.setItem(key, value); }
            catch (_) {}
        }

        function element(tag, className, text) {
            const node = document.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = text;
            return node;
        }

        function iconButton(label, path, onClick) {
            const button = element('button', 'icon-button');
            button.type = 'button';
            button.setAttribute('aria-label', label);
            button.title = label;
            button.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${path}"/></svg>`;
            button.addEventListener('click', onClick);
            return button;
        }

        function sortedNotes() {
            return [...notes.values()].sort((a, b) => b.updated.localeCompare(a.updated));
        }

        function readOpenNotes() {
            try {
                const stored = JSON.parse(sessionRead(`${storageScope}open`) || '[]');
                const valid = Array.isArray(stored) ? stored.filter(id => notes.has(id)) : [];
                if (valid.length) return [...new Set(valid)];
            } catch (_) {}
            const first = sortedNotes()[0];
            return first ? [first.id] : [];
        }

        function saveOpenNotes() {
            sessionWrite(`${storageScope}open`, JSON.stringify(openNotes));
        }

        function humanBytes(bytes) {
            if (bytes < 1024) return `${bytes} B`;
            if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KiB`;
            return `${(bytes / 1024 / 1024).toFixed(1)} MiB`;
        }

        function shortDate(value) {
            const date = new Date(value);
            return Number.isNaN(date.valueOf()) ? '' : new Intl.DateTimeFormat(undefined, {month: 'short', day: 'numeric', year: date.getFullYear() === new Date().getFullYear() ? undefined : 'numeric'}).format(date);
        }

        function setGlobalStatus(message, kind = '') {
            els['global-status'].dataset.kind = kind;
            els['global-status'].textContent = message;
        }

        function updateDrawerAccess() {
            const open = document.body.dataset.drawer === 'open';
            if (mobileViewport.matches) {
                els.library.toggleAttribute('inert', !open);
                els.main.toggleAttribute('inert', open);
                document.querySelector('.bar-actions').toggleAttribute('inert', open);
                if (open) {
                    els.library.setAttribute('role', 'dialog');
                    els.library.setAttribute('aria-modal', 'true');
                } else {
                    els.library.removeAttribute('role');
                    els.library.removeAttribute('aria-modal');
                }
            } else {
                els.library.removeAttribute('inert');
                els.main.removeAttribute('inert');
                document.querySelector('.bar-actions').removeAttribute('inert');
                els.library.removeAttribute('role');
                els.library.removeAttribute('aria-modal');
            }
            els['drawer-shade'].tabIndex = open ? 0 : -1;
            els['drawer-shade'].setAttribute('aria-hidden', open ? 'false' : 'true');
        }

        function openDrawer() {
            if (!mobileViewport.matches) return;
            document.body.dataset.drawer = 'open';
            els['menu-button'].setAttribute('aria-expanded', 'true');
            updateDrawerAccess();
            requestAnimationFrame(() => els['search-input'].focus());
        }

        function closeDrawer(restoreFocus = false) {
            document.body.dataset.drawer = '';
            els['menu-button'].setAttribute('aria-expanded', 'false');
            updateDrawerAccess();
            if (restoreFocus && mobileViewport.matches) els['menu-button'].focus();
        }

        function resolveNote(target, encoded = false) {
            let clean = String(target || '');
            if (encoded) {
                try { clean = decodeURIComponent(clean); }
                catch (_) {}
            }
            clean = clean.toLowerCase();
            if (notes.has(clean)) return clean;
            const match = [...notes.values()].find(note => note.title.toLowerCase() === clean);
            return match?.id || null;
        }

        function openNote(id, updateHash = true) {
            if (!notes.has(id)) return;
            openNotes = [id, ...openNotes.filter(open => open !== id)];
            saveOpenNotes();
            if (updateHash) history.replaceState(null, '', `#${encodeURIComponent(id)}`);
            render();
            closeDrawer();
            requestAnimationFrame(() => {
                const note = document.getElementById(`note-${id}`);
                note?.scrollIntoView({block: 'start'});
                note?.querySelector('.note-title')?.focus();
            });
        }

        function closeNote(id) {
            if (editing?.id === id) editing = null;
            openNotes = openNotes.filter(open => open !== id);
            saveOpenNotes();
            history.replaceState(null, '', openNotes[0] ? `#${encodeURIComponent(openNotes[0])}` : `${location.pathname}${location.search}`);
            render();
            requestAnimationFrame(() => {
                const target = openNotes[0] ? document.querySelector(`#note-${CSS.escape(openNotes[0])} .note-title`) : els['new-button'];
                target?.focus();
            });
        }

        function renderLibrary() {
            const normalized = query.trim().toLowerCase();
            const tagOnly = normalized.startsWith('#') ? normalized.slice(1) : null;
            const filtered = sortedNotes().filter(note => {
                if (!normalized) return true;
                if (tagOnly !== null) return note.tags.some(tag => tag.toLowerCase().includes(tagOnly));
                return `${note.title}\n${note.body}\n${note.tags.join(' ')}`.toLowerCase().includes(normalized);
            });

            els['library-list'].replaceChildren();
            for (const note of filtered) {
                const li = element('li');
                const button = element('button', 'library-item');
                button.type = 'button';
                button.setAttribute('aria-current', openNotes[0] === note.id ? 'true' : 'false');
                button.append(element('span', 'library-title', note.title));
                const meta = note.tags.length ? note.tags.map(tag => `#${tag}`).join(' · ') : `Edited ${shortDate(note.updated)}`;
                button.append(element('span', 'library-meta', meta));
                button.addEventListener('click', () => openNote(note.id));
                li.append(button);
                els['library-list'].append(li);
            }
            if (!filtered.length) els['library-list'].append(element('li', 'library-empty', normalized ? 'No notes match that search.' : 'Your first note starts here.'));
            els['note-count'].textContent = normalized ? `${filtered.length}/${notes.size}` : String(notes.size);
        }

        function appendInline(parent, text) {
            const token = /(\[\[[^\]]+\]\]|\*\*[^*\n]+\*\*|`[^`\n]+`)/g;
            let cursor = 0;
            for (const match of text.matchAll(token)) {
                if (match.index > cursor) parent.append(document.createTextNode(text.slice(cursor, match.index)));
                const value = match[0];
                if (value.startsWith('[[')) {
                    const parts = value.slice(2, -2).split('|');
                    const label = (parts[0] || '').trim();
                    const target = (parts[1] || parts[0] || '').trim();
                    const link = element('a', '', label || target);
                    link.href = `#${encodeURIComponent(target)}`;
                    link.dataset.wiki = target;
                    parent.append(link);
                } else if (value.startsWith('**')) {
                    parent.append(element('strong', '', value.slice(2, -2)));
                } else {
                    parent.append(element('code', '', value.slice(1, -1)));
                }
                cursor = match.index + value.length;
            }
            if (cursor < text.length) parent.append(document.createTextNode(text.slice(cursor)));
        }

        function renderProse(body) {
            const root = element('div', 'prose');
            const lines = body.replace(/\r\n?/g, '\n').split('\n');
            let index = 0;
            while (index < lines.length) {
                const line = lines[index];
                if (!line.trim()) { index++; continue; }
                if (line.startsWith('```')) {
                    const code = [];
                    index++;
                    while (index < lines.length && !lines[index].startsWith('```')) code.push(lines[index++]);
                    if (index < lines.length) index++;
                    const pre = element('pre');
                    pre.append(element('code', '', code.join('\n')));
                    root.append(pre);
                    continue;
                }
                const heading = line.match(/^(#{1,3})\s+(.+)$/);
                if (heading) {
                    const h = element(`h${heading[1].length}`);
                    appendInline(h, heading[2]);
                    root.append(h); index++; continue;
                }
                if (/^---+$/.test(line.trim())) { root.append(element('hr')); index++; continue; }
                if (/^[-*]\s+/.test(line)) {
                    const list = element('ul');
                    while (index < lines.length && /^[-*]\s+/.test(lines[index])) {
                        const item = element('li'); appendInline(item, lines[index].replace(/^[-*]\s+/, '')); list.append(item); index++;
                    }
                    root.append(list); continue;
                }
                if (/^\d+\.\s+/.test(line)) {
                    const list = element('ol');
                    while (index < lines.length && /^\d+\.\s+/.test(lines[index])) {
                        const item = element('li'); appendInline(item, lines[index].replace(/^\d+\.\s+/, '')); list.append(item); index++;
                    }
                    root.append(list); continue;
                }
                if (/^>\s+/.test(line)) {
                    const quote = element('blockquote');
                    const parts = [];
                    while (index < lines.length && /^>\s+/.test(lines[index])) parts.push(lines[index++].replace(/^>\s+/, ''));
                    appendInline(quote, parts.join(' ')); root.append(quote); continue;
                }
                const paragraph = [];
                while (index < lines.length && lines[index].trim() && !/^(#{1,3}\s+|```|[-*]\s+|\d+\.\s+|>\s+|---+$)/.test(lines[index])) paragraph.push(lines[index++]);
                if (!paragraph.length) paragraph.push(lines[index++]);
                const p = element('p');
                paragraph.forEach((part, i) => { if (i) p.append(document.createElement('br')); appendInline(p, part); });
                root.append(p);
            }
            if (!root.childNodes.length) root.append(element('p', '', 'This note is empty.'));
            return root;
        }

        function renderPreview(body) {
            if (body.length <= 256 * 1024) return renderProse(body);
            return element('p', 'prose', 'Live preview is paused for this large note. Saving still works normally.');
        }

        function tagButton(tag) {
            const button = element('button', 'tag', tag);
            button.type = 'button';
            button.title = `Find notes tagged ${tag}`;
            button.addEventListener('click', () => {
                query = `#${tag}`;
                els['search-input'].value = query;
                renderLibrary();
                if (mobileViewport.matches) openDrawer();
                els['search-input'].focus();
            });
            return button;
        }

        function renderNote(note) {
            const article = element('article', 'note');
            article.id = `note-${note.id}`;
            const header = element('header', 'note-header');
            const title = element('h2', 'note-title', note.title);
            title.tabIndex = -1;
            header.append(title);
            const actions = element('div', 'note-actions');
            if (boot.writable) actions.append(iconButton('Edit note', 'M4 20h4L19 9l-4-4L4 16v4Zm9.5-13.5 4 4', () => editNote(note.id)));
            actions.append(iconButton('Close note', 'M6 6l12 12M18 6 6 18', () => closeNote(note.id)));
            header.append(actions);
            article.append(header);
            const meta = element('div', 'note-meta');
            meta.append(element('span', '', `Edited ${shortDate(note.updated)}`));
            note.tags.forEach(tag => meta.append(tagButton(tag)));
            article.append(meta, renderProse(note.body));

            if (pendingDelete === note.id) {
                const row = element('div', 'delete-row');
                row.tabIndex = -1;
                row.setAttribute('role', 'group');
                row.setAttribute('aria-label', 'Confirm deletion');
                row.append(element('span', '', 'Delete this note? This cannot be undone.'));
                const cancel = element('button', 'button button-quiet', 'Keep it');
                cancel.type = 'button'; cancel.addEventListener('click', () => {
                    pendingDelete = null;
                    renderStory();
                    requestAnimationFrame(() => document.querySelector(`#note-${CSS.escape(note.id)} .note-title`)?.focus());
                });
                const remove = element('button', 'button button-danger', 'Delete note');
                remove.type = 'button'; remove.addEventListener('click', () => deleteNote(note));
                row.append(cancel, remove); article.append(row);
            }
            return article;
        }

        function draftKey(id) { return `${storageScope}draft:${id || 'new'}`; }

        function storeDraft(key, draft) {
            const value = JSON.stringify(draft);
            if (value.length <= 512 * 1024) return sessionWrite(key, value);
            sessionRemove(key);
            return false;
        }

        function saveDraft() {
            if (!editing) return;
            clearTimeout(draftTimer);
            const key = draftKey(editing.id);
            const draft = {...editing, tags: [...editing.tags]};
            // Debounce browser recovery; the explicit PHP save is separate.
            draftTimer = setTimeout(() => storeDraft(key, draft), 250);
        }

        function flushDraft() {
            if (!editing) return;
            clearTimeout(draftTimer);
            draftTimer = null;
            storeDraft(draftKey(editing.id), {...editing, tags: [...editing.tags]});
        }

        function removeDraft(key) {
            clearTimeout(draftTimer);
            draftTimer = null;
            sessionRemove(key);
        }

        function readDraft(note) {
            try {
                const draft = JSON.parse(sessionRead(draftKey(note?.id || null)) || 'null');
                if (draft && draft.baseRevision === (note?.revision || 0)) return draft;
            } catch (_) {}
            return null;
        }

        function cancelEditing() {
            if (!editing) return;
            const id = editing.id;
            clearTimeout(previewTimer);
            removeDraft(draftKey(id));
            editing = null;
            render();
            requestAnimationFrame(() => {
                const target = id && notes.has(id) ? document.querySelector(`#note-${CSS.escape(id)} .note-title`) : els['new-button'];
                target?.focus();
            });
        }

        function editNote(id) {
            const note = notes.get(id);
            if (!note || !boot.writable) return;
            flushDraft();
            editing = readDraft(note) || {id, baseRevision: note.revision, title: note.title, body: note.body, tags: [...note.tags]};
            pendingDelete = null;
            if (!openNotes.includes(id)) openNotes.unshift(id);
            render();
            requestAnimationFrame(() => document.querySelector('.field-title input')?.focus());
        }

        function newNote() {
            if (!boot.writable) return;
            flushDraft();
            editing = readDraft(null) || {id: null, baseRevision: 0, title: '', body: '', tags: []};
            pendingDelete = null;
            render();
            closeDrawer();
            requestAnimationFrame(() => document.querySelector('.field-title input')?.focus());
        }

        function renderEditor() {
            const article = element('article', 'note editor');
            article.id = editing.id ? `note-${editing.id}` : 'note-new';
            const form = element('form', 'editor-grid');

            const titleField = element('div', 'field field-title');
            const titleLabel = element('label', '', 'Title'); titleLabel.htmlFor = 'edit-title';
            const title = element('input'); title.id = 'edit-title'; title.name = 'title'; title.required = true; title.maxLength = 240; title.value = editing.title; title.autocomplete = 'off';
            titleField.append(titleLabel, title);

            const bodyField = element('div', 'field');
            const bodyLabel = element('label', '', 'Note'); bodyLabel.htmlFor = 'edit-body';
            const body = element('textarea'); body.id = 'edit-body'; body.name = 'body'; body.value = editing.body; body.spellcheck = true;
            bodyField.append(bodyLabel, body, element('p', 'field-hint', 'Use # headings, - lists, **bold**, `code`, and [[wiki links]].'));

            const tagsField = element('div', 'field field-tags');
            const tagsLabel = element('label', '', 'Tags'); tagsLabel.htmlFor = 'edit-tags';
            const tags = element('input'); tags.id = 'edit-tags'; tags.name = 'tags'; tags.value = editing.tags.join(', '); tags.placeholder = 'ideas, reading, work'; tags.autocomplete = 'off';
            tagsField.append(tagsLabel, tags);

            const preview = element('div', 'editor-preview');
            preview.append(element('div', 'preview-label', 'Live preview'));
            const previewBody = renderPreview(editing.body);
            preview.append(previewBody);

            const actions = element('div', 'editor-actions');
            const save = element('button', 'button button-primary', 'Save note'); save.type = 'submit';
            const cancel = element('button', 'button button-quiet', 'Cancel'); cancel.type = 'button';
            cancel.addEventListener('click', cancelEditing);
            const status = element('span', 'save-status', 'Changes stay in this browser until you save.'); status.setAttribute('aria-live', 'polite');
            actions.append(save, cancel);
            if (editing.id) {
                const remove = element('button', 'button button-quiet', 'Delete'); remove.type = 'button';
                remove.addEventListener('click', () => {
                    pendingDelete = editing.id;
                    editing = null;
                    render();
                    requestAnimationFrame(() => document.querySelector('.delete-row')?.focus());
                });
                actions.append(remove);
            }
            actions.append(status);

            const updateFields = () => {
                editing.title = title.value;
                editing.body = body.value;
                editing.tags = tags.value.split(',').map(tag => tag.trim()).filter(Boolean);
                saveDraft();
                if (body.value.length > 512 * 1024 && status.dataset.kind !== 'error') {
                    status.textContent = 'Large draft: save before closing this tab.';
                }
            };
            title.addEventListener('input', updateFields);
            tags.addEventListener('input', updateFields);
            body.addEventListener('input', () => {
                updateFields();
                clearTimeout(previewTimer);
                previewTimer = setTimeout(() => preview.replaceChild(renderPreview(body.value), preview.lastElementChild), 120);
            });
            form.addEventListener('submit', async event => {
                event.preventDefault();
                clearTimeout(previewTimer);
                updateFields();
                save.disabled = true; cancel.disabled = true;
                status.dataset.kind = ''; status.textContent = 'Saving…';
                try {
                    const response = await api('save', editing);
                    const note = response.result;
                    const oldKey = draftKey(editing.id);
                    notes.set(note.id, note);
                    boot.document.revision = response.documentRevision;
                    boot.bytes = null;
                    removeDraft(oldKey);
                    openNotes = [note.id, ...openNotes.filter(id => id !== note.id)];
                    editing = null;
                    saveOpenNotes();
                    setGlobalStatus('Saved in this file');
                    history.replaceState(null, '', `#${encodeURIComponent(note.id)}`);
                    render();
                    requestAnimationFrame(() => document.querySelector(`#note-${CSS.escape(note.id)} .note-title`)?.focus());
                } catch (error) {
                    if (error.status === 409) {
                        if (error.payload.current) notes.set(error.payload.current.id, error.payload.current);
                        else if (editing.id) notes.delete(editing.id);
                    }
                    status.dataset.kind = 'error';
                    status.textContent = error.status === 409
                        ? (error.payload.current ? 'A newer version exists. Your draft is safe; cancel to inspect it.' : 'This note was deleted elsewhere. Your draft is still here.')
                        : error.message;
                    save.disabled = false; cancel.disabled = false;
                }
            });

            form.append(titleField, bodyField, tagsField, preview, actions);
            article.append(form);
            return article;
        }

        async function api(action, payload) {
            let response;
            try {
                response = await fetch(`?api=${encodeURIComponent(action)}`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                    body: JSON.stringify(payload)
                });
            } catch (_) {
                throw Object.assign(new Error('Could not reach the server.'), {status: 0, payload: {}});
            }
            let payloadBody = {};
            try { payloadBody = await response.json(); } catch (_) {}
            if (!response.ok) throw Object.assign(new Error(payloadBody.error || 'The save failed.'), {status: response.status, payload: payloadBody});
            return payloadBody;
        }

        async function deleteNote(note) {
            setGlobalStatus('Deleting…');
            try {
                const response = await api('delete', {id: note.id, baseRevision: note.revision});
                notes.delete(note.id);
                removeDraft(draftKey(note.id));
                openNotes = openNotes.filter(id => id !== note.id);
                history.replaceState(null, '', openNotes[0] ? `#${encodeURIComponent(openNotes[0])}` : `${location.pathname}${location.search}`);
                pendingDelete = null;
                boot.document.revision = response.documentRevision;
                saveOpenNotes();
                setGlobalStatus('Note deleted');
                render();
                requestAnimationFrame(() => {
                    const target = openNotes[0] ? document.querySelector(`#note-${CSS.escape(openNotes[0])} .note-title`) : els['new-button'];
                    target?.focus();
                });
            } catch (error) {
                pendingDelete = null;
                if (error.status === 409) {
                    if (error.payload.current) notes.set(error.payload.current.id, error.payload.current);
                    else {
                        notes.delete(note.id);
                        openNotes = openNotes.filter(id => id !== note.id);
                        history.replaceState(null, '', openNotes[0] ? `#${encodeURIComponent(openNotes[0])}` : `${location.pathname}${location.search}`);
                    }
                }
                setGlobalStatus(error.status === 409 ? 'That note changed; delete cancelled.' : error.message, 'error');
                render();
            }
        }

        function renderStory() {
            els.story.replaceChildren();
            if (editing?.id === null) els.story.append(renderEditor());
            for (const id of openNotes) {
                const note = notes.get(id);
                if (!note) continue;
                els.story.append(editing?.id === id ? renderEditor() : renderNote(note));
            }
            if (!els.story.childNodes.length) {
                const empty = element('section', 'empty-story');
                empty.append(element('p', 'kicker', notes.size ? 'No notes open' : 'A single-file notebook'));
                empty.append(element('h2', '', notes.size ? 'Choose a note.' : 'No notes yet.'));
                empty.append(element('p', '', notes.size ? 'Use the index, or create a new note.' : 'Create one here. Saving makes it part of this PHP file.'));
                if (boot.writable) {
                    const start = element('button', 'button button-primary', 'Write a note'); start.type = 'button'; start.addEventListener('click', newNote); empty.append(start);
                }
                els.story.append(empty);
            }
        }

        function render() {
            renderLibrary();
            renderStory();
            els['new-button'].disabled = !boot.writable;
            els['storage-state'].textContent = boot.writable ? '' : 'Read-only';
            els['file-size'].textContent = boot.bytes ? humanBytes(boot.bytes) : 'saved atomically';
        }

        els['search-input'].addEventListener('input', event => {
            query = event.target.value;
            clearTimeout(searchTimer);
            searchTimer = setTimeout(renderLibrary, 100);
        });
        els['new-button'].addEventListener('click', newNote);
        els['menu-button'].addEventListener('click', () => {
            const open = document.body.dataset.drawer !== 'open';
            if (open) openDrawer(); else closeDrawer(true);
        });
        els['drawer-shade'].addEventListener('click', () => closeDrawer(true));
        mobileViewport.addEventListener?.('change', () => {
            if (!mobileViewport.matches) closeDrawer();
            updateDrawerAccess();
        });
        document.addEventListener('click', event => {
            const link = event.target.closest('[data-wiki]');
            if (!link) return;
            const id = resolveNote(link.dataset.wiki);
            if (id) { event.preventDefault(); openNote(id); }
        });
        window.addEventListener('hashchange', () => {
            const id = resolveNote(location.hash.slice(1), true);
            if (id && openNotes[0] !== id) openNote(id, false);
        });
        document.addEventListener('keydown', event => {
            const typing = /^(INPUT|TEXTAREA)$/.test(event.target.tagName);
            if (event.key === 'Tab' && document.body.dataset.drawer === 'open') {
                const focusable = [...els.library.querySelectorAll('button:not(:disabled), input, a[href]')];
                const first = focusable[0], last = focusable.at(-1);
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
            }
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's' && editing) {
                event.preventDefault(); document.querySelector('.editor form')?.requestSubmit(); return;
            }
            if (event.key === 'Escape') {
                if (document.body.dataset.drawer === 'open') closeDrawer(true);
                else if (editing) cancelEditing();
                return;
            }
            if (typing || event.metaKey || event.ctrlKey || event.altKey) return;
            if (event.key === '/') {
                event.preventDefault();
                if (mobileViewport.matches) openDrawer(); else els['search-input'].focus();
            }
            if (event.key.toLowerCase() === 'n') newNote();
            if (event.key.toLowerCase() === 'e' && openNotes[0]) editNote(openNotes[0]);
        });

        function setTheme(theme) {
            document.documentElement.dataset.theme = theme;
            localWrite('phplet.theme', theme);
            els['theme-button'].setAttribute('aria-label', theme === 'dark' ? 'Use light theme' : 'Use dark theme');
        }
        const preferredTheme = localRead('phplet.theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        setTheme(preferredTheme);
        els['theme-button'].addEventListener('click', () => setTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark'));

        const initialHash = resolveNote(location.hash.slice(1), true);
        if (initialHash) openNotes = [initialHash, ...openNotes.filter(id => id !== initialHash)];
        updateDrawerAccess();
        render();
    })();
    </script>
</body>
</html>
<?php
}

if (!defined('PHPLET_LIBRARY_ONLY')) {
    phplet_run();
}

__halt_compiler();
PIPLET-DATA/1
{"format":1,"revision":1,"notes":{"welcome":{"id":"welcome","title":"A quieter web","body":"This is a **phplet**: the application and its notes live together in one PHP file.\n\nChoose **Edit note** above and watch your changes appear in the live preview.\n\n## markup\n\n- `#` makes a heading\n- `-` makes a list\n- `**words**` adds emphasis\n- `[[A quieter web|welcome]]` links one note to another\n\nTo change the interface, search for \"CHANGE THE LOOK HERE\" in the source.","tags":["welcome","simplicity"],"revision":1,"created":"2026-08-15T05:30:00Z","updated":"2026-08-15T05:30:00Z"}}}
