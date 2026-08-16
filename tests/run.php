<?php
declare(strict_types=1);

/* A dependency-free black-box test runner. The source phplet is never mutated. */

if (($argv[1] ?? '') === '--worker') {
    worker_main($argv);
}

$root = dirname(__DIR__);
$source = $root . '/phplet.php';
$temporaryRoot = sys_get_temp_dir() . '/phplet-tests-' . bin2hex(random_bytes(6));
$copy = $temporaryRoot . '/index.php';
$sourceHashBefore = is_file($source) ? hash_file('sha256', $source) : false;
$assertions = 0;
$liveWorkers = [];
$successMessage = null;
$temporaryRootOwned = false;
$temporaryRootIdentity = null;

function check(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function make_fixture(string $source, string $destination): bool
{
    $raw = @file_get_contents($source);
    $marker = "\nPIPLET-DATA/1\n";
    $markerAt = is_string($raw) ? strpos($raw, $marker) : false;
    if ($markerAt === false || !@copy($source, $destination)) return false;

    $document = [
        'format' => 1,
        'revision' => 1,
        'notes' => [
            'welcome' => [
                'id' => 'welcome',
                'title' => 'A quieter web',
                'body' => "This is a **phplet**: the application and its notes live together in one PHP file.\n\nChoose **Edit note** above and watch your changes appear in the live preview.\n\n## markup\n\n- `#` makes a heading\n- `-` makes a list\n- `**words**` adds emphasis\n- `[[A quieter web|welcome]]` links one note to another\n\nUse **Appearance** in the top bar to make the interface your own.",
                'tags' => ['welcome', 'simplicity'],
                'revision' => 1,
                'created' => '2026-08-15T05:30:00Z',
                'updated' => '2026-08-15T05:30:00Z',
            ],
        ],
    ];
    $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $fixture = substr($raw, 0, $markerAt) . $marker . $json . "\n";
    return file_put_contents($destination, $fixture) === strlen($fixture);
}

function css_theme_tokens(string $source, string $selector): array
{
    $quoted = preg_quote($selector, '/');
    check(preg_match('/' . $quoted . '\s*\{([^}]+)\}/', $source, $block) === 1, "Missing CSS theme block: $selector");
    $tokens = [];
    preg_match_all('/--([a-z-]+)\s*:\s*(#[0-9a-f]{6})\s*;/i', $block[1], $matches, PREG_SET_ORDER);
    foreach ($matches as $match) $tokens['--' . $match[1]] = strtolower($match[2]);
    return $tokens;
}

function css_braced_block(string $source, string $needle): string
{
    $start = strpos($source, $needle);
    $open = $start === false ? false : strpos($source, '{', $start + strlen($needle));
    if ($open === false) throw new RuntimeException("Missing CSS block: $needle");
    $depth = 0;
    for ($cursor = $open; $cursor < strlen($source); $cursor++) {
        if ($source[$cursor] === '{') $depth++;
        elseif ($source[$cursor] === '}' && --$depth === 0) return substr($source, $open + 1, $cursor - $open - 1);
    }
    throw new RuntimeException("Unclosed CSS block: $needle");
}

function worker_main(array $arguments): never
{
    $target = $arguments[2] ?? '';
    $action = $arguments[3] ?? '';
    $encoded = $arguments[4] ?? '';
    define('PHPLET_LIBRARY_ONLY', true);
    require $target;

    try {
        $input = $encoded === '' ? [] : json_decode(base64_decode($encoded, true), true, 16, JSON_THROW_ON_ERROR);
        $result = match ($action) {
            'read' => phplet_read(),
            'save' => phplet_save_note($input),
            'delete' => phplet_delete_note($input),
            'appearance' => phplet_save_appearance($input),
            'current-appearance' => phplet_current_appearance(phplet_read()),
            'prefix' => hash('sha256', substr(file_get_contents(phplet_path()), 0, phplet_code_offset())),
            'held-save' => worker_held_save($input),
            'large-save' => worker_large_save($input),
            'summary' => worker_summary(),
            'seed-notes' => worker_seed_notes($input),
            'large-output' => str_repeat('x', 1024 * 1024),
            'temp-info' => worker_temp_info($input),
            'inject-appearance' => worker_inject_appearance($input),
            'duplicate-token' => worker_duplicate_token(),
            'numeric-id' => worker_numeric_id(),
            'cookie-path' => phplet_cookie_path((string) ($input['script'] ?? '/')),
            default => throw new RuntimeException('Unknown worker action.'),
        };
        fwrite(STDOUT, json_encode(['ok' => true, 'value' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        exit(0);
    } catch (PhpletConflict $error) {
        fwrite(STDOUT, json_encode(['ok' => false, 'conflict' => true, 'current' => $error->current], JSON_THROW_ON_ERROR));
        exit(3);
    } catch (Throwable $error) {
        fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
        exit(2);
    }
}

function worker_held_save(array $input): array
{
    $hold = max(0, min(500000, (int) ($input['hold'] ?? 0)));
    $title = (string) ($input['title'] ?? 'Concurrent note');
    return phplet_mutate(function (array &$document) use ($hold, $title): array {
        if ($hold > 0) {
            usleep($hold);
        }
        $id = phplet_slug($title, $document['notes']);
        $now = phplet_now();
        $note = [
            'id' => $id,
            'title' => $title,
            'body' => 'Written by a concurrent worker.',
            'tags' => ['concurrency'],
            'revision' => $document['revision'] + 1,
            'created' => $now,
            'updated' => $now,
        ];
        $document['notes'][$id] = $note;
        return $note;
    });
}

function worker_large_save(array $input): array
{
    $bytes = max(0, (int) ($input['bytes'] ?? 0));
    $id = isset($input['id']) && is_string($input['id']) ? $input['id'] : null;
    $saved = phplet_save_note([
        'id' => $id,
        'baseRevision' => (int) ($input['baseRevision'] ?? 0),
        'title' => (string) ($input['title'] ?? 'Large note'),
        'body' => str_repeat((string) ($input['character'] ?? 'x'), $bytes),
        'tags' => ['large'],
    ]);
    return [
        'id' => $saved['result']['id'],
        'noteRevision' => $saved['result']['revision'],
        'documentRevision' => $saved['document']['revision'],
        'peakBytes' => memory_get_peak_usage(true),
    ];
}

function worker_summary(): array
{
    $document = phplet_read();
    return [
        'revision' => $document['revision'],
        'notes' => count($document['notes']),
        'bytes' => filesize(phplet_path()),
    ];
}

function worker_seed_notes(array $input): array
{
    $count = max(0, min(100, (int) ($input['count'] ?? 0)));
    return phplet_mutate(function (array &$document) use ($count): array {
        $now = phplet_now();
        for ($index = 0; $index < $count; $index++) {
            $id = "story-cap-$index";
            $document['notes'][$id] = [
                'id' => $id,
                'title' => "Story cap $index",
                'body' => "A bounded note $index.",
                'tags' => ['cap'],
                'revision' => $document['revision'] + 1,
                'created' => $now,
                'updated' => $now,
            ];
        }
        return ['notes' => count($document['notes'])];
    });
}

function worker_temp_info(array $input): array
{
    $previous = umask((int) ($input['umask'] ?? 0));
    $temp = '';
    $handle = null;
    try {
        [$temp, $handle] = phplet_open_temp(phplet_path());
        $stat = fstat($handle);
        return [
            'mode' => ((int) $stat['mode']) & 0777,
            'directory' => dirname($temp),
            'basename' => basename($temp),
        ];
    } finally {
        if (is_resource($handle)) fclose($handle);
        if ($temp !== '') @unlink($temp);
        umask($previous);
    }
}

function worker_inject_appearance(array $input): array
{
    return phplet_mutate(function (array &$document) use ($input): array {
        $document['appearance'] = ['revision' => $document['revision'] + 1, ...$input];
        return $document['appearance'];
    });
}

function worker_duplicate_token(): array
{
    return phplet_mutate(function (array &$document): array {
        $now = phplet_now();
        foreach (['duplicate-one', 'duplicate-two'] as $id) {
            $document['notes'][$id] = [
                'id' => $id, 'title' => $id, 'body' => '', 'tags' => [],
                'revision' => $document['revision'] + 1,
                'created' => $now, 'updated' => $now,
                'createToken' => str_repeat('e', 32),
            ];
        }
        return [];
    });
}

function worker_numeric_id(): array
{
    return phplet_mutate(function (array &$document): array {
        $document['notes']['01'] = [
            'id' => '01', 'title' => 'Numeric identifier', 'body' => '', 'tags' => [],
            'revision' => $document['revision'] + 1,
            'created' => phplet_now(), 'updated' => phplet_now(),
        ];
        return [];
    });
}

function worker_command(string $target, string $action, array $input = []): array|string
{
    $decoded = finish_worker(start_worker($target, $action, $input));
    return $decoded['value'];
}

function worker_conflict(string $target, string $action, array $input): array
{
    return finish_worker(start_worker($target, $action, $input), 3);
}

function start_worker(string $target, string $action, array $input, ?array $environment = null): array
{
    global $liveWorkers;
    $command = [PHP_BINARY, __FILE__, '--worker', $target, $action, base64_encode(json_encode($input, JSON_THROW_ON_ERROR))];
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start a concurrent worker.');
    }
    fclose($pipes[0]);
    $worker = [$process, $pipes, microtime(true) + 10];
    $liveWorkers[get_resource_id($process)] = $worker;
    return $worker;
}

function finish_worker(array $worker, int $expectedStatus = 0): array
{
    global $liveWorkers;
    [$process, $pipes, $deadline] = $worker;
    $resourceId = get_resource_id($process);
    $lastStatus = null;
    $stdout = '';
    $stderr = '';
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    do {
        foreach ([1, 2] as $index) {
            while (is_resource($pipes[$index]) && ($chunk = fread($pipes[$index], 8192)) !== false && $chunk !== '') {
                if ($index === 1) $stdout .= $chunk;
                else $stderr .= $chunk;
            }
        }
        $lastStatus = proc_get_status($process);
        if (!$lastStatus['running']) break;
        usleep(10000);
    } while (microtime(true) < $deadline);

    if ($lastStatus['running']) {
        $stopped = terminate_process($process, 1.0);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if ($stopped) proc_close($process);
        unset($liveWorkers[$resourceId]);
        throw new RuntimeException($stopped
            ? 'A worker exceeded its 10 second deadline.'
            : 'A worker exceeded its deadline and could not be terminated.');
    }
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedStatus = proc_close($process);
    $status = $closedStatus === -1 ? $lastStatus['exitcode'] : $closedStatus;
    unset($liveWorkers[$resourceId]);
    if ($status !== $expectedStatus) {
        throw new RuntimeException("Concurrent worker failed ($status): $stderr\n$stdout");
    }
    if ($stdout === '') {
        return ['ok' => false, 'stderr' => $stderr];
    }
    return json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
}

function free_port(): int
{
    $errorCode = 0;
    $errorMessage = '';
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException("Could not allocate a test port: $errorMessage");
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr(strrchr($address, ':'), 1);
}

function http_request(string $url, string $method = 'GET', array $headers = [], string $body = ''): array
{
    $options = ['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 5,
    ]];
    $responseBody = file_get_contents($url, false, stream_context_create($options));
    $responseHeaders = $http_response_header ?? [];
    $status = isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $match) ? (int) $match[1] : 0;
    return [$status, $responseHeaders, $responseBody === false ? '' : $responseBody];
}

function wait_for_server(int $port): void
{
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, .1);
        if (is_resource($socket)) {
            fclose($socket);
            return;
        }
        usleep(20000);
    }
    throw new RuntimeException('The PHP test server did not start.');
}

function chrome_binary(): ?string
{
    $configured = getenv('PHPLET_CHROME');
    if (is_string($configured) && is_executable($configured)) return $configured;
    $candidates = [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/opt/google/chrome/chrome',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/snap/bin/chromium',
    ];
    $path = getenv('PATH');
    if (is_string($path)) {
        foreach (array_filter(explode(PATH_SEPARATOR, $path), 'strlen') as $directory) {
            foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'] as $name) {
                $candidates[] = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
            }
        }
    }
    foreach (array_unique($candidates) as $candidate) {
        if (is_executable($candidate)) return $candidate;
    }
    return null;
}

function terminate_process($process, float $seconds = 1.0): bool
{
    if (!is_resource($process)) return true;
    $status = proc_get_status($process);
    if (!$status['running']) return true;
    @proc_terminate($process);
    $deadline = microtime(true) + $seconds;
    do {
        usleep(10000);
        $status = proc_get_status($process);
        if (!$status['running']) return true;
    } while (microtime(true) < $deadline);
    @proc_terminate($process, 9);
    $deadline = microtime(true) + $seconds;
    do {
        usleep(10000);
        $status = proc_get_status($process);
        if (!$status['running']) return true;
    } while (microtime(true) < $deadline);
    return false;
}

function run_bounded_command(array $command, float $seconds = 5.0): array
{
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start a subprocess.');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $drain = static function () use (&$pipes, &$stdout, &$stderr): void {
        foreach ([1, 2] as $index) {
            while (($chunk = fread($pipes[$index], 65536)) !== false && $chunk !== '') {
                if ($index === 1) $stdout .= $chunk;
                else $stderr .= $chunk;
            }
        }
    };
    $deadline = microtime(true) + $seconds;
    $lastStatus = null;
    do {
        $drain();
        $lastStatus = proc_get_status($process);
        if (!$lastStatus['running']) break;
        usleep(10000);
    } while (microtime(true) < $deadline);

    if ($lastStatus === null || $lastStatus['running']) {
        $stopped = terminate_process($process, 1.0);
        $drain();
        fclose($pipes[1]);
        fclose($pipes[2]);
        if ($stopped) proc_close($process);
        throw new RuntimeException($stopped ? 'A subprocess exceeded its deadline.' : 'A subprocess timed out and could not be terminated.');
    }

    $drain();
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedStatus = proc_close($process);
    $status = $closedStatus === -1 ? $lastStatus['exitcode'] : $closedStatus;
    return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
}

