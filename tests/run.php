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

function check(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
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
            'prefix' => hash('sha256', substr(file_get_contents(phplet_path()), 0, phplet_code_offset())),
            'held-save' => worker_held_save($input),
            'large-save' => worker_large_save($input),
            'summary' => worker_summary(),
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
    $worker = [$process, $pipes];
    $liveWorkers[get_resource_id($process)] = $worker;
    return $worker;
}

function finish_worker(array $worker, int $expectedStatus = 0): array
{
    global $liveWorkers;
    [$process, $pipes] = $worker;
    $resourceId = get_resource_id($process);
    $deadline = microtime(true) + 10;
    $lastStatus = null;
    do {
        $lastStatus = proc_get_status($process);
        if (!$lastStatus['running']) break;
        usleep(10000);
    } while (microtime(true) < $deadline);

    if ($lastStatus['running']) {
        proc_terminate($process, 9);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($process);
        unset($liveWorkers[$resourceId]);
        throw new RuntimeException('A worker exceeded its 10 second deadline.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
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

function header_value(array $headers, string $name): ?string
{
    foreach ($headers as $header) {
        if (str_starts_with(strtolower($header), strtolower($name) . ':')) {
            return trim(substr($header, strlen($name) + 1));
        }
    }
    return null;
}

function remove_tree(string $directory): void
{
    if (!is_dir($directory)) return;
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
        if ($item->isDir() && !$item->isLink()) remove_tree($item->getPathname());
        else @unlink($item->getPathname());
    }
    @rmdir($directory);
}

function stop_live_workers(): void
{
    global $liveWorkers;
    foreach ($liveWorkers as [$process, $pipes]) {
        @proc_terminate($process, 9);
        foreach ($pipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
        @proc_close($process);
    }
    $liveWorkers = [];
}

$exitStatus = 0;
try {
    check(is_file($source), 'phplet.php is missing.');
    check(mkdir($temporaryRoot, 0700), 'Could not create the test directory.');
    check(copy($source, $copy), 'Could not make an isolated test copy.');

    $lintOutput = [];
    $lintStatus = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($copy), $lintOutput, $lintStatus);
    check($lintStatus === 0, 'The initial phplet does not lint.');

    $prefixBefore = worker_command($copy, 'prefix');
    $initial = worker_command($copy, 'read');
    check($initial['format'] === 1, 'Unexpected data format.');
    check($initial['revision'] === 1, 'Unexpected initial document revision.');
    check(isset($initial['notes']['welcome']), 'The welcome note is missing.');

    $abaRoot = $temporaryRoot . '/aba';
    check(mkdir($abaRoot, 0700) && copy($source, $abaRoot . '/index.php'), 'Could not create the revision fixture.');
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
    check(glob($temporaryRoot . '/.*.phplet-tmp-*.php') === [], 'A temporary snapshot was left behind.');

    // Deterministically exercise the stale-inode retry. Instrument only this
    // disposable copy: A opens inode 1 and pauses; B replaces it with inode 2;
    // A resumes, rejects its stale descriptor, retries, and preserves B's save.
    $raceRoot = $temporaryRoot . '/race';
    check(mkdir($raceRoot, 0700), 'Could not create the stale-inode fixture.');
    $raceCopy = $raceRoot . '/index.php';
    check(copy($source, $raceCopy), 'Could not create the stale-inode copy.');
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
    check(mkdir($faultRoot, 0700) && copy($source, $faultRoot . '/index.php'), 'Could not create the fault-injection fixture.');
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
    check(glob($faultRoot . '/.*.phplet-tmp-*.php') === [], 'A failed pre-rename commit left its temporary snapshot.');

    $modeRoot = $temporaryRoot . '/mode';
    check(mkdir($modeRoot, 0700) && copy($source, $modeRoot . '/index.php'), 'Could not create the mode fixture.');
    $modeCopy = $modeRoot . '/index.php';
    check(chmod($modeCopy, 0440), 'Could not set the mode fixture permissions.');
    clearstatcache(true, $modeCopy);
    $readOnlyInode = fileinode($modeCopy);
    worker_command($modeCopy, 'save', ['id' => null, 'baseRevision' => 0, 'title' => 'Mode check', 'body' => '', 'tags' => []]);
    clearstatcache(true, $modeCopy);
    check(fileinode($modeCopy) !== $readOnlyInode && worker_command($modeCopy, 'summary')['notes'] === 2, 'A readable file in a writable directory could not be atomically replaced.');
    check((fileperms($modeCopy) & 0777) === 0440, 'Atomic replacement did not preserve read-only mode bits.');

    $hardRoot = $temporaryRoot . '/hardlink';
    check(mkdir($hardRoot, 0700) && copy($source, $hardRoot . '/index.php'), 'Could not create the hard-link fixture.');
    $hardCopy = $hardRoot . '/index.php';
    check(link($hardCopy, $hardRoot . '/alias.php'), 'Could not create a hard-linked alias.');
    $hardHash = hash_file('sha256', $hardCopy);
    $hardFailure = finish_worker(start_worker($hardCopy, 'save', ['id' => null, 'baseRevision' => 0, 'title' => 'Must fail', 'body' => '', 'tags' => []]), 2);
    check(str_contains($hardFailure['stderr'], 'Hard-linked phplets'), 'A hard-linked deployment was not rejected clearly.');
    check(hash_file('sha256', $hardCopy) === $hardHash, 'Hard-link rejection changed the canonical file.');

    $largeRoot = $temporaryRoot . '/large';
    check(mkdir($largeRoot, 0700) && copy($source, $largeRoot . '/index.php'), 'Could not create the large-data fixture.');
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
    check(str_contains($oversize['stderr'], 'PhpletHttpError'), 'An over-limit snapshot did not return a size error.');
    check(hash_file('sha256', $largeCopy) === $beforeOversize, 'An over-limit save changed the canonical file.');
    check(glob($largeRoot . '/.*.phplet-tmp-*.php') === [], 'An over-limit save left a temporary snapshot.');
    $largeElapsed = microtime(true) - $largeStart;

    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($copy), $lintOutput, $lintStatus);
    check($lintStatus === 0, 'The saved phplet no longer lints.');

    // Exercise the actual HTML and JSON API against another isolated copy.
    $httpRoot = $temporaryRoot . '/http';
    check(mkdir($httpRoot, 0700), 'Could not create the HTTP fixture.');
    $httpCopy = $httpRoot . '/index.php';
    check(copy($source, $httpCopy), 'Could not create the HTTP copy.');
    $port = free_port();
    $serverPipes = [];
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $httpRoot], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $serverPipes, $httpRoot);
    check(is_resource($server), 'Could not start the PHP test server.');
    fclose($serverPipes[0]);
    try {
        wait_for_server($port);
        [$getStatus, $getHeaders, $page] = http_request("http://127.0.0.1:$port/");
        check($getStatus === 200, 'The app page did not return 200.');
        check(str_contains((string) header_value($getHeaders, 'Content-Security-Policy'), "default-src 'none'"), 'The CSP is missing.');
        check(preg_match('/name="phplet-csrf" content="([a-f0-9]{64})"/', $page, $tokenMatch) === 1, 'The CSRF token is missing.');
        $setCookie = header_value($getHeaders, 'Set-Cookie');
        check($setCookie !== null && preg_match('/^([^=]+)=([^;]+)/', $setCookie, $cookieMatch) === 1, 'The CSRF cookie is missing.');
        $token = $tokenMatch[1];
        $cookie = $cookieMatch[1] . '=' . $cookieMatch[2];
        $payload = json_encode(['id' => null, 'baseRevision' => 0, 'title' => 'HTTP note', 'body' => '<img src=x onerror=alert(1)>', 'tags' => ['web']], JSON_THROW_ON_ERROR);

        [$rebindStatus] = http_request("http://127.0.0.1:$port/", 'GET', ['Host: notes.attacker.example']);
        check($rebindStatus === 403, 'Password-free local mode accepted an untrusted Host header.');
        [$methodStatus, $methodHeaders] = http_request("http://127.0.0.1:$port/?api=save");
        check($methodStatus === 405 && header_value($methodHeaders, 'Allow') === 'POST', 'The API did not reject GET with Allow: POST.');

        [$missingStatus] = http_request("http://127.0.0.1:$port/?api=save", 'POST', ['Content-Type: application/json'], $payload);
        check($missingStatus === 403, 'A mutation without CSRF protection was accepted.');

        [$typeStatus] = http_request("http://127.0.0.1:$port/?api=save", 'POST', ["Cookie: $cookie", 'Content-Type: text/plain', "X-CSRF-Token: $token"], '{}');
        check($typeStatus === 415, 'The API accepted a non-JSON content type.');
        [$malformedStatus] = http_request("http://127.0.0.1:$port/?api=save", 'POST', ["Cookie: $cookie", 'Content-Type: application/json', "X-CSRF-Token: $token"], '{');
        check($malformedStatus === 400, 'The API accepted malformed JSON.');
        $objectTags = json_encode(['id' => null, 'baseRevision' => 0, 'title' => 'Bad tags', 'body' => '', 'tags' => ['name' => 'not-a-list']], JSON_THROW_ON_ERROR);
        [$tagStatus] = http_request("http://127.0.0.1:$port/?api=save", 'POST', ["Cookie: $cookie", 'Content-Type: application/json', "X-CSRF-Token: $token"], $objectTags);
        check($tagStatus === 422, 'The API accepted associative tags.');

        [$postStatus, , $postBody] = http_request("http://127.0.0.1:$port/?api=save", 'POST', ["Cookie: $cookie", 'Content-Type: application/json', "X-CSRF-Token: $token"], $payload);
        check($postStatus === 200, 'A valid HTTP save failed.');
        $post = json_decode($postBody, true, 16, JSON_THROW_ON_ERROR);
        check($post['result']['title'] === 'HTTP note', 'The HTTP response returned the wrong note.');

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
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($downloadCopy), $downloadLint, $downloadLintStatus);
        check($downloadLintStatus === 0 && worker_command($downloadCopy, 'summary')['notes'] === 2, 'The downloaded snapshot was not runnable and decodable.');

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
        proc_terminate($server);
        proc_close($server);
    }

    $authRoot = $temporaryRoot . '/auth';
    check(mkdir($authRoot, 0700) && copy($source, $authRoot . '/index.php'), 'Could not create the authentication fixture.');
    $authPort = free_port();
    $authEnvironment = getenv();
    $authEnvironment = is_array($authEnvironment) ? $authEnvironment : [];
    $authEnvironment['PHPLET_PASSWORD'] = 'correct horse battery staple';
    $authPipes = [];
    $authServer = proc_open([PHP_BINARY, '-S', "127.0.0.1:$authPort", '-t', $authRoot], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $authPipes, $authRoot, $authEnvironment);
    check(is_resource($authServer), 'Could not start the authenticated test server.');
    fclose($authPipes[0]);
    try {
        wait_for_server($authPort);
        [$anonymousStatus, $anonymousHeaders] = http_request("http://127.0.0.1:$authPort/");
        check($anonymousStatus === 401 && header_value($anonymousHeaders, 'WWW-Authenticate') !== null, 'Password mode did not challenge an anonymous request.');
        [$wrongStatus] = http_request("http://127.0.0.1:$authPort/", 'GET', ['Authorization: Basic ' . base64_encode('writer:wrong')]);
        check($wrongStatus === 401, 'Password mode accepted a wrong password.');
        [$correctStatus] = http_request("http://127.0.0.1:$authPort/", 'GET', ['Authorization: Basic ' . base64_encode('writer:correct horse battery staple')]);
        check($correctStatus === 200, 'Password mode rejected the configured password.');
    } finally {
        proc_terminate($authServer);
        proc_close($authServer);
    }

    check(hash_file('sha256', $source) === $sourceHashBefore, 'The test runner changed the source phplet.');
    fwrite(STDOUT, sprintf("ok — %d assertions; source file untouched; 7+ MiB cycle %.2fs (worker peak %.1f MiB)\n", $assertions, $largeElapsed, $largePeak / 1024 / 1024));
} catch (Throwable $error) {
    fwrite(STDERR, "not ok — {$error->getMessage()}\n");
    $exitStatus = 1;
} finally {
    stop_live_workers();
    remove_tree($temporaryRoot);
    if ($sourceHashBefore !== false && hash_file('sha256', $source) !== $sourceHashBefore) {
        fwrite(STDERR, "not ok — source phplet changed during tests\n");
        $exitStatus = 1;
    }
}
exit($exitStatus);