function run_browser_scenario(
    string $chrome,
    string $url,
    string $profile,
    string $signalFile,
    string $signalNamespace,
    ?string $windowSize = null
): string
{
    if (!mkdir($profile, 0700)) {
        throw new RuntimeException('Could not create the browser profile.');
    }
    $command = [
        $chrome, '--headless=new', '--disable-background-networking', '--disable-component-update',
        '--disable-extensions', '--disable-sync', '--no-proxy-server', '--no-first-run',
        '--no-default-browser-check', '--disable-gpu', '--virtual-time-budget=20000',
    ];
    if ($windowSize !== null) {
        $command[] = '--force-device-scale-factor=1';
        $command[] = "--window-size=$windowSize";
    }
    array_push($command, "--user-data-dir=$profile", '--dump-dom', $url);
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the browser smoke test.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + 45;
    $match = null;
    $signalPattern = '/^' . preg_quote($signalNamespace, '/') . ':result:([^\r\n]+)$/m';
    try {
        do {
            foreach ([1, 2] as $index) {
                while (($chunk = fread($pipes[$index], 65536)) !== false && $chunk !== '') {
                    if ($index === 1) $stdout .= $chunk;
                    else $stderr .= $chunk;
                }
            }
            if (preg_match('/<output id="phplet-browser-result">([^<]*)<\/output>/', $stdout, $found) === 1) {
                $match = html_entity_decode($found[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                break;
            }
            $signal = @file_get_contents($signalFile);
            if (is_string($signal) && preg_match_all($signalPattern, $signal, $signals) > 0) {
                $match = trim($signals[1][array_key_last($signals[1])]);
                break;
            }
            $status = proc_get_status($process);
            if (!$status['running']) break;
            usleep(20000);
        } while (microtime(true) < $deadline);
    } finally {
        $stopped = terminate_process($process, 1.0);
        foreach ([1, 2] as $index) {
            if ($index === 1) $stdout .= stream_get_contents($pipes[$index]);
            else $stderr .= stream_get_contents($pipes[$index]);
            fclose($pipes[$index]);
        }
        if ($stopped) proc_close($process);
        remove_tree($profile);
        if (!$stopped) throw new RuntimeException('The browser process could not be terminated.');
    }
    if ($match === null && preg_match('/<output id="phplet-browser-result">([^<]*)<\/output>/', $stdout, $found) === 1) {
        $match = html_entity_decode($found[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if ($match === null) {
        $signal = @file_get_contents($signalFile);
        if (is_string($signal) && preg_match_all($signalPattern, $signal, $signals) > 0) {
            $match = trim($signals[1][array_key_last($signals[1])]);
        }
    }
    if ($match === null) {
        throw new RuntimeException('Browser smoke test produced no result: ' . substr(trim($stderr), -500));
    }
    return $match;
}

function header_value(array $headers, string $name): ?string
{
    foreach ($headers as $header) {
        if (str_starts_with(strtolower($header), strtolower($name) . ':')) {
            return trim(substr($header, strlen($name) + 1));
        }
    }
    return null;
}

function contrast_ratio(string $first, string $second): float
{
    $luminance = static function (string $color): float {
        $hex = ltrim($color, '#');
        $channels = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        $linear = array_map(static function (int $channel): float {
            $value = $channel / 255;
            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, $channels);
        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    };
    $a = $luminance($first);
    $b = $luminance($second);
    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

function remove_tree(string $directory): void
{
    $stat = @lstat($directory);
    if ($stat === false || (((int) $stat['mode']) & 0170000) !== 0040000) return;
    @chmod($directory, 0700);
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
        if ($item->isDir() && !$item->isLink()) remove_tree($item->getPathname());
        else @unlink($item->getPathname());
    }
    @rmdir($directory);
}

function stop_live_workers(): bool
{
    global $liveWorkers;
    $stoppedAll = true;
    foreach ($liveWorkers as [$process, $pipes]) {
        $stopped = terminate_process($process, 1.0);
        foreach ($pipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
        if ($stopped) @proc_close($process);
        else $stoppedAll = false;
    }
    $liveWorkers = [];
    return $stoppedAll;
}

$exitStatus = 0;
try {
    check(is_file($source), 'phplet.php is missing.');
    check(mkdir($temporaryRoot, 0700), 'Could not create the test directory.');
    $temporaryRootOwned = true;
    $temporaryRootIdentity = lstat($temporaryRoot);
    check(is_array($temporaryRootIdentity), 'Could not identify the test directory.');
    $liveDocument = worker_command($source, 'read');
    check(($liveDocument['format'] ?? null) === 1, 'The live phplet data cannot be read.');
    check(make_fixture($source, $copy), 'Could not make an isolated test copy.');

    $initialLint = run_bounded_command([PHP_BINARY, '-l', $copy]);
    check($initialLint['status'] === 0, 'The initial phplet does not lint.');
    $timeoutObserved = false;
    try {
        run_bounded_command([PHP_BINARY, '-r', 'usleep(500000);'], .05);
    } catch (RuntimeException $error) {
        $timeoutObserved = str_contains($error->getMessage(), 'deadline');
    }
    check($timeoutObserved, 'The bounded subprocess runner did not enforce its deadline.');

    $prefixBefore = worker_command($copy, 'prefix');
    $initial = worker_command($copy, 'read');
    check($initial['format'] === 1, 'Unexpected data format.');
    check($initial['revision'] === 1, 'Unexpected initial document revision.');
    check(isset($initial['notes']['welcome']), 'The welcome note is missing.');
    $numericIdFailure = finish_worker(start_worker($copy, 'numeric-id', []), 2);
    check(str_contains($numericIdFailure['stderr'], 'Invalid note identifier'), 'A stored numeric-only note identifier passed validation.');
    check(worker_command($copy, 'cookie-path', ['script' => '/notes./phplet.php']) === '/notes./', 'A trailing dot was stripped from the CSRF cookie directory.');
    check(worker_command($copy, 'cookie-path', ['script' => '/phplet.php']) === '/', 'The root CSRF cookie path was malformed.');
    $sourceText = file_get_contents($source);
    check(is_string($sourceText), 'Could not read the production CSS for contrast checks.');
    check(
        preg_match('/font-size\s*:\s*var\(--title-size\)/', css_braced_block($sourceText, '.note-title')) === 1,
        'The note title stopped honoring the shared title-size token.'
    );
    $mobileCss = css_braced_block($sourceText, '@media (max-width: 760px)');
    preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $mobileCss, $mobileRules, PREG_SET_ORDER);
    $mobileNoteTitleValid = true;
    $mobileEditorTitleValid = false;
    foreach ($mobileRules as $rule) {
        if (str_contains($rule[1], '.note-title') && preg_match('/font-size\s*:\s*([^;]+)/', $rule[2], $size) === 1) {
            $mobileNoteTitleValid = trim($size[1]) === 'var(--title-size)';
        }
        if (str_contains($rule[1], '.field-title input') && preg_match('/font-size\s*:\s*var\(--title-size\)/', $rule[2]) === 1) {
            $mobileEditorTitleValid = true;
        }
    }
    check($mobileNoteTitleValid && $mobileEditorTitleValid, 'Mobile styles stopped honoring the shared title-size token.');
    foreach ([
        ':root',
        'html[data-theme="dark"]',
        'html[data-palette="ocean"]',
        'html[data-theme="dark"][data-palette="ocean"]',
        'html[data-palette="plum"]',
        'html[data-theme="dark"][data-palette="plum"]',
        'html[data-palette="mono"]',
        'html[data-theme="dark"][data-palette="mono"]',
    ] as $selector) {
        $tokens = css_theme_tokens($sourceText, $selector);
        check(
            isset($tokens['--faint'], $tokens['--canvas'], $tokens['--paper'], $tokens['--accent-wash'], $tokens['--line-strong'])
                && contrast_ratio($tokens['--faint'], $tokens['--canvas']) >= 4.5
                && contrast_ratio($tokens['--faint'], $tokens['--accent-wash']) >= 4.5
                && contrast_ratio($tokens['--line-strong'], $tokens['--canvas']) >= 3
                && contrast_ratio($tokens['--line-strong'], $tokens['--paper']) >= 3,
            "$selector text or control-boundary contrast is too low."
        );
    }
    $previousUmask = umask(0);
    $visibleTemp = tempnam(dirname($copy), '.phplet-visible-');
    umask($previousUmask);
    check(is_string($visibleTemp), 'The runtime could not create a temporary file for the first-visibility check.');
    $visiblePermissions = fileperms($visibleTemp);
    check($visiblePermissions !== false && ($visiblePermissions & 0777) === 0600, 'tempnam did not create mode 0600 at first visibility under umask 0000.');
    check(unlink($visibleTemp), 'Could not clean the first-visibility temporary file.');
    check(strlen(worker_command($copy, 'large-output')) === 1024 * 1024, 'The worker runner deadlocked or truncated a large response.');
    foreach ([0, 0777] as $testUmask) {
        $tempInfo = worker_command($copy, 'temp-info', ['umask' => $testUmask]);
        check($tempInfo['mode'] === 0600, 'A temporary snapshot was not private from first use.');
        check(realpath($tempInfo['directory']) === realpath(dirname($copy)), 'A temporary snapshot was not created beside the phplet.');
        check(str_contains($tempInfo['basename'], '.phplet-tmp-') && str_ends_with($tempInfo['basename'], '.php'), 'A temporary snapshot lost its guarded PHP name.');
    }

    $defaultAppearance = [
        'revision' => 0,
        'palette' => 'quiet',
        'font' => 'editorial',
        'scale' => 'comfortable',
        'measure' => 'balanced',
        'tokens' => [],
    ];
    $appearanceRoot = $temporaryRoot . '/appearance';
    check(mkdir($appearanceRoot, 0700) && make_fixture($source, $appearanceRoot . '/index.php'), 'Could not create the appearance fixture.');
    $appearanceCopy = $appearanceRoot . '/index.php';
    check(worker_command($appearanceCopy, 'current-appearance') === $defaultAppearance, 'A document without appearance settings did not use the defaults.');
    $appearancePrefix = worker_command($appearanceCopy, 'prefix');
    $defaultHash = hash_file('sha256', $appearanceCopy);
    clearstatcache(true, $appearanceCopy);
    $defaultInode = fileinode($appearanceCopy);
    $defaultNoop = worker_command($appearanceCopy, 'appearance', ['baseRevision' => 0, 'appearance' => array_diff_key($defaultAppearance, ['revision' => true])]);
    clearstatcache(true, $appearanceCopy);
    check($defaultNoop['document']['revision'] === 1 && $defaultNoop['result'] === $defaultAppearance, 'Saving effective appearance defaults was not a no-op.');
    check(hash_file('sha256', $appearanceCopy) === $defaultHash && fileinode($appearanceCopy) === $defaultInode, 'A default appearance no-op rewrote the file.');
    $oceanAppearance = [
        'palette' => 'ocean',
        'font' => 'modern',
        'scale' => 'large',
        'measure' => 'wide',
        'tokens' => [],
    ];
    $savedAppearance = worker_command($appearanceCopy, 'appearance', ['baseRevision' => 0, 'appearance' => $oceanAppearance]);
    $expectedOcean = ['revision' => 2] + $oceanAppearance;
    check($savedAppearance['result'] === $expectedOcean && $savedAppearance['document']['revision'] === 2, 'Appearance settings did not get a global commit revision.');
    check(worker_command($appearanceCopy, 'current-appearance') === $expectedOcean, 'Appearance settings did not persist across worker restarts.');
    check(worker_command($appearanceCopy, 'prefix') === $appearancePrefix, 'An appearance save changed the executable prefix.');
    $oceanHash = hash_file('sha256', $appearanceCopy);
    clearstatcache(true, $appearanceCopy);
    $oceanInode = fileinode($appearanceCopy);
    $oceanNoop = worker_command($appearanceCopy, 'appearance', ['baseRevision' => 2, 'appearance' => $oceanAppearance]);
    clearstatcache(true, $appearanceCopy);
    check($oceanNoop['document']['revision'] === 2 && $oceanNoop['result'] === $expectedOcean, 'An identical appearance retry was not a no-op.');
    check(hash_file('sha256', $appearanceCopy) === $oceanHash && fileinode($appearanceCopy) === $oceanInode, 'An identical appearance retry rewrote the file.');

    $unrelated = worker_command($appearanceCopy, 'save', [
        'id' => null, 'baseRevision' => 0, 'title' => 'Unrelated note', 'body' => '', 'tags' => [],
    ]);
    check($unrelated['result']['revision'] === 3, 'The unrelated note did not receive the next global revision.');
    $plumAppearance = [
        'palette' => 'plum',
        'font' => 'typewriter',
        'scale' => 'compact',
        'measure' => 'focused',
        'tokens' => [],
    ];
    $afterUnrelated = worker_command($appearanceCopy, 'appearance', ['baseRevision' => 2, 'appearance' => $plumAppearance]);
    check($afterUnrelated['result'] === ['revision' => 4] + $plumAppearance, 'An unrelated note edit caused an appearance conflict.');
    check(isset($afterUnrelated['document']['notes']['unrelated-note']), 'An appearance save lost an unrelated note edit.');

    $appearanceAbaRoot = $temporaryRoot . '/appearance-aba';
    check(mkdir($appearanceAbaRoot, 0700) && make_fixture($source, $appearanceAbaRoot . '/index.php'), 'Could not create the appearance revision fixture.');
    $appearanceAbaCopy = $appearanceAbaRoot . '/index.php';
    $firstAppearance = worker_command($appearanceAbaCopy, 'appearance', ['baseRevision' => 0, 'appearance' => $oceanAppearance]);
    $resetAppearance = worker_command($appearanceAbaCopy, 'appearance', ['baseRevision' => $firstAppearance['result']['revision'], 'appearance' => array_diff_key($defaultAppearance, ['revision' => true])]);
    $expectedReset = $defaultAppearance;
    $expectedReset['revision'] = 3;
    check($resetAppearance['result'] === $expectedReset, 'Restoring default appearance did not persist as a new revision.');
    $resetHash = hash_file('sha256', $appearanceAbaCopy);
    $staleAppearance = worker_conflict($appearanceAbaCopy, 'appearance', ['baseRevision' => 0, 'appearance' => $plumAppearance]);
    check($staleAppearance['current'] === $expectedReset, 'A stale appearance save crossed a customize/reset boundary.');
    check(hash_file('sha256', $appearanceAbaCopy) === $resetHash, 'A stale appearance save changed the file.');

    $invalidAppearanceRoot = $temporaryRoot . '/appearance-invalid';
    check(mkdir($invalidAppearanceRoot, 0700) && make_fixture($source, $invalidAppearanceRoot . '/index.php'), 'Could not create the invalid appearance fixture.');
    $invalidAppearanceCopy = $invalidAppearanceRoot . '/index.php';
    $invalidAppearanceHash = hash_file('sha256', $invalidAppearanceCopy);
    $missingAppearance = $oceanAppearance;
    unset($missingAppearance['measure']);
    $extraAppearance = $oceanAppearance;
    $extraAppearance['css'] = 'body { display: none }';
    $injectedAppearance = $oceanAppearance;
    $injectedAppearance['palette'] = 'quiet"} body { display:none } /*';
    $unknownTokenAppearance = $oceanAppearance;
    $unknownTokenAppearance['tokens'] = ['--not-a-token' => '#ffffff'];
    $invalidTokenAppearance = $oceanAppearance;
    $invalidTokenAppearance['tokens'] = ['--accent' => 'url(https://example.test)'];
    $injectedTokenAppearance = $oceanAppearance;
    $injectedTokenAppearance['tokens'] = ['--radius' => '10px; display:none'];
    $outOfRangeTokenAppearance = $oceanAppearance;
    $outOfRangeTokenAppearance['tokens'] = ['--radius' => '25px'];
    foreach ([
        ['baseRevision' => 0, 'appearance' => $missingAppearance],
        ['baseRevision' => 0, 'appearance' => $extraAppearance],
        ['baseRevision' => 0, 'appearance' => $injectedAppearance],
        ['baseRevision' => 0, 'appearance' => $unknownTokenAppearance],
        ['baseRevision' => 0, 'appearance' => $invalidTokenAppearance],
        ['baseRevision' => 0, 'appearance' => $injectedTokenAppearance],
        ['baseRevision' => 0, 'appearance' => $outOfRangeTokenAppearance],
        ['baseRevision' => 0, 'appearance' => 'ocean'],
    ] as $invalidAppearance) {
        $failure = finish_worker(start_worker($invalidAppearanceCopy, 'appearance', $invalidAppearance), 2);
        check(str_contains($failure['stderr'], 'PhpletHttpError'), 'Invalid appearance input was not rejected as a request error.');
    }
    check(hash_file('sha256', $invalidAppearanceCopy) === $invalidAppearanceHash, 'Invalid appearance input changed the file.');
    check(worker_command($invalidAppearanceCopy, 'current-appearance') === $defaultAppearance, 'Invalid appearance input changed the effective defaults.');

    $legacyAppearanceRoot = $temporaryRoot . '/appearance-legacy';
    check(mkdir($legacyAppearanceRoot, 0700) && make_fixture($source, $legacyAppearanceRoot . '/index.php'), 'Could not create the legacy appearance fixture.');
    $legacyAppearanceCopy = $legacyAppearanceRoot . '/index.php';
    worker_command($legacyAppearanceCopy, 'inject-appearance', [
        'palette' => 'retired-choice',
        'font' => 'modern',
        'scale' => 'large',
        'futureSetting' => ['kept' => true],
        'tokens' => ['--accent' => 'url(https://invalid.example)', '--future-token' => '#ffffff'],
    ]);
    $legacyEffective = worker_command($legacyAppearanceCopy, 'current-appearance');
    check($legacyEffective === ['revision' => 2, 'palette' => 'quiet', 'font' => 'modern', 'scale' => 'large', 'measure' => 'balanced', 'tokens' => []], 'A sparse or retired appearance record did not fall back safely.');
    $legacySaved = worker_command($legacyAppearanceCopy, 'appearance', ['baseRevision' => 2, 'appearance' => $plumAppearance]);
    check($legacySaved['result'] === ['revision' => 3] + $plumAppearance, 'A migrated appearance could not be saved.');
    $legacyRaw = worker_command($legacyAppearanceCopy, 'read');
    check(($legacyRaw['appearance']['futureSetting']['kept'] ?? false) === true, 'A future appearance field was erased by a known-setting save.');
    check(($legacyRaw['appearance']['tokens']['--future-token'] ?? null) === '#ffffff', 'A future design token was erased by a known-setting save.');

    $idempotentRoot = $temporaryRoot . '/idempotent-create';
    check(mkdir($idempotentRoot, 0700) && make_fixture($source, $idempotentRoot . '/index.php'), 'Could not create the idempotent-create fixture.');
    $idempotentCopy = $idempotentRoot . '/index.php';
    $createPayload = [
        'id' => null, 'baseRevision' => 0, 'createToken' => str_repeat('a', 32),
        'title' => 'Only once', 'body' => 'A retry is the same mutation.', 'tags' => ['retry'],
    ];
    $createdOnce = worker_command($idempotentCopy, 'save', $createPayload);
    $onceHash = hash_file('sha256', $idempotentCopy);
    clearstatcache(true, $idempotentCopy);
    $onceInode = fileinode($idempotentCopy);
    $createdAgain = worker_command($idempotentCopy, 'save', $createPayload);
    clearstatcache(true, $idempotentCopy);
    check($createdAgain['result'] === $createdOnce['result'] && $createdAgain['document']['revision'] === 2, 'A repeated tokenized create did not return its original note.');
    check(hash_file('sha256', $idempotentCopy) === $onceHash && fileinode($idempotentCopy) === $onceInode, 'A repeated tokenized create rewrote the file.');
    $changedRetry = $createPayload;
    $changedRetry['body'] = 'Different content';
    $retryConflict = worker_conflict($idempotentCopy, 'save', $changedRetry);
    check($retryConflict['current']['id'] === $createdOnce['result']['id'], 'A changed retry did not conflict with its original create.');
    check(hash_file('sha256', $idempotentCopy) === $onceHash, 'A changed create retry modified the file.');
    $updatedTokenNote = worker_command($idempotentCopy, 'save', [
        'id' => $createdOnce['result']['id'], 'baseRevision' => 2, 'createToken' => str_repeat('b', 32),
        'title' => 'Only once', 'body' => 'Updated', 'tags' => ['retry'],
    ]);
    check($updatedTokenNote['result']['createToken'] === str_repeat('a', 32), 'Updating a note replaced its stable creation token.');
    $badCreateToken = finish_worker(start_worker($idempotentCopy, 'save', [
        'id' => null, 'baseRevision' => 0, 'createToken' => 'not-a-token',
        'title' => 'Bad token', 'body' => '', 'tags' => [],
    ]), 2);
    check(str_contains($badCreateToken['stderr'], 'Invalid note creation token'), 'An invalid creation token was accepted.');

    $sameTokenRoot = $temporaryRoot . '/same-token-race';
    check(mkdir($sameTokenRoot, 0700) && make_fixture($source, $sameTokenRoot . '/index.php'), 'Could not create the same-token concurrency fixture.');
    $sameTokenCopy = $sameTokenRoot . '/index.php';
    $sameTokenPayload = [
        'id' => null, 'baseRevision' => 0, 'createToken' => str_repeat('c', 32),
        'title' => 'Concurrent retry', 'body' => 'one logical create', 'tags' => [],
    ];
    $sameTokenWorkers = [start_worker($sameTokenCopy, 'save', $sameTokenPayload), start_worker($sameTokenCopy, 'save', $sameTokenPayload)];
    $sameTokenResults = array_map(fn(array $worker): array => finish_worker($worker), $sameTokenWorkers);
    check($sameTokenResults[0]['value']['result']['id'] === $sameTokenResults[1]['value']['result']['id'], 'Concurrent retries created different notes.');
    $sameTokenDocument = worker_command($sameTokenCopy, 'read');
    check($sameTokenDocument['revision'] === 2 && count($sameTokenDocument['notes']) === 2, 'Concurrent retries produced more than one commit or note.');
    $duplicateTokenHash = hash_file('sha256', $sameTokenCopy);
    $duplicateTokenFailure = finish_worker(start_worker($sameTokenCopy, 'duplicate-token', []), 2);
    check(str_contains($duplicateTokenFailure['stderr'], 'Invalid note creation token'), 'Duplicate stored creation tokens passed document validation.');
    check(hash_file('sha256', $sameTokenCopy) === $duplicateTokenHash, 'Duplicate-token rejection changed the canonical file.');

    $abaRoot = $temporaryRoot . '/aba';
    check(mkdir($abaRoot, 0700) && make_fixture($source, $abaRoot . '/index.php'), 'Could not create the revision fixture.');
    $abaCopy = $abaRoot . '/index.php';
    $firstWelcomeEdit = worker_command($abaCopy, 'save', [
        'id' => 'welcome', 'baseRevision' => 1, 'title' => 'A quieter web', 'body' => 'first editor', 'tags' => [],
    ]);
    check($firstWelcomeEdit['result']['revision'] === 2, 'The first bundled-note edit did not advance its revision.');
    $staleWelcome = worker_conflict($abaCopy, 'save', [
        'id' => 'welcome', 'baseRevision' => 1, 'title' => 'A quieter web', 'body' => 'stale editor', 'tags' => [],
    ]);
    check($staleWelcome['current']['body'] === 'first editor', 'A stale bundled-note editor was not rejected.');
    worker_command($abaCopy, 'delete', ['id' => 'welcome', 'baseRevision' => 2]);
    $recreatedWelcome = worker_command($abaCopy, 'save', [
        'id' => null, 'baseRevision' => 0, 'title' => 'Welcome', 'body' => 'new generation', 'tags' => [],
    ]);
    check($recreatedWelcome['result']['id'] === 'welcome' && $recreatedWelcome['result']['revision'] === 4, 'Recreated slugs did not get a new generation revision.');
    $abaDelete = worker_conflict($abaCopy, 'delete', ['id' => 'welcome', 'baseRevision' => 2]);
    check($abaDelete['current']['body'] === 'new generation', 'A stale delete crossed a delete/recreate boundary.');

    $hostileBody = "A null follows: \0\r\n__halt_compiler();\r\nPIPLET-DATA/1\r\n<?php echo 'not code'; ?>\r\n</script>\r\nSnowman: ☃";
    $created = worker_command($copy, 'save', [
        'id' => null,
        'baseRevision' => 0,
        'title' => 'Unicode & hostile text',
        'body' => $hostileBody,
        'tags' => ['testing', '日本語'],
    ]);
    $note = $created['result'];
    check($note['revision'] === 2, 'A note should carry its global commit revision.');
    check($created['document']['revision'] === 2, 'The document revision did not advance.');
    check($note['body'] === $hostileBody, 'Marker-like hostile text did not round-trip exactly.');
    check(worker_command($copy, 'read')['notes'][$note['id']]['body'] === $hostileBody, 'The persisted hostile body changed on reload.');

    $updated = worker_command($copy, 'save', [
        'id' => $note['id'],
        'baseRevision' => 2,
        'title' => $note['title'],
        'body' => 'Updated safely.',
        'tags' => $note['tags'],
    ]);
    check($updated['result']['revision'] === 3, 'The note revision did not advance.');

    $conflict = worker_conflict($copy, 'save', [
        'id' => $note['id'], 'baseRevision' => 2, 'title' => 'Stale', 'body' => 'Must not win', 'tags' => [],
    ]);
    check($conflict['conflict'] === true, 'A stale update was not rejected.');
    check($conflict['current']['body'] === 'Updated safely.', 'The conflict did not return the current note.');

    $deleted = worker_command($copy, 'delete', ['id' => $note['id'], 'baseRevision' => 3]);
    check($deleted['result']['id'] === $note['id'], 'Delete returned the wrong note.');
    check(!isset($deleted['document']['notes'][$note['id']]), 'The note was not deleted.');

    $numeric = worker_command($copy, 'save', ['id' => null, 'baseRevision' => 0, 'title' => '123', 'body' => '', 'tags' => []]);
    check($numeric['result']['id'] === 'note-123', 'A numeric title produced an invalid PHP array key.');
    check($numeric['result']['revision'] === 5, 'A recreated note version must use the global commit number.');
    worker_command($copy, 'delete', ['id' => 'note-123', 'baseRevision' => 5]);

    // Force waiters to queue on an inode that the first worker will replace.
    $workers = [];
    $workers[] = start_worker($copy, 'held-save', ['title' => 'Worker 00', 'hold' => 180000]);
    usleep(25000);
    for ($index = 1; $index < 24; $index++) {
        $workers[] = start_worker($copy, 'held-save', ['title' => sprintf('Worker %02d', $index), 'hold' => 0]);
    }
    foreach ($workers as $worker) {
        $result = finish_worker($worker);
        check($result['ok'] === true, 'A concurrent writer failed.');
    }
    $afterConcurrency = worker_command($copy, 'read');
    for ($index = 0; $index < 24; $index++) {
        check(isset($afterConcurrency['notes'][sprintf('worker-%02d', $index)]), "Concurrent note $index was lost.");
    }
    check($afterConcurrency['revision'] === 30, 'Concurrent document revisions were lost.');
    check($prefixBefore === worker_command($copy, 'prefix'), 'A save changed the executable prefix.');
    check(glob($temporaryRoot . '/.phplet-tmp-*.php') === [], 'A temporary snapshot was left behind.');

    // Deterministically exercise the stale-inode retry. Instrument only this
    // disposable copy: A opens inode 1 and pauses; B replaces it with inode 2;
    // A resumes, rejects its stale descriptor, retries, and preserves B's save.
    $raceRoot = $temporaryRoot . '/race';
    check(mkdir($raceRoot, 0700), 'Could not create the stale-inode fixture.');
    $raceCopy = $raceRoot . '/index.php';
    check(make_fixture($source, $raceCopy), 'Could not create the stale-inode copy.');
    $raceSource = file_get_contents($raceCopy);
    $needle = <<<'PHP'
        if ($handle === false) {
            throw new RuntimeException('Cannot open the phplet for saving.');
        }

        try {
PHP;
    $instrumented = <<<'PHP'
        if ($handle === false) {
            throw new RuntimeException('Cannot open the phplet for saving.');
        }

        $testBarrier = getenv('PHPLET_TEST_OPEN_BARRIER');
        if (is_string($testBarrier) && $testBarrier !== '' && !is_file($testBarrier . '.passed')) {
            file_put_contents($testBarrier . '.opened', '1');
            $testDeadline = microtime(true) + 5;
            while (!is_file($testBarrier . '.release')) {
                if (microtime(true) >= $testDeadline) {
                    throw new RuntimeException('Test barrier timed out.');
                }
                usleep(1000);
            }
            file_put_contents($testBarrier . '.passed', '1');
        }

        try {
PHP;
    check(is_string($raceSource) && substr_count($raceSource, $needle) === 1, 'Could not locate the lock instrumentation point.');
    check(file_put_contents($raceCopy, str_replace($needle, $instrumented, $raceSource)) !== false, 'Could not instrument the stale-inode copy.');
    $barrier = $raceRoot . '/barrier';
    $workerEnvironment = getenv();
    $workerEnvironment = is_array($workerEnvironment) ? $workerEnvironment : [];
    $workerEnvironment['PHPLET_TEST_OPEN_BARRIER'] = $barrier;
    clearstatcache(true, $raceCopy);
    $oldInode = fileinode($raceCopy);
    $staleWorker = start_worker($raceCopy, 'held-save', ['title' => 'Opened before rename', 'hold' => 0], $workerEnvironment);
    for ($attempt = 0; $attempt < 200 && !is_file($barrier . '.opened'); $attempt++) usleep(5000);
    check(is_file($barrier . '.opened'), 'The stale-inode worker did not reach its barrier.');
    worker_command($raceCopy, 'held-save', ['title' => 'Replacement writer', 'hold' => 0]);
    clearstatcache(true, $raceCopy);
    check(fileinode($raceCopy) !== $oldInode, 'The replacement writer did not swap the inode.');
    touch($barrier . '.release');
    $staleResult = finish_worker($staleWorker);
    check($staleResult['ok'] === true, 'The stale-inode worker failed to retry.');
    $raceDocument = worker_command($raceCopy, 'read');
    check(isset($raceDocument['notes']['replacement-writer']), 'The retry clobbered the replacement writer.');
    check(isset($raceDocument['notes']['opened-before-rename']), 'The retried mutation was lost.');
    check($raceDocument['revision'] === 3, 'The deterministic race lost a document revision.');

    $faultRoot = $temporaryRoot . '/fault';
    check(mkdir($faultRoot, 0700) && make_fixture($source, $faultRoot . '/index.php'), 'Could not create the fault-injection fixture.');
    $faultCopy = $faultRoot . '/index.php';
    $faultSource = file_get_contents($faultCopy);
    $faultNeedle = <<<'PHP'
        $tempHandle = null;

        // Refuse to clobber an out-of-band replacement made while we prepared.
PHP;
    $faultReplacement = <<<'PHP'
        $tempHandle = null;

        if (getenv('PHPLET_TEST_FAIL_BEFORE_RENAME') === '1') {
            throw new RuntimeException('Injected failure before rename.');
        }

        // Refuse to clobber an out-of-band replacement made while we prepared.
PHP;
    check(is_string($faultSource) && substr_count($faultSource, $faultNeedle) === 1, 'Could not locate the persistence fault point.');
    check(file_put_contents($faultCopy, str_replace($faultNeedle, $faultReplacement, $faultSource)) !== false, 'Could not instrument the fault fixture.');
    $faultHash = hash_file('sha256', $faultCopy);
    $faultEnvironment = getenv();
    $faultEnvironment = is_array($faultEnvironment) ? $faultEnvironment : [];
    $faultEnvironment['PHPLET_TEST_FAIL_BEFORE_RENAME'] = '1';
    $faultFailure = finish_worker(start_worker($faultCopy, 'save', ['id' => null, 'baseRevision' => 0, 'title' => 'Faulted save', 'body' => '', 'tags' => []], $faultEnvironment), 2);
    check(str_contains($faultFailure['stderr'], 'Injected failure'), 'The post-temp persistence failure was not reached.');
    check(hash_file('sha256', $faultCopy) === $faultHash, 'A failed pre-rename commit changed the canonical file.');
    check(glob($faultRoot . '/.phplet-tmp-*.php') === [], 'A failed pre-rename commit left its temporary snapshot.');

    $modeRoot = $temporaryRoot . '/mode';
    check(mkdir($modeRoot, 0700) && make_fixture($source, $modeRoot . '/index.php'), 'Could not create the mode fixture.');
    $modeCopy = $modeRoot . '/index.php';
    check(chmod($modeCopy, 0440), 'Could not set the mode fixture permissions.');
    clearstatcache(true, $modeCopy);
    $readOnlyInode = fileinode($modeCopy);
    worker_command($modeCopy, 'save', ['id' => null, 'baseRevision' => 0, 'title' => 'Mode check', 'body' => '', 'tags' => []]);
    clearstatcache(true, $modeCopy);
    check(fileinode($modeCopy) !== $readOnlyInode && worker_command($modeCopy, 'summary')['notes'] === 2, 'A readable file in a writable directory could not be atomically replaced.');
    check((fileperms($modeCopy) & 0777) === 0440, 'Atomic replacement did not preserve read-only mode bits.');

    $hardRoot = $temporaryRoot . '/hardlink';
    check(mkdir($hardRoot, 0700) && make_fixture($source, $hardRoot . '/index.php'), 'Could not create the hard-link fixture.');
    $hardCopy = $hardRoot . '/index.php';
    check(link($hardCopy, $hardRoot . '/alias.php'), 'Could not create a hard-linked alias.');
    $hardHash = hash_file('sha256', $hardCopy);
    $hardFailure = finish_worker(start_worker($hardCopy, 'save', ['id' => null, 'baseRevision' => 0, 'title' => 'Must fail', 'body' => '', 'tags' => []]), 2);
    check(str_contains($hardFailure['stderr'], 'Hard-linked phplets'), 'A hard-linked deployment was not rejected clearly.');
    check(hash_file('sha256', $hardCopy) === $hardHash, 'Hard-link rejection changed the canonical file.');

    $largeRoot = $temporaryRoot . '/large';
    check(mkdir($largeRoot, 0700) && make_fixture($source, $largeRoot . '/index.php'), 'Could not create the large-data fixture.');
    $largeCopy = $largeRoot . '/index.php';
    $largeStart = microtime(true);
    $largePeak = 0;
    $largeNotes = [];
    for ($index = 1; $index <= 3; $index++) {
        $savedLarge = worker_command($largeCopy, 'large-save', ['title' => "Large $index", 'bytes' => 2450000]);
        $largeNotes[] = $savedLarge;
        $largePeak = max($largePeak, $savedLarge['peakBytes']);
    }
    $largeSummary = worker_command($largeCopy, 'summary');
    check($largeSummary['notes'] === 4 && $largeSummary['bytes'] > 7 * 1024 * 1024, 'The multi-megabyte snapshot did not persist across worker restarts.');
    $updatedLarge = worker_command($largeCopy, 'large-save', [
        'id' => $largeNotes[0]['id'], 'baseRevision' => $largeNotes[0]['noteRevision'], 'title' => 'Large 1', 'bytes' => 2450000, 'character' => 'y',
    ]);
    check($updatedLarge['noteRevision'] > $largeNotes[0]['noteRevision'], 'A multi-megabyte note could not be updated.');
    $beforeOversize = hash_file('sha256', $largeCopy);
    $oversize = finish_worker(start_worker($largeCopy, 'large-save', ['title' => 'Over the limit', 'bytes' => 1400000]), 2);
    check(
        str_contains($oversize['stderr'], 'PhpletHttpError: This save would make the phplet larger than 8 MiB.'),
        'An over-limit snapshot did not reach the exact file-size guard.'
    );
    check(hash_file('sha256', $largeCopy) === $beforeOversize, 'An over-limit save changed the canonical file.');
    check(glob($largeRoot . '/.phplet-tmp-*.php') === [], 'An over-limit save left a temporary snapshot.');
    $largeElapsed = microtime(true) - $largeStart;

    $savedLint = run_bounded_command([PHP_BINARY, '-l', $copy]);
    check($savedLint['status'] === 0, 'The saved phplet no longer lints.');

    // Exercise the actual HTML and JSON API against another isolated copy.
    $httpRoot = $temporaryRoot . '/http';
    check(mkdir($httpRoot, 0700), 'Could not create the HTTP fixture.');
    $httpCopy = $httpRoot . '/index.php';
    check(make_fixture($source, $httpCopy), 'Could not create the HTTP copy.');
    $browserHarness = <<<'PHP'
    <script nonce="<?= phplet_h($nonce) ?>">
    (() => {
        const browserMode = new URLSearchParams(location.search).get('__browser');
        if (!['state', 'mobile'].includes(browserMode)) return;
        const runtimeErrors = [];
        addEventListener('error', event => runtimeErrors.push(event.message || 'window error'));
        addEventListener('unhandledrejection', event => runtimeErrors.push(String(event.reason?.message || event.reason || 'unhandled rejection')));
        const result = document.createElement('output');
        result.id = 'phplet-browser-result';
        const wait = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
        const until = async (predicate, message, milliseconds = 4000) => {
            const deadline = performance.now() + milliseconds;
            while (!predicate()) {
                if (performance.now() >= deadline) throw new Error(message);
                await wait(20);
            }
        };
        const assert = (condition, message) => { if (!condition) throw new Error(message); };
        const input = (control, value) => {
            control.value = value;
            control.dispatchEvent(new Event('input', {bubbles: true}));
        };
        const button = (root, label) => {
            const found = root ? [...root.querySelectorAll('button')].find(item => item.textContent.trim() === label) : null;
            if (!found) throw new Error(`button was missing: ${label}`);
            return found;
        };
        const click = (target, label) => {
            if (!target) throw new Error(`control was missing: ${label}`);
            target.click();
        };
        const nativeFetch = window.fetch.bind(window);
        const progress = label => nativeFetch(`?__browser_progress=${encodeURIComponent(`${browserMode}:${label}`)}`).catch(() => null);
        if (browserMode === 'mobile') {
            const runMobile = async () => {
                assert(matchMedia('(max-width: 760px)').matches && innerWidth <= 760,
                    'the mobile browser scenario did not enter the mobile layout');
                const menu = document.getElementById('menu-button');
                assert(getComputedStyle(menu).display !== 'none', 'the mobile menu control was not visible');
                menu.click();
                await until(() => document.body.dataset.drawer === 'open', 'the real mobile menu action did not open the note index');
                const library = document.getElementById('library');
                assert(library.getAttribute('role') === 'dialog'
                    && library.getAttribute('aria-modal') === 'true'
                    && !library.inert
                    && document.getElementById('main').inert
                    && document.querySelector('.bar-actions').inert,
                    'the open mobile note index did not isolate its modal surface');
                await until(() => document.activeElement === document.getElementById('search-input'), 'the mobile note index did not receive initial focus');
                const controls = [...library.querySelectorAll('button:not(:disabled), input, a[href]')];
                assert(controls.length > 1, 'the mobile note index had no keyboard surface');
                controls.at(-1).focus();
                const forwardTab = new KeyboardEvent('keydown', {key: 'Tab', bubbles: true, cancelable: true});
                controls.at(-1).dispatchEvent(forwardTab);
                assert(forwardTab.defaultPrevented && document.activeElement === controls[0]
                    && document.getElementById('drawer-shade').tabIndex === -1
                    && document.getElementById('drawer-shade').getAttribute('aria-hidden') === 'true',
                    'the real mobile focus trap escaped onto its outside backdrop');
                const reverseTab = new KeyboardEvent('keydown', {key: 'Tab', shiftKey: true, bubbles: true, cancelable: true});
                controls[0].dispatchEvent(reverseTab);
                assert(reverseTab.defaultPrevented && document.activeElement === controls.at(-1),
                    'the real mobile focus trap did not wrap backward');
                document.activeElement.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true}));
                await until(() => document.body.dataset.drawer !== 'open' && document.activeElement === menu,
                    'Escape did not close the mobile note index and restore focus');
                assert(menu.getAttribute('aria-expanded') === 'false'
                    && !library.hasAttribute('role')
                    && library.inert
                    && !document.getElementById('main').inert
                    && !document.querySelector('.bar-actions').inert,
                    'closing the mobile note index did not restore page access');
                menu.click();
                await until(() => document.body.dataset.drawer === 'open',
                    'the mobile note index did not reopen for its pointer-close check');
                document.getElementById('drawer-shade').click();
                await until(() => document.body.dataset.drawer !== 'open' && document.activeElement === menu,
                    'the mobile backdrop did not close the note index and restore focus');
                if (runtimeErrors.length) throw new Error(`mobile page error: ${runtimeErrors.join('; ')}`);
            };
            runMobile().then(async () => {
                result.textContent = 'PASS'; document.body.append(result);
                await progress('result:PASS');
            }).catch(async error => {
                const message = String(error.stack || error.message).replace(/\s+/g, ' ');
                result.textContent = `FAIL: ${message}`; document.body.append(result);
                await progress(`result:FAIL: ${message}`);
            });
            return;
        }
        const run = async () => {
            const csrf = document.querySelector('meta[name="phplet-csrf"]').content;
            const api = (action, payload) => nativeFetch(`?api=${action}`, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                body: JSON.stringify(payload)
            });
            const findLibraryItem = async title => {
                input(document.getElementById('search-input'), title);
                await until(() => [...document.querySelectorAll('.library-item')].some(node => node.querySelector('.library-title')?.textContent === title), `library search did not find ${title}`);
                return [...document.querySelectorAll('.library-item')].find(node => node.querySelector('.library-title')?.textContent === title);
            };
            await progress(`start:${sessionStorage.getItem('phplet-browser-phase') || 'main'}`);
            if (sessionStorage.getItem('phplet-browser-phase') === 'read-only') {
                await until(() => document.querySelector('.plain-note[aria-label="Recovered draft text"]'), 'read-only page did not expose its browser draft');
                const expectedRecoveries = new Map([
                    ['Read-only recovery', {body: 'copy this text while the file is read-only', tag: 'first-tag'}],
                    ['Second recovery', {body: '', tag: 'tag-only-change'}]
                ]);
                const assertRecovery = () => {
                    const title = document.querySelector('.editor h2').textContent;
                    const expected = expectedRecoveries.get(title);
                    assert(expected, `read-only recovery exposed an unexpected draft: ${title}`);
                    assert(document.querySelector('.plain-note').value === expected.body, `read-only recovery lost the body for ${title}`);
                    assert(document.querySelector('.editor .note-meta')?.textContent.includes(expected.tag), `read-only recovery omitted unsaved tags for ${title}`);
                    return title;
                };
                const firstRecovery = assertRecovery();
                await progress(`read-only:first:${firstRecovery}`);
                assert(![...document.querySelectorAll('button')].some(item => item.textContent.trim() === 'Save note'), 'read-only recovery exposed a server save action');
                const exerciseFailedDismissal = title => {
                    const recoveryKey = Object.keys(sessionStorage).find(key => {
                        try { return JSON.parse(sessionStorage.getItem(key))?.title === title; }
                        catch (_) { return false; }
                    });
                    const staleKey = Object.keys(sessionStorage).find(key => key.endsWith('draft:stale-migration'));
                    const blockedKey = title === 'Read-only recovery' ? staleKey : recoveryKey;
                    assert(recoveryKey && blockedKey, `could not identify recovery storage for ${title}`);
                    const removeItem = Storage.prototype.removeItem;
                    const setItem = Storage.prototype.setItem;
                    Storage.prototype.removeItem = function (key) {
                        if (key === blockedKey) throw new DOMException('blocked', 'SecurityError');
                        return removeItem.call(this, key);
                    };
                    Storage.prototype.setItem = function (key, value) {
                        if (key === blockedKey) throw new DOMException('blocked', 'SecurityError');
                        return setItem.call(this, key, value);
                    };
                    try {
                        button(document.querySelector('.editor'), 'Dismiss recovery').click();
                        assert(sessionStorage.getItem(recoveryKey) !== null, `failed dismissal erased the authoritative recovery for ${title}`);
                        assert(document.querySelector('.plain-note'), 'failed read-only dismissal hid the only recovery copy');
                        assert(document.querySelector('.save-status').textContent.includes('could not clear'), 'failed read-only dismissal was not reported');
                    } finally {
                        Storage.prototype.removeItem = removeItem;
                        Storage.prototype.setItem = setItem;
                    }
                };
                exerciseFailedDismissal(firstRecovery);
                await progress('read-only:storage-restored');
                button(document.querySelector('.editor'), 'Dismiss recovery').click();
                await progress('read-only:first-dismissed');
                await until(() => document.querySelector('.editor h2')?.textContent !== firstRecovery, 'read-only recovery did not advance to the next draft');
                await progress('read-only:advanced');
                const secondRecovery = assertRecovery();
                await progress(`read-only:second:${secondRecovery}`);
                assert(secondRecovery !== firstRecovery, 'read-only recovery repeated the same draft');
                await until(() => document.querySelector('.editor')?.contains(document.activeElement), 'the next recovery did not receive keyboard focus');
                exerciseFailedDismissal(secondRecovery);
                button(document.querySelector('.editor'), 'Dismiss recovery').click();
                await until(() => !document.querySelector('.plain-note[aria-label="Recovered draft text"]'), 'read-only recoveries did not finish after explicit dismissal');
                assert(!Object.keys(sessionStorage).some(key => key.includes('draft:stale-migration')), 'read-only dismissal left a linked stale recovery copy');
                assert(sessionStorage.getItem('phplet-browser-foreign') === 'keep me', 'a recovery link cleared unrelated session storage');
                const malformedKey = Object.keys(sessionStorage).find(key => key.endsWith('draft:bad-json'));
                assert(malformedKey && sessionStorage.getItem(malformedKey) === '{', 'a malformed recovery hid valid drafts or was deleted');
                sessionStorage.removeItem(malformedKey);
                sessionStorage.removeItem('phplet-browser-foreign');
                await until(() => document.activeElement === document.getElementById('main'), 'focus was not restored after the final recovery');
                sessionStorage.removeItem('phplet-browser-phase');
                await progress('read-only:done');
                return;
            }
            if (sessionStorage.getItem('phplet-browser-phase') === 'orphan') {
                await progress('orphan:entered');
                await until(() => document.querySelector('.conflict-panel'), 'deleted-note draft was not recovered after reload');
                assert(document.getElementById('edit-body').value === 'orphaned after remote delete',
                    `orphan recovery lost the draft text (opened ${document.getElementById('edit-title').value})`);
                sessionStorage.removeItem('phplet-browser-phase');
                const currentOrphanKey = Object.keys(sessionStorage).find(key => key.endsWith('draft:welcome'));
                const staleOrphanKey = Object.keys(sessionStorage).find(key => key.endsWith('draft:older-welcome'));
                assert(currentOrphanKey && staleOrphanKey, 'the chained writable-orphan fixture was incomplete');
                const orphanRemoveItem = Storage.prototype.removeItem;
                const orphanSetItem = Storage.prototype.setItem;
                Storage.prototype.removeItem = function (key) {
                    if (key === staleOrphanKey) throw new DOMException('blocked', 'SecurityError');
                    return orphanRemoveItem.call(this, key);
                };
                Storage.prototype.setItem = function (key, value) {
                    if (key === staleOrphanKey) throw new DOMException('blocked', 'SecurityError');
                    return orphanSetItem.call(this, key, value);
                };
                button(document.querySelector('.conflict-panel'), 'Discard draft').click();
                assert(sessionStorage.getItem(currentOrphanKey) !== null, 'failed writable-orphan cleanup deleted the authoritative recovery first');
                assert(document.querySelector('.editor'), 'failed writable-orphan cleanup closed its only current copy');
                assert(document.querySelector('.save-status').textContent.includes('could not discard'), 'failed writable-orphan cleanup was not reported');
                Storage.prototype.removeItem = orphanRemoveItem;
                Storage.prototype.setItem = orphanSetItem;
                button(document.querySelector('.conflict-panel'), 'Discard draft').click();
                await until(() => !document.querySelector('.editor'), 'orphan draft did not close after explicit discard');
                assert(!Object.keys(sessionStorage).some(key => key.endsWith('draft:welcome') || key.endsWith('draft:older-welcome')), 'discard left a chained orphan recovery key behind');
                await until(() => document.activeElement === document.getElementById('new-button'), 'discard did not restore keyboard focus');
                document.getElementById('new-button').click();
                await until(() => document.querySelector('.editor'), 'read-only recovery draft did not open');
                input(document.getElementById('edit-title'), 'Read-only recovery');
                input(document.getElementById('edit-body'), 'copy this text while the file is read-only');
                input(document.getElementById('edit-tags'), 'first-tag');
                window.dispatchEvent(new Event('pagehide'));
                const newDraftKey = Object.keys(sessionStorage).find(key => key.endsWith('draft:@new'));
                assert(newDraftKey, 'first read-only recovery draft was not stored');
                const draftPrefix = newDraftKey.slice(0, -4);
                const staleMigrationKey = draftPrefix + 'stale-migration';
                const secondStaleMigrationKey = draftPrefix + 'stale-migration-2';
                sessionStorage.setItem(staleMigrationKey, JSON.stringify({
                    id: 'stale-migration', baseRevision: 1, title: 'Stale migration copy', body: 'older text', tags: ['stale'],
                    previousDraftKeys: [secondStaleMigrationKey]
                }));
                sessionStorage.setItem(secondStaleMigrationKey, JSON.stringify({
                    id: 'stale-migration-2', baseRevision: 1, title: 'Second stale migration copy', body: 'oldest text', tags: ['stale']
                }));
                const currentRecovery = JSON.parse(sessionStorage.getItem(newDraftKey));
                currentRecovery.previousDraftKey = staleMigrationKey;
                sessionStorage.setItem(newDraftKey, JSON.stringify(currentRecovery));
                sessionStorage.setItem(draftPrefix + 'bad-json', '{');
                sessionStorage.setItem('phplet-browser-foreign', 'keep me');
                sessionStorage.setItem(draftPrefix + 'story-cap-0', JSON.stringify({
                    id: 'story-cap-0', baseRevision: 1, title: 'Second recovery', body: '', tags: ['tag-only-change'],
                    previousDraftKey: 'phplet-browser-foreign'
                }));
                const madeReadOnly = await nativeFetch('?__browser_readonly=1');
                assert(madeReadOnly.ok, 'test fixture could not become read-only');
                assert(runtimeErrors.length === 0, `page error before read-only reload: ${runtimeErrors.join('; ')}`);
                sessionStorage.setItem('phplet-browser-phase', 'read-only');
                await progress('orphan:reload-read-only');
                const reloadSetItem = Storage.prototype.setItem;
                Storage.prototype.setItem = function (key, value) {
                    if (key === newDraftKey) return;
                    return reloadSetItem.call(this, key, value);
                };
                location.reload();
                await new Promise(() => {});
            }
            if (sessionStorage.getItem('phplet-browser-phase') === 'superseded-orphan') {
                await progress('superseded-orphan:entered');
                await until(() => document.querySelector('.editor'), 'writable startup did not open its pending recovery migration');
                assert(document.getElementById('edit-title').value === 'Authoritative recovery'
                    && document.getElementById('edit-body').value === 'latest text',
                    'writable startup selected the superseded orphan instead of its authoritative draft');
                const staleKey = Object.keys(sessionStorage).find(key => key.endsWith('draft:synthetic-orphan'));
                const oldestKey = Object.keys(sessionStorage).find(key => key.endsWith('draft:synthetic-oldest'));
                const authoritativeKey = Object.keys(sessionStorage).find(key => key.endsWith('draft:@new'));
                assert(staleKey && oldestKey && authoritativeKey, 'superseded-orphan fixture did not survive reload');
                button(document.querySelector('.editor-actions'), 'Cancel').click();
                await until(() => !document.querySelector('.editor'), 'pending recovery migration did not close after explicit discard');
                assert(!sessionStorage.getItem(staleKey) && !sessionStorage.getItem(oldestKey) && !sessionStorage.getItem(authoritativeKey), 'pending recovery migration did not clear its linked copies');

                const draftPrefix = authoritativeKey.slice(0, -4);
                const componentOwnerKey = `${draftPrefix}story-cap-0`;
                const componentPredecessorKey = `${draftPrefix}story-cap-1`;
                sessionStorage.setItem(componentPredecessorKey, JSON.stringify({
                    id: 'story-cap-1', baseRevision: 0, title: 'Stale component', body: 'older component text', tags: ['stale']
                }));
                sessionStorage.setItem(componentOwnerKey, JSON.stringify({
                    id: 'story-cap-0', baseRevision: 0, title: 'Authoritative component', body: 'latest component text', tags: ['current'],
                    previousDraftKeys: [componentPredecessorKey]
                }));
                const predecessorItem = await findLibraryItem('Story cap 1');
                predecessorItem.click();
                await until(() => document.getElementById('phplet-note-story-cap-1'), 'the predecessor note did not open');
                click(document.querySelector('#phplet-note-story-cap-1 button[title="Edit note"]'), 'edit a reserved predecessor key');
                await until(() => document.querySelector('#phplet-note-story-cap-0.editor'), 'Edit did not prioritize the pending recovery owner');
                assert(document.getElementById('edit-title').value === 'Authoritative component', 'Edit opened stale predecessor text instead of its owner');
                button(document.querySelector('.conflict-panel'), 'Use saved version').click();
                await until(() => !document.querySelector('.editor'), 'the pending edit owner did not close');
                assert(!sessionStorage.getItem(componentOwnerKey) && !sessionStorage.getItem(componentPredecessorKey), 'the pending edit component was not cleared');

                const danglingOwnerKey = `${draftPrefix}story-cap-0`;
                sessionStorage.setItem(danglingOwnerKey, JSON.stringify({
                    id: 'story-cap-0', baseRevision: 0, title: 'Dangling-link owner', body: 'current recovery text', tags: ['recovery'],
                    previousDraftKeys: [`${draftPrefix}already-removed`]
                }));
                document.getElementById('new-button').click();
                await until(() => document.querySelector('#phplet-note-story-cap-0.editor'), 'a dangling recovery link did not reopen its owner');
                assert(document.getElementById('edit-title').value === 'Dangling-link owner', 'a dangling recovery link allowed its fixed key to be reused');
                button(document.querySelector('.conflict-panel'), 'Use saved version').click();
                await until(() => !document.querySelector('.editor'), 'dangling-link owner did not close after choosing the saved version');
                assert(!sessionStorage.getItem(danglingOwnerKey), 'dangling-link owner recovery was not cleared');

                const welcomeItem = await findLibraryItem('A quieter web');
                assert(welcomeItem, 'welcome note was missing before story-cap recovery');
                welcomeItem.click();
                click(document.querySelector('#phplet-note-welcome button[title="Edit note"]'), 'story-cap welcome edit');
                await until(() => document.querySelector('#phplet-note-welcome.editor'), 'story-cap editor did not open');
                input(document.getElementById('edit-body'), 'orphaned after remote delete');
                for (let index = 0; index < 25; index++) {
                    const title = `Story cap ${index}`;
                    const item = await findLibraryItem(title);
                    assert(item, `story-cap note ${index} was missing from the library`);
                    item.click();
                }
                assert(document.querySelectorAll('#story > article').length <= 20, 'the story rendered more than 20 open notes');
                assert(document.querySelector('#phplet-note-welcome.editor') && document.getElementById('edit-body').value === 'orphaned after remote delete', 'the story cap evicted the live editor');
                const openKey = Object.keys(sessionStorage).find(key => key.endsWith(':open'));
                assert(openKey && JSON.parse(sessionStorage.getItem(openKey)).length <= 20, 'the persisted open-note set exceeded its cap');
                window.dispatchEvent(new Event('pagehide'));
                const welcomeDraftKey = Object.keys(sessionStorage).find(key => key.endsWith('draft:welcome'));
                assert(welcomeDraftKey, 'the current orphan-recovery draft was not stored');
                const olderWelcomeKey = welcomeDraftKey.slice(0, -7) + 'older-welcome';
                sessionStorage.setItem(olderWelcomeKey, JSON.stringify({
                    id: 'older-welcome', baseRevision: 1, title: 'Older welcome recovery', body: 'older orphan text', tags: ['stale']
                }));
                const welcomeDraft = JSON.parse(sessionStorage.getItem(welcomeDraftKey));
                welcomeDraft.previousDraftKeys = [olderWelcomeKey];
                sessionStorage.setItem(welcomeDraftKey, JSON.stringify(welcomeDraft));
                const snapshot = await nativeFetch('?download=1').then(response => response.text());
                const marker = '\nPIPLET-DATA/1\n';
                const markerAt = snapshot.lastIndexOf(marker);
                assert(markerAt >= 0, 'downloaded snapshot data marker was missing');
                const snapshotDocument = JSON.parse(snapshot.slice(markerAt + marker.length).trim());
                const removed = await api('delete', {id: 'welcome', baseRevision: snapshotDocument.notes.welcome.revision});
                assert(removed.ok, 'remote delete for orphan recovery failed');
                assert(runtimeErrors.length === 0, `page error before orphan reload: ${runtimeErrors.join('; ')}`);
                sessionStorage.setItem('phplet-browser-phase', 'orphan');
                await progress('superseded-orphan:reload-orphan');
                const orphanReloadSetItem = Storage.prototype.setItem;
                Storage.prototype.setItem = function (key, value) {
                    if (key === welcomeDraftKey) return;
                    return orphanReloadSetItem.call(this, key, value);
                };
                location.reload();
                await new Promise(() => {});
            }
            if (sessionStorage.getItem('phplet-browser-phase') === 'story-cap') {
                await progress('story-cap:entered');
                assert(document.querySelectorAll('.library-item').length === 40, 'the library DOM exceeded its 40-note window');
                assert(document.querySelector('.library-empty')?.textContent.includes('Refine the search'), 'the capped library did not explain how to find older notes');
                const openKey = Object.keys(sessionStorage).find(key => key.endsWith(':open'));
                assert(openKey, 'the story-cap open-note state was missing');
                const scope = openKey.slice(0, -4);
                const oldestKey = `${scope}draft:synthetic-oldest`;
                const staleKey = `${scope}draft:synthetic-orphan`;
                const authoritativeKey = `${scope}draft:@new`;
                sessionStorage.setItem(oldestKey, JSON.stringify({
                    id: 'synthetic-oldest', baseRevision: 1, title: 'Oldest orphan', body: 'oldest text', tags: ['stale']
                }));
                sessionStorage.setItem(staleKey, JSON.stringify({
                    id: 'synthetic-orphan', baseRevision: 1, title: 'Superseded orphan', body: 'stale text', tags: ['stale'],
                    previousDraftKeys: [oldestKey]
                }));
                sessionStorage.setItem(authoritativeKey, JSON.stringify({
                    id: null, baseRevision: 0, createToken: 'ffffffffffffffffffffffffffffffff',
                    title: 'Authoritative recovery', body: 'latest text', tags: ['current'],
                    previousDraftKeys: [staleKey]
                }));
                sessionStorage.setItem('phplet-browser-phase', 'superseded-orphan');
                await progress('story-cap:reload-superseded-orphan');
                location.reload();
                await new Promise(() => {});
            }

            const hostileItem = await findLibraryItem('HTTP note');
            await progress('main:entered');
            assert(hostileItem, 'hostile-content note was missing from the browser fixture');
            hostileItem.click();
            const hostileNote = document.getElementById('phplet-note-http-note');
            assert(hostileNote?.querySelector('.prose h3')?.textContent === 'Safe heading', 'note headings did not preserve the document outline');
            assert(hostileNote?.querySelector('.prose h4')?.textContent === 'Subheading' && hostileNote?.querySelector('.prose h5')?.textContent === 'Detail', 'nested note headings lost their semantic levels');
            const headingSizes = ['h3', 'h4', 'h5'].map(selector => parseFloat(getComputedStyle(hostileNote.querySelector(selector)).fontSize));
            assert(headingSizes[0] > headingSizes[1] && headingSizes[1] > headingSizes[2]
                && ['h3', 'h4', 'h5'].every(selector => getComputedStyle(hostileNote.querySelector(selector)).marginBottom === '0px'),
                'nested note heading styles regressed');
            const hostileMarkup = '<im' + 'g src=x onerror=alert(1)>';
            assert(!hostileNote.querySelector('img') && hostileNote.textContent.includes(hostileMarkup), 'stored HTML became executable DOM');

            const mobileDrawer = matchMedia('(max-width: 760px)').matches;
            if (mobileDrawer) document.getElementById('menu-button').click();
            else document.body.dataset.drawer = 'open';
            const drawerControls = [...document.getElementById('library').querySelectorAll('button:not(:disabled), input, a[href]')];
            assert(drawerControls.length > 1, 'the mobile note index had no keyboard surface');
            drawerControls.at(-1).focus();
            drawerControls.at(-1).dispatchEvent(new KeyboardEvent('keydown', {key: 'Tab', bubbles: true, cancelable: true}));
            assert(document.activeElement === drawerControls[0]
                && document.getElementById('drawer-shade').tabIndex === -1
                && document.getElementById('drawer-shade').getAttribute('aria-hidden') === 'true',
                'the mobile modal drawer moved keyboard focus onto its outside backdrop');
            if (mobileDrawer) document.getElementById('drawer-shade').click();
            else document.body.dataset.drawer = '';

            let appearanceCalls = 0;
            window.fetch = (resource, options) => {
                if (String(resource).includes('api=appearance')) appearanceCalls++;
                return nativeFetch(resource, options);
            };
            const beforeTheme = await nativeFetch('?download=1').then(response => response.text());
            document.getElementById('appearance-button').click();
            const appearanceDialog = document.getElementById('appearance-dialog');
            await until(() => appearanceDialog.open, 'appearance did not open');
            const dark = document.querySelector('input[name="appearance-theme"][value="dark"]');
            dark.checked = true;
            dark.dispatchEvent(new Event('input', {bubbles: true}));
            document.getElementById('appearance-form').requestSubmit();
            await until(() => !appearanceDialog.open, 'theme-only save did not finish');
            const afterTheme = await nativeFetch('?download=1').then(response => response.text());
            assert(beforeTheme === afterTheme, 'theme-only save rewrote the phplet');
            assert(appearanceCalls === 0, 'theme-only save called the shared appearance API');

            document.getElementById('appearance-button').click();
            await until(() => appearanceDialog.open, 'appearance did not open for storage failure');
            const light = document.querySelector('input[name="appearance-theme"][value="light"]');
            light.checked = true;
            light.dispatchEvent(new Event('input', {bubbles: true}));
            const originalAppearanceSetItem = Storage.prototype.setItem;
            Storage.prototype.setItem = function (key, value) {
                if (String(key).endsWith(':theme')) throw new DOMException('blocked', 'QuotaExceededError');
                return originalAppearanceSetItem.call(this, key, value);
            };
            document.getElementById('appearance-form').requestSubmit();
            await until(() => document.getElementById('appearance-status').textContent.includes('could not store'), 'theme storage failure was not reported');
            assert(appearanceDialog.open, 'theme storage failure falsely completed the save');
            Storage.prototype.setItem = originalAppearanceSetItem;
            document.getElementById('appearance-cancel').click();
            await until(() => !appearanceDialog.open, 'appearance cancel did not close after storage failure');
            assert(appearanceCalls === 0, 'failed device-only theme save called the shared appearance API');
            window.fetch = nativeFetch;

            document.getElementById('appearance-button').click();
            await until(() => appearanceDialog.open, 'appearance did not reopen');
            document.querySelector('.appearance-custom').open = true;
            const tokenEditor = document.getElementById('appearance-tokens');
            input(tokenEditor, '--radius: 10px; display:none;');
            assert(document.getElementById('appearance-status').dataset.kind === 'error', 'invalid token syntax was not rejected in preview');
            assert(!document.getElementById('phplet-token-style').textContent.includes('display:none'), 'invalid token syntax reached the stylesheet');
            const plum = document.querySelector('input[name="appearance-palette"][value="plum"]');
            plum.checked = true;
            plum.dispatchEvent(new Event('input', {bubbles: true}));
            assert(document.documentElement.dataset.palette === 'plum', 'an invalid token blocked an unrelated palette preview');
            input(tokenEditor, '--story-width: 60rem;\n--radius: 10px;');
            assert(document.getElementById('phplet-token-style').textContent.includes('--story-width:60rem;'), 'design-token preview was not applied');
            assert(getComputedStyle(document.documentElement).getPropertyValue('--story-width').trim() === '60rem', 'the design-token layer lost computed precedence');
            document.getElementById('appearance-form').requestSubmit();
            await until(() => !appearanceDialog.open, 'design-token save did not finish');

            let saveCalls = 0;
            let releaseSave = null;
            window.fetch = (resource, options) => {
                if (String(resource).includes('api=save')) {
                    saveCalls++;
                    if (saveCalls === 1) {
                        return new Promise((resolve, reject) => {
                            releaseSave = () => nativeFetch(resource, options).then(resolve, reject);
                        });
                    }
                }
                return nativeFetch(resource, options);
            };
            document.getElementById('new-button').click();
            await until(() => document.querySelector('.editor'), 'new-note editor did not open');
            assert(document.querySelector('.editor-preview > h2.preview-label')?.textContent === 'Live preview',
                'the editor preview did not preserve a level-two heading above rendered note headings');
            input(document.getElementById('edit-title'), 'One browser save');
            input(document.getElementById('edit-body'), 'typed before the request');
            const firstForm = document.querySelector('.editor form');
            firstForm.requestSubmit();
            firstForm.requestSubmit();
            document.dispatchEvent(new KeyboardEvent('keydown', {key: 's', ctrlKey: true, bubbles: true}));
            await until(() => releaseSave !== null, 'save was not intercepted');
            assert(saveCalls === 1, 'duplicate submissions escaped the in-flight guard');
            assert(document.getElementById('edit-body').disabled, 'editor remained writable during save');
            document.getElementById('new-button').click();
            assert(document.getElementById('edit-title').value === 'One browser save', 'navigation replaced the in-flight editor');
            releaseSave();
            await until(() => [...document.querySelectorAll('.note-title')].some(node => node.textContent === 'One browser save'), 'saved note did not render');
            assert(saveCalls === 1, 'one user save created multiple requests');
            assert(document.getElementById('file-size').isConnected && document.getElementById('file-size').textContent === 'Saved',
                'the save confirmation was missing or used implementation jargon');
            window.fetch = nativeFetch;

            document.getElementById('new-button').click();
            await until(() => document.getElementById('phplet-composer'), 'the slug-collision note composer did not open');
            input(document.getElementById('edit-title'), 'new');
            input(document.getElementById('edit-body'), 'saved body for the real new slug');
            document.querySelector('.editor form').requestSubmit();
            await until(() => document.getElementById('phplet-note-new') && !document.querySelector('.editor'), 'a note whose slug is new did not save');
            assert(document.querySelectorAll('#phplet-note-new').length === 1 && document.querySelectorAll('#note-count').length === 1,
                'a saved note collided with a fixed page element ID');
            click(document.querySelector('#phplet-note-new button[title="Edit note"]'), 'edit the real new-slug note');
            await until(() => document.querySelector('#phplet-note-new.editor'), 'the real new-slug editor did not open');
            input(document.getElementById('edit-body'), 'unsaved body for the real new slug');
            document.getElementById('new-button').click();
            await until(() => document.getElementById('phplet-composer'), 'the distinct null-ID composer did not open');
            const realNewDraftKey = Object.keys(sessionStorage).find(key => key.endsWith('draft:new'));
            assert(realNewDraftKey && JSON.parse(sessionStorage.getItem(realNewDraftKey))?.id === 'new'
                && JSON.parse(sessionStorage.getItem(realNewDraftKey))?.body === 'unsaved body for the real new slug',
                'the real new-slug recovery draft was lost or mistaken for a composer');
            input(document.getElementById('edit-title'), 'Separate composer');
            input(document.getElementById('edit-body'), 'separate null-ID draft');
            const composerDraftKey = realNewDraftKey.replace(/draft:new$/, 'draft:@new');
            await until(() => {
                try { return JSON.parse(sessionStorage.getItem(composerDraftKey))?.body === 'separate null-ID draft'; }
                catch (_) { return false; }
            }, 'the null-ID composer did not use its separate recovery key');
            assert(JSON.parse(sessionStorage.getItem(realNewDraftKey))?.body === 'unsaved body for the real new slug'
                && document.querySelectorAll('#phplet-note-new, #phplet-composer').length === 2,
                'the composer overwrote the real new-slug draft or duplicated its DOM ID');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'the separate composer did not cancel');
            click(document.querySelector('#phplet-note-new button[title="Edit note"]'), 'reopen the real new-slug note');
            await until(() => document.querySelector('#phplet-note-new.editor'), 'the real new-slug recovery did not reopen');
            assert(document.getElementById('edit-body').value === 'unsaved body for the real new slug', 'the real new-slug draft did not round-trip');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'the real new-slug recovery did not discard');

            sessionStorage.setItem(realNewDraftKey, JSON.stringify({
                id: null, baseRevision: 0, createToken: 'abababababababababababababababab',
                title: 'Legacy composer', body: 'legacy null-ID body', tags: []
            }));
            click(document.querySelector('#phplet-note-new button[title="Edit note"]'), 'edit while a legacy null-ID draft owns draft:new');
            await until(() => document.getElementById('phplet-composer'), 'editor entry did not prioritize the legacy null-ID draft');
            assert(document.getElementById('edit-title').value === 'Legacy composer'
                && document.getElementById('edit-body').value === 'legacy null-ID body',
                'the old draft:new composer format was not read compatibly');
            input(document.getElementById('edit-body'), 'migrated null-ID body');
            await until(() => {
                try { return JSON.parse(sessionStorage.getItem(composerDraftKey))?.body === 'migrated null-ID body'; }
                catch (_) { return false; }
            }, 'the legacy composer was not migrated to draft:@new');
            assert(sessionStorage.getItem(realNewDraftKey) === null, 'legacy draft:new remained after migration');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'the migrated composer did not cancel');

            sessionStorage.setItem(composerDraftKey, JSON.stringify({
                id: null, baseRevision: 0, createToken: 'cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd',
                title: 'Canonical composer', body: 'keep this first', tags: []
            }));
            sessionStorage.setItem(realNewDraftKey, JSON.stringify({
                id: null, baseRevision: 0, createToken: 'efefefefefefefefefefefefefefefef',
                title: 'Independent legacy composer', body: 'keep this second', tags: []
            }));
            click(document.querySelector('#phplet-note-new button[title="Edit note"]'), 'edit while two composer recoveries coexist');
            await until(() => document.getElementById('phplet-composer'), 'coexisting composer recovery did not open');
            assert(document.getElementById('edit-title').value === 'Canonical composer', 'legacy migration overwrote the independent canonical composer');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'canonical composer recovery did not resolve');
            assert(sessionStorage.getItem(realNewDraftKey) !== null, 'resolving the canonical composer deleted its independent legacy peer');
            click(document.querySelector('#phplet-note-new button[title="Edit note"]'), 'edit after resolving the canonical composer');
            await until(() => document.getElementById('phplet-composer'), 'the second composer recovery did not open');
            assert(document.getElementById('edit-title').value === 'Independent legacy composer', 'the independent legacy composer did not drain second');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'the independent legacy composer did not resolve');
            click(document.querySelector('#phplet-note-new button[title="Edit note"]'), 'edit the real new slug after draining composer recoveries');
            await until(() => document.querySelector('#phplet-note-new.editor'), 'composer recovery keys still blocked the real new-slug note');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'the real new-slug editor did not close after recovery drainage');

            sessionStorage.setItem(composerDraftKey, JSON.stringify({
                id: null, baseRevision: 0, createToken: '12121212121212121212121212121212',
                title: 'Cycle owner', body: 'recover this cycle head', tags: [], previousDraftKeys: [realNewDraftKey]
            }));
            sessionStorage.setItem(realNewDraftKey, JSON.stringify({
                id: 'new', baseRevision: 1, title: 'Cycle predecessor', body: 'older cycle text', tags: [],
                previousDraftKeys: [composerDraftKey]
            }));
            click(document.querySelector('#phplet-note-new button[title="Edit note"]'), 'edit while recovery metadata contains a cycle');
            await until(() => document.getElementById('phplet-composer'), 'cyclic recovery metadata hid every writable draft');
            assert(document.getElementById('edit-title').value === 'Cycle owner', 'cycle recovery did not prefer the canonical composer');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'cyclic recovery did not resolve');
            assert(!sessionStorage.getItem(composerDraftKey) && !sessionStorage.getItem(realNewDraftKey), 'cyclic recovery cleanup left a hidden component');

            const cycleAKey = composerDraftKey.replace(/@new$/, 'missing-cycle-a');
            const cycleBKey = composerDraftKey.replace(/@new$/, 'missing-cycle-b');
            sessionStorage.setItem(composerDraftKey, JSON.stringify({
                id: null, baseRevision: 0, createToken: '34343434343434343434343434343434',
                title: 'Independent before orphan cycle', body: 'do not overwrite this composer', tags: []
            }));
            sessionStorage.setItem(cycleAKey, JSON.stringify({
                id: 'missing-cycle-a', baseRevision: 2, title: 'Disjoint cycle owner', body: 'newer cyclic recovery', tags: [],
                previousDraftKeys: [cycleBKey]
            }));
            sessionStorage.setItem(cycleBKey, JSON.stringify({
                id: 'missing-cycle-b', baseRevision: 1, title: 'Disjoint cycle predecessor', body: 'older cyclic recovery', tags: [],
                previousDraftKeys: [cycleAKey]
            }));
            click(document.querySelector('#phplet-note-new button[title="Edit note"]'), 'edit while an orphan cycle competes with a composer');
            await until(() => document.getElementById('phplet-composer'), 'the independent composer did not open ahead of the orphan cycle');
            assert(document.getElementById('edit-title').value === 'Independent before orphan cycle'
                && JSON.parse(sessionStorage.getItem(cycleAKey))?.body === 'newer cyclic recovery',
                'an orphan recovery cycle overwrote the independent composer destination');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'the independent cycle-blocking composer did not resolve');
            click(document.querySelector('#phplet-note-new button[title="Edit note"]'), 'edit after resolving the independent cycle-blocking composer');
            await until(() => document.querySelector('.conflict-panel')?.textContent.includes('deleted elsewhere'),
                'the disjoint orphan cycle did not drain after its destination resolved');
            assert(['Disjoint cycle owner', 'Disjoint cycle predecessor'].includes(document.getElementById('edit-title').value),
                'the disjoint cycle recovery lost its recoverable component');
            button(document.querySelector('.conflict-panel'), 'Discard draft').click();
            await until(() => !document.querySelector('.editor'), 'the disjoint cycle recovery did not discard');
            assert(!sessionStorage.getItem(cycleAKey) && !sessionStorage.getItem(cycleBKey),
                'discarding the disjoint recovery cycle left a hidden component');

            document.getElementById('new-button').click();
            await until(() => document.getElementById('phplet-composer'), 'the fixed-ID collision composer did not open');
            input(document.getElementById('edit-title'), 'count');
            input(document.getElementById('edit-body'), 'saved without colliding with note-count');
            document.querySelector('.editor form').requestSubmit();
            await until(() => document.getElementById('phplet-note-count') && !document.querySelector('.editor'), 'a note whose slug is count did not save');
            assert(document.querySelectorAll('#note-count').length === 1
                && document.getElementById('note-count').classList.contains('note-count'),
                'a note slug collided with the fixed note-count element');

            let loseCreateResponse = true;
            window.fetch = (resource, options) => {
                if (loseCreateResponse && String(resource).includes('api=save')) {
                    loseCreateResponse = false;
                    return nativeFetch(resource, options).then(() => { throw new TypeError('simulated lost response'); });
                }
                return nativeFetch(resource, options);
            };
            document.getElementById('new-button').click();
            await until(() => document.querySelector('.editor'), 'lost-response editor did not open');
            input(document.getElementById('edit-title'), 'Lost response create');
            input(document.getElementById('edit-body'), 'committed body');
            document.querySelector('.editor form').requestSubmit();
            await until(() => document.querySelector('.save-status')?.textContent.includes('Could not reach'), 'lost create response was not reported');
            input(document.getElementById('edit-body'), 'changed after lost response');
            window.fetch = nativeFetch;
            const lostSnapshot = await nativeFetch('?download=1').then(response => response.text());
            const lostMarker = '\nPIPLET-DATA/1\n';
            const lostDocument = JSON.parse(lostSnapshot.slice(lostSnapshot.lastIndexOf(lostMarker) + lostMarker.length).trim());
            const lostCurrent = Object.values(lostDocument.notes).find(note => note.title === 'Lost response create');
            const oldCreateKey = Object.keys(sessionStorage).find(key => key.endsWith('draft:@new'));
            assert(lostCurrent && oldCreateKey, 'the lost-create collision fixture was incomplete');
            const occupiedCreateKey = `${oldCreateKey.slice(0, -4)}${lostCurrent.id}`;
            sessionStorage.setItem(occupiedCreateKey, JSON.stringify({
                id: lostCurrent.id, baseRevision: lostCurrent.revision,
                title: 'Independent destination draft', body: 'do not overwrite this draft', tags: ['independent']
            }));
            document.querySelector('.editor form').requestSubmit();
            await until(() => document.getElementById('edit-title')?.value === 'Independent destination draft',
                'create-conflict migration did not open its occupied destination first');
            assert(JSON.parse(sessionStorage.getItem(oldCreateKey))?.body === 'changed after lost response',
                'create-conflict migration overwrote the source composer');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'occupied create destination did not resolve');
            document.getElementById('new-button').click();
            await until(() => document.getElementById('edit-body')?.value === 'changed after lost response',
                'the preserved lost-response composer did not reopen');
            const createConflictSetItem = Storage.prototype.setItem;
            Storage.prototype.setItem = function (key, value) {
                if (String(key).includes('draft:')) throw new DOMException('blocked', 'QuotaExceededError');
                return createConflictSetItem.call(this, key, value);
            };
            document.querySelector('.editor form').requestSubmit();
            await until(() => document.querySelector('.conflict-panel'), 'changed lost-response retry did not show its conflict');
            assert(document.querySelector('.editor') && document.getElementById('edit-body').value === 'changed after lost response', 'create conflict disappeared or lost its draft');
            assert(document.querySelector('.save-status').textContent.includes('could not store'), 'create conflict storage failure was mislabeled as recovered');
            await until(() => document.querySelector('.conflict-panel')?.contains(document.activeElement), 'create conflict did not receive keyboard focus');
            Storage.prototype.setItem = createConflictSetItem;

            assert(oldCreateKey, 'the failed create migration lost its original recovery key');
            const createConflictRemoveItem = Storage.prototype.removeItem;
            Storage.prototype.removeItem = function (key) {
                if (key === oldCreateKey) throw new DOMException('blocked', 'SecurityError');
                return createConflictRemoveItem.call(this, key);
            };
            Storage.prototype.setItem = function (key, value) {
                if (key === oldCreateKey && value === 'null') throw new DOMException('blocked', 'SecurityError');
                return createConflictSetItem.call(this, key, value);
            };
            let migrationRetryCalls = 0;
            window.fetch = (resource, options) => {
                if (String(resource).includes('api=save')) migrationRetryCalls++;
                return nativeFetch(resource, options);
            };
            document.querySelector('.editor form').requestSubmit();
            await until(() => migrationRetryCalls === 1
                && !document.querySelector('.editor button[type="submit"]').disabled
                && document.querySelector('.save-status').textContent.includes('older recovery copy'),
                'selective conflict cleanup failure was not reported');
            const authoritativeCreateKey = Object.keys(sessionStorage).find(key => {
                if (key === oldCreateKey) return false;
                try { return JSON.parse(sessionStorage.getItem(key))?.title === 'Lost response create'; }
                catch (_) { return false; }
            });
            const authoritativeCreate = JSON.parse(sessionStorage.getItem(authoritativeCreateKey));
            assert(authoritativeCreate?.previousDraftKeys?.includes(oldCreateKey), 'conflict migration did not link its authoritative recovery before cleanup');
            Storage.prototype.removeItem = createConflictRemoveItem;
            Storage.prototype.setItem = createConflictSetItem;
            window.fetch = nativeFetch;
            const blockedLatestSetItem = Storage.prototype.setItem;
            Storage.prototype.setItem = function (key, value) {
                if (String(key).includes('draft:')) throw new DOMException('blocked', 'QuotaExceededError');
                return blockedLatestSetItem.call(this, key, value);
            };
            input(document.getElementById('edit-body'), 'changed while latest recovery writes fail');
            await until(() => document.querySelector('.save-status').textContent.includes('could not store the latest draft'),
                'a latest-write failure kept the stale safe-recovery message');
            assert(JSON.parse(sessionStorage.getItem(authoritativeCreateKey)).body !== 'changed while latest recovery writes fail',
                'the latest-write failure test did not leave storage behind the live editor');
            Storage.prototype.setItem = blockedLatestSetItem;
            window.dispatchEvent(new Event('pagehide'));
            assert(!Object.keys(sessionStorage).some(key => key.endsWith('draft:@new')), 'an immediate flush after storage recovery did not clean the old draft key');
            input(document.getElementById('edit-body'), 'changed after storage recovered');
            await until(() => {
                try {
                    return JSON.parse(sessionStorage.getItem(authoritativeCreateKey))?.body === 'changed after storage recovered'
                        && document.querySelector('.save-status').textContent === 'Changes stay in this browser until you save.';
                } catch (_) { return false; }
            }, 'successful browser recovery did not clear the prior latest-write warning');
            document.querySelector('.editor form').requestSubmit();
            await until(() => {
                const panel = document.querySelector('.conflict-panel');
                return panel && !panel.querySelector('button').disabled
                    && !document.querySelector('.save-status').textContent.includes('could not store');
            }, 'create conflict recovery did not retry');
            assert(!Object.keys(sessionStorage).some(key => key.endsWith('draft:@new')), 'successful conflict migration left the original new-note draft behind');
            button(document.querySelector('.conflict-panel'), 'Use saved version').click();
            await until(() => !document.querySelector('.editor'), 'using the committed create did not close its draft');
            document.getElementById('new-button').click();
            await until(() => document.querySelector('.editor'), 'fresh editor did not open after conflict cleanup');
            assert(document.getElementById('edit-title').value === '' && document.getElementById('edit-body').value === '', 'stale create conflict text resurfaced in a new draft');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'fresh editor did not cancel');

            const welcomeForConflict = await findLibraryItem('A quieter web');
            assert(welcomeForConflict, 'welcome was missing from the conflict library');
            welcomeForConflict.click();
            await until(() => document.getElementById('phplet-note-welcome'), 'welcome did not open for conflict testing');
            click(document.querySelector('#phplet-note-welcome button[title="Edit note"]'), 'conflict welcome edit');
            await until(() => document.querySelector('#phplet-note-welcome.editor'), 'welcome editor did not open');
            input(document.getElementById('edit-body'), 'my conflicted draft');
            const remote = await api('save', {id: 'welcome', baseRevision: 1, title: 'A quieter web', body: 'saved in another tab', tags: ['welcome']});
            assert(remote.ok, 'competing save failed');
            const conflictSetItem = Storage.prototype.setItem;
            Storage.prototype.setItem = function (key, value) {
                if (String(key).includes('draft:')) throw new DOMException('blocked', 'QuotaExceededError');
                return conflictSetItem.call(this, key, value);
            };
            document.querySelector('.editor form').requestSubmit();
            await until(() => document.querySelector('.conflict-panel'), 'stale save did not show conflict choices');
            assert(document.getElementById('edit-body').value === 'my conflicted draft', '409 discarded the local draft');
            assert(document.querySelector('.save-status').textContent.includes('could not store'), '409 storage failure was mislabeled as safely recovered');
            await until(() => document.querySelector('.conflict-panel')?.contains(document.activeElement), 'saved-version conflict did not receive keyboard focus');
            Storage.prototype.setItem = conflictSetItem;
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'conflict cancel did not close the editor');
            click(document.querySelector('#phplet-note-welcome button[title="Edit note"]'), 'reopen conflicted welcome');
            await until(() => document.querySelector('.conflict-panel'), 'kept conflict draft was not recovered');
            assert(document.getElementById('edit-body').value === 'my conflicted draft', 'reopened conflict lost local text');
            button(document.querySelector('.conflict-panel'), 'Use saved version').click();
            await until(() => !document.querySelector('.editor'), 'using the saved version did not close the draft');
            assert(document.querySelector('#phplet-note-welcome .prose').textContent.includes('saved in another tab'), 'saved version was not restored');

            click(document.querySelector('#phplet-note-welcome button[title="Edit note"]'), 'pristine welcome edit');
            await until(() => document.querySelector('#phplet-note-welcome.editor'), 'pristine editor did not open');
            window.dispatchEvent(new Event('pagehide'));
            assert(!Object.keys(sessionStorage).some(key => key.endsWith('draft:welcome')), 'pagehide created a recovery draft for an untouched editor');
            document.getElementById('new-button').click();
            await until(() => document.getElementById('phplet-composer'), 'switching away from an untouched editor failed');
            assert(!Object.keys(sessionStorage).some(key => key.endsWith('draft:welcome')), 'switching notes created a false recovery draft');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'untouched new editor did not cancel');

            click(document.querySelector('#phplet-note-welcome button[title="Edit note"]'), 'ordinary cancel welcome edit');
            await until(() => document.querySelector('#phplet-note-welcome.editor'), 'ordinary-cancel editor did not open');
            input(document.getElementById('edit-body'), 'ordinary draft to discard');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'ordinary cancel did not close the editor');
            assert(!document.getElementById('global-status').textContent.includes('Draft kept'), 'ordinary cancel falsely claimed to keep its draft');
            click(document.querySelector('#phplet-note-welcome button[title="Edit note"]'), 'ordinary cancel welcome reopen');
            await until(() => document.querySelector('#phplet-note-welcome.editor'), 'ordinary-cancel note did not reopen');
            assert(document.getElementById('edit-body').value === 'saved in another tab', 'ordinary cancel retained discarded text');
            input(document.getElementById('edit-body'), 'draft whose removeItem fails');
            const originalRemoveItem = Storage.prototype.removeItem;
            Storage.prototype.removeItem = function (key) {
                if (String(key).includes('draft:')) throw new DOMException('blocked', 'SecurityError');
                return originalRemoveItem.call(this, key);
            };
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'second ordinary cancel did not close');
            Storage.prototype.removeItem = originalRemoveItem;
            click(document.querySelector('#phplet-note-welcome button[title="Edit note"]'), 'removeItem welcome reopen');
            await until(() => document.querySelector('#phplet-note-welcome.editor'), 'removeItem-failure note did not reopen');
            assert(document.getElementById('edit-body').value === 'saved in another tab', 'a failed removeItem resurrected discarded text');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'removeItem-failure editor did not close again');

            click(document.querySelector('#phplet-note-welcome button[title="Edit note"]'), 'storage-failure welcome edit');
            await until(() => document.querySelector('#phplet-note-welcome.editor'), 'storage-failure editor did not open');
            const originalSetItem = Storage.prototype.setItem;
            Storage.prototype.setItem = function (key, value) {
                if (String(key).includes('draft:')) throw new DOMException('blocked', 'QuotaExceededError');
                return originalSetItem.call(this, key, value);
            };
            input(document.getElementById('edit-body'), 'must stay visible');
            document.getElementById('new-button').click();
            assert(document.getElementById('edit-body')?.value === 'must stay visible', 'storage failure allowed an editor switch');
            assert(document.querySelector('.save-status').dataset.kind === 'error', 'storage failure was not reported');
            Storage.prototype.setItem = originalSetItem;

            input(document.getElementById('edit-body'), 'flushed on pagehide');
            window.dispatchEvent(new Event('pagehide'));
            const draftStorageKey = Object.keys(sessionStorage).find(key => key.endsWith('draft:welcome'));
            assert(draftStorageKey && JSON.parse(sessionStorage.getItem(draftStorageKey)).body === 'flushed on pagehide', 'pagehide did not synchronously flush the draft');

            input(document.getElementById('edit-body'), 'x'.repeat(513 * 1024));
            document.getElementById('new-button').click();
            assert(document.getElementById('edit-body')?.value.length === 513 * 1024, 'oversized draft was lost on editor switch');
            assert(JSON.parse(sessionStorage.getItem(draftStorageKey)).body === 'flushed on pagehide', 'an oversized draft erased the last recoverable copy');
            const unmatchedStarted = performance.now();
            input(document.getElementById('edit-body'), '['.repeat(100000));
            await until(() => document.querySelector('.editor-preview .prose')?.textContent.length === 100000, 'unmatched inline markup did not render promptly');
            assert(performance.now() - unmatchedStarted < 2500, 'unmatched inline markup blocked rendering for too long');
            await until(() => document.querySelector('.save-status').dataset.kind !== 'error', 'a smaller draft did not clear the stale recovery warning');
            const structured = Array.from({length: 2101}, (_, index) => `line ${index}`).join('\n');
            input(document.getElementById('edit-body'), structured);
            await until(() => document.querySelector('.editor-preview .render-notice'), 'node-heavy preview was not bounded');
            document.querySelector('.editor form').requestSubmit();
            await until(() => !document.querySelector('.editor'), 'bounded note save did not finish');
            const welcome = document.getElementById('phplet-note-welcome');
            assert(welcome.querySelector('.render-notice'), 'stored node-heavy note was not bounded');
            assert(welcome.querySelector('.plain-note').value === structured, 'bounded renderer did not retain complete text');
            assert(welcome.querySelectorAll('*').length < 250, 'bounded renderer created too many DOM nodes');

            document.getElementById('new-button').click();
            await until(() => document.getElementById('phplet-composer'), 'the remote-delete collision composer did not open');
            input(document.getElementById('edit-title'), 'Independent composer before delete');
            input(document.getElementById('edit-body'), 'keep this independent composer');
            const deletedConflictItem = await findLibraryItem('One browser save');
            deletedConflictItem.click();
            await until(() => [...document.querySelectorAll('.note-title')].some(node => node.textContent === 'One browser save'), 'deleted-conflict note did not open');
            const deletedConflictArticle = [...document.querySelectorAll('.note')].find(node => node.querySelector('.note-title')?.textContent === 'One browser save');
            click(deletedConflictArticle?.querySelector('button[title="Edit note"]'), 'deleted-conflict edit');
            input(document.getElementById('edit-body'), 'keep after remote deletion');
            const independentComposerKey = Object.keys(sessionStorage).find(key => key.endsWith('draft:@new'));
            assert(independentComposerKey && JSON.parse(sessionStorage.getItem(independentComposerKey))?.body === 'keep this independent composer',
                'switching to the delete-conflict note did not preserve its independent composer');
            const conflictSnapshot = await nativeFetch('?download=1').then(response => response.text());
            const conflictMarker = '\nPIPLET-DATA/1\n';
            const conflictAt = conflictSnapshot.lastIndexOf(conflictMarker);
            assert(conflictAt >= 0, 'deleted-conflict snapshot marker was missing');
            const conflictDocument = JSON.parse(conflictSnapshot.slice(conflictAt + conflictMarker.length).trim());
            const deletedConflictNote = Object.values(conflictDocument.notes).find(note => note.title === 'One browser save');
            assert(deletedConflictNote, 'deleted-conflict snapshot note was missing');
            assert((await api('delete', {id: deletedConflictNote.id, baseRevision: deletedConflictNote.revision})).ok, 'deleted-conflict remote delete failed');
            document.querySelector('.editor form').requestSubmit();
            await until(() => document.querySelector('.conflict-panel')?.textContent.includes('deleted elsewhere'), 'deleted-note save did not show its conflict');
            const deletedOpenKey = Object.keys(sessionStorage).find(key => key.endsWith(':open'));
            assert(!JSON.parse(sessionStorage.getItem(deletedOpenKey) || '[]').includes(deletedConflictNote.id), 'deleted-note conflict left a dead ID in the open-note state');
            assert(location.hash !== `#${encodeURIComponent(deletedConflictNote.id)}`, 'deleted-note conflict left a dead URL hash');
            assert(JSON.parse(sessionStorage.getItem(independentComposerKey))?.body === 'keep this independent composer',
                'deleted-note conflict overwrote the independent composer destination');
            const deletedSourceKey = `${independentComposerKey.slice(0, -4)}${deletedConflictNote.id}`;
            assert(JSON.parse(sessionStorage.getItem(deletedSourceKey))?.body === 'keep after remote deletion',
                'deleted-note conflict did not remain recoverable at its source key');
            button(document.querySelector('.conflict-panel'), 'Save as new').click();
            await until(() => document.getElementById('edit-body')?.value === 'keep this independent composer',
                'deleted-note conversion did not open the occupied composer first');
            assert(JSON.parse(sessionStorage.getItem(deletedSourceKey))?.body === 'keep after remote deletion',
                'opening the occupied composer erased the deleted-note draft');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'independent composer did not resolve before deleted-note recovery');
            document.getElementById('new-button').click();
            await until(() => document.querySelector('.conflict-panel')?.textContent.includes('deleted elsewhere'),
                'deleted-note source draft did not reopen after the composer resolved');
            assert(document.getElementById('edit-body').value === 'keep after remote deletion', 'deleted-note source draft lost its text');
            button(document.querySelector('.conflict-panel'), 'Discard draft').click();
            await until(() => !document.querySelector('.editor'), 'deleted-note conflict did not discard');

            assert(runtimeErrors.length === 0, `page error before story-cap reload: ${runtimeErrors.join('; ')}`);
            sessionStorage.setItem('phplet-browser-phase', 'story-cap');
            await progress('main:reload-story-cap');
            location.reload();
            await new Promise(() => {});
        };
        run().then(async () => {
            if (runtimeErrors.length) throw new Error(`page error: ${runtimeErrors.join('; ')}`);
            result.textContent = 'PASS'; document.body.append(result);
            await progress('result:PASS');
        })
            .catch(async error => {
                const message = String(error.stack || error.message).replace(/\s+/g, ' ');
                result.textContent = `FAIL: ${message}`; document.body.append(result);
                await progress(`result:FAIL: ${message}`);
            });
    })();
    </script>
PHP;
    $browserNeedle = "    </script>\n</body>";
    $httpSource = file_get_contents($httpCopy);
    check(is_string($httpSource) && substr_count($httpSource, $browserNeedle) === 1, 'Could not locate the browser harness insertion point.');
    $testPrelude = <<<'PHP'
if (($_GET['__browser_readonly'] ?? null) === '1') {
    if (!chmod(__DIR__, 0555)) { http_response_code(500); }
    exit;
}
if (isset($_GET['__browser_progress'])) {
    file_put_contents(__DIR__ . '/browser-progress.log', (string) $_GET['__browser_progress'] . "\n", FILE_APPEND);
    exit;
}
PHP;
    $declareNeedle = "declare(strict_types=1);\n";
    check(substr_count($httpSource, $declareNeedle) === 1, 'Could not locate the browser fixture prelude point.');
    $httpSource = str_replace($declareNeedle, $declareNeedle . $testPrelude . "\n", $httpSource);
    check(file_put_contents($httpCopy, str_replace($browserNeedle, "    </script>\n$browserHarness</body>", $httpSource)) !== false, 'Could not instrument the browser fixture.');
    $browserSignal = $httpRoot . '/browser-progress.log';
    check(file_put_contents($browserSignal, '') === 0, 'Could not initialize the browser completion signal.');
    $port = free_port();
    $httpEnvironment = getenv();
    $httpEnvironment = is_array($httpEnvironment) ? $httpEnvironment : [];
    unset($httpEnvironment['PHPLET_PASSWORD']);
    $httpEnvironment['PHPLET_ALLOW_PASSWORDLESS'] = '1';
    $serverPipes = [];
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $httpRoot], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $serverPipes, $httpRoot, $httpEnvironment);
    check(is_resource($server), 'Could not start the PHP test server.');
    fclose($serverPipes[0]);
    try {
        wait_for_server($port);
        [$getStatus, $getHeaders, $page] = http_request("http://127.0.0.1:$port/");
        check($getStatus === 200, 'The app page did not return 200.');
        $csp = (string) header_value($getHeaders, 'Content-Security-Policy');
        check(
            str_contains($csp, "default-src 'none'")
                && preg_match("/script-src 'nonce-[^']+'/", $csp) === 1
                && preg_match("/style-src 'nonce-[^']+'/", $csp) === 1
                && str_contains($csp, "connect-src 'self'")
                && str_contains($csp, 'img-src data:')
                && str_contains($csp, "base-uri 'none'")
                && str_contains($csp, "form-action 'self'")
                && str_contains($csp, "frame-ancestors 'none'")
                && !str_contains($csp, "'unsafe-inline'"),
            'The restrictive CSP contract changed.'
        );
        check(preg_match('/name="phplet-csrf" content="([a-f0-9]{64})"/', $page, $tokenMatch) === 1, 'The CSRF token is missing.');
        $setCookie = header_value($getHeaders, 'Set-Cookie');
        check($setCookie !== null && preg_match('/^([^=]+)=([^;]+)/', $setCookie, $cookieMatch) === 1, 'The CSRF cookie is missing.');
        $token = $tokenMatch[1];
        $cookie = $cookieMatch[1] . '=' . $cookieMatch[2];
        $payload = json_encode(['id' => null, 'baseRevision' => 0, 'createToken' => str_repeat('d', 32), 'title' => 'HTTP note', 'body' => "# Safe heading\n\n## Subheading\n\n### Detail\n\n<img src=x onerror=alert(1)>", 'tags' => ['web']], JSON_THROW_ON_ERROR);
        $httpAppearanceValues = [
            'palette' => 'ocean',
            'font' => 'modern',
            'scale' => 'large',
            'measure' => 'wide',
            'tokens' => ['--story-width' => '60rem', '--radius' => '10px'],
        ];
        $appearancePayload = json_encode(['baseRevision' => 0, 'appearance' => $httpAppearanceValues], JSON_THROW_ON_ERROR);

        [$rebindStatus] = http_request("http://127.0.0.1:$port/", 'GET', ['Host: notes.attacker.example']);
        check($rebindStatus === 403, 'Password-free local mode accepted an untrusted Host header.');
        $rebindHash = hash_file('sha256', $httpCopy);
        [$rebindMutationStatus] = http_request("http://127.0.0.1:$port/?api=save", 'POST', ['Host: notes.attacker.example', "Cookie: $cookie", 'Content-Type: application/json', "X-CSRF-Token: $token"], $payload);
        check($rebindMutationStatus === 403 && hash_file('sha256', $httpCopy) === $rebindHash, 'An untrusted Host reached a mutation endpoint.');
        [$methodStatus, $methodHeaders] = http_request("http://127.0.0.1:$port/?api=save");
        check($methodStatus === 405 && header_value($methodHeaders, 'Allow') === 'POST', 'The API did not reject GET with Allow: POST.');
        [$appearanceMethodStatus, $appearanceMethodHeaders] = http_request("http://127.0.0.1:$port/?api=appearance");
        check($appearanceMethodStatus === 405 && header_value($appearanceMethodHeaders, 'Allow') === 'POST', 'The appearance API did not reject GET with Allow: POST.');

        [$missingStatus] = http_request("http://127.0.0.1:$port/?api=save", 'POST', ['Content-Type: application/json'], $payload);
        check($missingStatus === 403, 'A mutation without CSRF protection was accepted.');
        [$appearanceMissingStatus] = http_request("http://127.0.0.1:$port/?api=appearance", 'POST', ['Content-Type: application/json'], $appearancePayload);
        check($appearanceMissingStatus === 403, 'An appearance mutation without CSRF protection was accepted.');

        [$typeStatus] = http_request("http://127.0.0.1:$port/?api=save", 'POST', ["Cookie: $cookie", 'Content-Type: text/plain', "X-CSRF-Token: $token"], '{}');
        check($typeStatus === 415, 'The API accepted a non-JSON content type.');
        [$malformedStatus] = http_request("http://127.0.0.1:$port/?api=save", 'POST', ["Cookie: $cookie", 'Content-Type: application/json', "X-CSRF-Token: $token"], '{');
        check($malformedStatus === 400, 'The API accepted malformed JSON.');
        [$listRootStatus] = http_request("http://127.0.0.1:$port/?api=save", 'POST', ["Cookie: $cookie", 'Content-Type: application/json', "X-CSRF-Token: $token"], '[]');
        check($listRootStatus === 400, 'The API accepted a top-level JSON list as an object.');
        $objectTags = json_encode(['id' => null, 'baseRevision' => 0, 'title' => 'Bad tags', 'body' => '', 'tags' => ['name' => 'not-a-list']], JSON_THROW_ON_ERROR);
        [$tagStatus] = http_request("http://127.0.0.1:$port/?api=save", 'POST', ["Cookie: $cookie", 'Content-Type: application/json', "X-CSRF-Token: $token"], $objectTags);
        check($tagStatus === 422, 'The API accepted associative tags.');
        $numericObjectTags = '{"id":null,"baseRevision":0,"title":"Bad numeric tags","body":"","tags":{"0":"not-a-list"}}';
        [$numericTagStatus] = http_request("http://127.0.0.1:$port/?api=save", 'POST', ["Cookie: $cookie", 'Content-Type: application/json', "X-CSRF-Token: $token"], $numericObjectTags);
        check($numericTagStatus === 422, 'The API confused a numeric-key JSON object with a tag list.');

        [$appearanceStatus, , $appearanceBody] = http_request("http://127.0.0.1:$port/?api=appearance", 'POST', ["Cookie: $cookie", 'Content-Type: application/json', "X-CSRF-Token: $token"], $appearancePayload);
        $appearanceResponse = json_decode($appearanceBody, true, 16, JSON_THROW_ON_ERROR);
        $expectedHttpAppearance = ['revision' => 2] + $httpAppearanceValues;
        check($appearanceStatus === 200 && $appearanceResponse['result'] === $expectedHttpAppearance && $appearanceResponse['documentRevision'] === 2, 'A valid HTTP appearance save failed.');

        [$appearanceGetStatus, , $appearancePage] = http_request("http://127.0.0.1:$port/", 'GET', ["Cookie: $cookie"]);
        check($appearanceGetStatus === 200, 'The app failed after an HTTP appearance save.');
        check(preg_match('/<html\b[^>]*>/i', $appearancePage, $appearanceHtmlMatch) === 1, 'The saved page is missing its html element.');
        $appearanceHtml = $appearanceHtmlMatch[0];
        foreach (array_diff_key($httpAppearanceValues, ['tokens' => true]) as $name => $value) {
            check(str_contains($appearanceHtml, 'data-' . $name . '="' . $value . '"'), "The saved $name appearance was not rendered on reload.");
        }
        check(str_contains($appearancePage, '--story-width:60rem;') && str_contains($appearancePage, '--radius:10px;'), 'Saved design tokens were not rendered in the override module.');
        $appearanceHash = hash_file('sha256', $httpCopy);
        [$appearanceStaleStatus, , $appearanceStaleBody] = http_request("http://127.0.0.1:$port/?api=appearance", 'POST', ["Cookie: $cookie", 'Content-Type: application/json', "X-CSRF-Token: $token"], $appearancePayload);
        $appearanceStaleResponse = json_decode($appearanceStaleBody, true, 16, JSON_THROW_ON_ERROR);
        check($appearanceStaleStatus === 409 && $appearanceStaleResponse['current'] === $expectedHttpAppearance, 'The appearance API did not return the current record for a stale save.');
        check(hash_file('sha256', $httpCopy) === $appearanceHash, 'A stale HTTP appearance save changed the file.');

        [$postStatus, , $postBody] = http_request("http://127.0.0.1:$port/?api=save", 'POST', ["Cookie: $cookie", 'Content-Type: application/json', "X-CSRF-Token: $token"], $payload);
        check($postStatus === 200, 'A valid HTTP save failed.');
        $post = json_decode($postBody, true, 16, JSON_THROW_ON_ERROR);
        check($post['result']['title'] === 'HTTP note', 'The HTTP response returned the wrong note.');
        $postHash = hash_file('sha256', $httpCopy);
        clearstatcache(true, $httpCopy);
        $postInode = fileinode($httpCopy);
        [$repeatStatus, , $repeatBody] = http_request("http://127.0.0.1:$port/?api=save", 'POST', ["Cookie: $cookie", 'Content-Type: application/json', "X-CSRF-Token: $token"], $payload);
        $repeat = json_decode($repeatBody, true, 16, JSON_THROW_ON_ERROR);
        clearstatcache(true, $httpCopy);
        check($repeatStatus === 200 && $repeat['result']['id'] === $post['result']['id'], 'A lost-response create retry produced another HTTP note.');
        check(hash_file('sha256', $httpCopy) === $postHash && fileinode($httpCopy) === $postInode, 'A lost-response create retry rewrote the HTTP snapshot.');

        [$secondGetStatus, , $secondPage] = http_request("http://127.0.0.1:$port/", 'GET', ["Cookie: $cookie"]);
        check($secondGetStatus === 200, 'The app failed after an HTTP save.');
        check(!str_contains($secondPage, '<img src=x onerror=alert(1)>'), 'Stored markup was embedded as live HTML.');
        check(str_contains($secondPage, '\\u003Cimg src=x onerror=alert(1)\\u003E'), 'Stored markup was not safely represented in boot data.');

        [$downloadStatus, $downloadHeaders, $downloadBody] = http_request("http://127.0.0.1:$port/?download=1", 'GET', ["Cookie: $cookie"]);
        check($downloadStatus === 200 && str_starts_with($downloadBody, '<?php'), 'Snapshot download failed.');
        check((int) header_value($downloadHeaders, 'Content-Length') === strlen($downloadBody), 'Snapshot Content-Length did not match its inode.');
        check($downloadBody === file_get_contents($httpCopy), 'The downloaded snapshot was not an exact restorable copy.');
        $downloadCopy = $httpRoot . '/downloaded.php';
        check(file_put_contents($downloadCopy, $downloadBody) === strlen($downloadBody), 'Could not materialize the downloaded snapshot test.');
        $downloadLint = run_bounded_command([PHP_BINARY, '-l', $downloadCopy]);
        check($downloadLint['status'] === 0 && worker_command($downloadCopy, 'summary')['notes'] === 2, 'The downloaded snapshot was not runnable and decodable.');

        $seeded = worker_command($httpCopy, 'seed-notes', ['count' => 41]);
        check($seeded['result']['notes'] === 43, 'Could not seed the bounded library/story browser fixture.');

        $chrome = chrome_binary();
        if ($chrome !== null) {
            try {
                try {
                    $browserResult = run_browser_scenario(
                        $chrome,
                        "http://127.0.0.1:$port/?__browser=state",
                        $temporaryRoot . '/chrome-profile',
                        $browserSignal,
                        'state'
                    );
                } catch (Throwable $error) {
                    $progress = @file_get_contents($httpRoot . '/browser-progress.log') ?: 'no progress';
                    throw new RuntimeException($error->getMessage() . "\nProgress:\n$progress", 0, $error);
                }
            } finally {
                @chmod($httpRoot, 0700);
            }
            $browserProgress = @file_get_contents($httpRoot . '/browser-progress.log') ?: 'no progress';
            check($browserResult === 'PASS', "Browser regression failed: $browserResult\nProgress:\n$browserProgress");
            check(file_put_contents($browserSignal, '') === 0, 'Could not reset the mobile browser completion signal.');
            try {
                $mobileResult = run_browser_scenario(
                    $chrome,
                    "http://127.0.0.1:$port/?__browser=mobile",
                    $temporaryRoot . '/chrome-mobile-profile',
                    $browserSignal,
                    'mobile',
                    '720,900'
                );
            } catch (Throwable $error) {
                $progress = @file_get_contents($httpRoot . '/browser-progress.log') ?: 'no progress';
                throw new RuntimeException($error->getMessage() . "\nMobile progress:\n$progress", 0, $error);
            }
            $mobileProgress = @file_get_contents($httpRoot . '/browser-progress.log') ?: 'no progress';
            check($mobileResult === 'PASS', "Mobile browser regression failed: $mobileResult\nProgress:\n$mobileProgress");
        } else {
            check(str_contains($httpSource, 'if (noteSaving || editing !== editor) return;'), 'The browser save-flight guard is missing.');
            check(str_contains($httpSource, 'return renderPlainBody(body, preview);'), 'The bounded-renderer fallback is missing.');
            check(str_contains($httpSource, 'recoverReadOnlyDraft'), 'The read-only draft recovery guard is missing.');
            check(str_contains($httpSource, 'const maxOpenNotes = 20;') && str_contains($httpSource, 'const maxLibraryNotes = 40;'), 'The aggregate rendering guards are missing.');
            check(str_contains($httpSource, "id === null ? '@new' : id") && str_contains($httpSource, "draft.id !== null && draft.id !== 'new'"), 'The null-ID draft namespace or legacy migration guard is missing.');
            check(str_contains($httpSource, 'article.id = editor.id === null ? \'phplet-composer\' : `phplet-note-${editor.id}`;'), 'Saved notes and the composer no longer have separate DOM namespaces.');
            check(str_contains($httpSource, "els['drawer-shade'].tabIndex = -1;") && !str_contains($httpSource, ", els['drawer-shade']];"), 'The modal drawer focus guard includes its outside backdrop.');
            fwrite(STDOUT, "skip — Chrome unavailable; dynamic browser regressions were not run\n");
        }

        $deletePayload = json_encode(['id' => $post['result']['id'], 'baseRevision' => $post['result']['revision']], JSON_THROW_ON_ERROR);
        [$deleteStatus] = http_request("http://127.0.0.1:$port/?api=delete", 'POST', ["Cookie: $cookie", 'Content-Type: application/json', "X-CSRF-Token: $token"], $deletePayload);
        check($deleteStatus === 200, 'A current HTTP delete failed.');
        $hashAfterDelete = hash_file('sha256', $httpCopy);
        $stalePayload = json_encode(['id' => $post['result']['id'], 'baseRevision' => $post['result']['revision'], 'title' => 'Stale after delete', 'body' => 'draft', 'tags' => []], JSON_THROW_ON_ERROR);
        [$staleStatus, , $staleBody] = http_request("http://127.0.0.1:$port/?api=save", 'POST', ["Cookie: $cookie", 'Content-Type: application/json', "X-CSRF-Token: $token"], $stalePayload);
        $staleResponse = json_decode($staleBody, true, 16, JSON_THROW_ON_ERROR);
        check($staleStatus === 409 && array_key_exists('current', $staleResponse) && $staleResponse['current'] === null, 'A stale edit after deletion did not return 409/current:null.');
        check(hash_file('sha256', $httpCopy) === $hashAfterDelete, 'A stale edit after deletion changed the file.');
    } finally {
        @chmod($httpRoot, 0700);
        $stopped = terminate_process($server, 1.0);
        if ($stopped) proc_close($server);
        else throw new RuntimeException('The HTTP test server could not be terminated.');
    }

    $closedRoot = $temporaryRoot . '/closed-local';
    check(mkdir($closedRoot, 0700) && make_fixture($source, $closedRoot . '/index.php'), 'Could not create the deny-by-default fixture.');
    $closedPort = free_port();
    $closedEnvironment = getenv();
    $closedEnvironment = is_array($closedEnvironment) ? $closedEnvironment : [];
    unset($closedEnvironment['PHPLET_PASSWORD'], $closedEnvironment['PHPLET_ALLOW_PASSWORDLESS']);
    $closedPipes = [];
    $closedServer = proc_open([PHP_BINARY, '-S', "127.0.0.1:$closedPort", '-t', $closedRoot], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $closedPipes, $closedRoot, $closedEnvironment);
    check(is_resource($closedServer), 'Could not start the deny-by-default server.');
    fclose($closedPipes[0]);
    try {
        wait_for_server($closedPort);
        [$closedStatus] = http_request("http://127.0.0.1:$closedPort/", 'GET', ['Host: localhost', 'X-Forwarded-For: 127.0.0.1']);
        check($closedStatus === 403, 'Passwordless HTTP was enabled without an explicit local-development opt-in.');
    } finally {
        $stopped = terminate_process($closedServer, 1.0);
        if ($stopped) proc_close($closedServer);
        else throw new RuntimeException('The deny-by-default test server could not be terminated.');
    }

    $authRoot = $temporaryRoot . '/auth';
    check(mkdir($authRoot, 0700) && make_fixture($source, $authRoot . '/index.php'), 'Could not create the authentication fixture.');
    $authPort = free_port();
    $authEnvironment = getenv();
    $authEnvironment = is_array($authEnvironment) ? $authEnvironment : [];
    unset($authEnvironment['PHPLET_ALLOW_PASSWORDLESS']);
    $authEnvironment['PHPLET_PASSWORD'] = 'correct horse battery staple';
    $authPipes = [];
    $authServer = proc_open([PHP_BINARY, '-S', "127.0.0.1:$authPort", '-t', $authRoot], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $authPipes, $authRoot, $authEnvironment);
    check(is_resource($authServer), 'Could not start the authenticated test server.');
    fclose($authPipes[0]);
    try {
        wait_for_server($authPort);
        $authHash = hash_file('sha256', $authRoot . '/index.php');
        [$anonymousStatus, $anonymousHeaders] = http_request("http://127.0.0.1:$authPort/");
        check($anonymousStatus === 401 && header_value($anonymousHeaders, 'WWW-Authenticate') !== null, 'Password mode did not challenge an anonymous request.');
        [$anonymousDownloadStatus] = http_request("http://127.0.0.1:$authPort/?download=1");
        [$anonymousApiStatus] = http_request("http://127.0.0.1:$authPort/?api=save", 'POST', ['Content-Type: application/json'], '{}');
        check($anonymousDownloadStatus === 401 && $anonymousApiStatus === 401, 'Authentication did not protect download and mutation routes.');
        [$wrongStatus] = http_request("http://127.0.0.1:$authPort/", 'GET', ['Authorization: Basic ' . base64_encode('writer:wrong')]);
        check($wrongStatus === 401, 'Password mode accepted a wrong password.');
        [$wrongApiStatus] = http_request("http://127.0.0.1:$authPort/?api=delete", 'POST', ['Authorization: Basic ' . base64_encode('writer:wrong'), 'Content-Type: application/json'], '{}');
        check($wrongApiStatus === 401 && hash_file('sha256', $authRoot . '/index.php') === $authHash, 'Wrong-password API access changed the protected phplet.');
        [$correctStatus] = http_request("http://127.0.0.1:$authPort/", 'GET', ['Authorization: Basic ' . base64_encode('writer:correct horse battery staple')]);
        check($correctStatus === 200, 'Password mode rejected the configured password.');
        [$lowercaseStatus] = http_request("http://127.0.0.1:$authPort/", 'GET', ['Authorization: basic ' . base64_encode('writer:correct horse battery staple')]);
        check($lowercaseStatus === 200, 'The fallback parser treated the Basic authentication scheme as case-sensitive.');
        [$spacedStatus] = http_request("http://127.0.0.1:$authPort/", 'GET', ['Authorization: Basic  ' . base64_encode('writer:correct horse battery staple')]);
        check($spacedStatus === 200, 'The fallback parser rejected valid repeated authentication whitespace.');
    } finally {
        $stopped = terminate_process($authServer, 1.0);
        if ($stopped) proc_close($authServer);
        else throw new RuntimeException('The authenticated test server could not be terminated.');
    }

    check(hash_file('sha256', $source) === $sourceHashBefore, 'The test runner changed the source phplet.');
    $successMessage = sprintf("ok — %d assertions; source file untouched; 7+ MiB cycle %.2fs (worker peak %.1f MiB)\n", $assertions, $largeElapsed, $largePeak / 1024 / 1024);
} catch (Throwable $error) {
    fwrite(STDERR, "not ok — {$error->getMessage()}\n");
    $exitStatus = 1;
} finally {
    if (!stop_live_workers()) {
        fwrite(STDERR, "not ok — a worker process could not be terminated\n");
        $exitStatus = 1;
    }
    if ($temporaryRootOwned) {
        $currentRoot = @lstat($temporaryRoot);
        $sameRoot = $currentRoot === false || (
            is_array($temporaryRootIdentity)
            && $currentRoot['dev'] === $temporaryRootIdentity['dev']
            && $currentRoot['ino'] === $temporaryRootIdentity['ino']
            && (((int) $currentRoot['mode']) & 0170000) === 0040000
        );
        if ($sameRoot && $currentRoot !== false) remove_tree($temporaryRoot);
        if (!$sameRoot || @lstat($temporaryRoot) !== false) {
            fwrite(STDERR, "not ok — owned test fixtures could not be safely removed\n");
            $exitStatus = 1;
        }
    }
    if ($sourceHashBefore !== false && hash_file('sha256', $source) !== $sourceHashBefore) {
        fwrite(STDERR, "not ok — source phplet changed during tests\n");
        $exitStatus = 1;
    }
}
if ($exitStatus === 0 && is_string($successMessage)) fwrite(STDOUT, $successMessage);
exit($exitStatus);
