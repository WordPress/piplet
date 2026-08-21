<?php
declare(strict_types=1);

$source = dirname(__DIR__) . '/wiki-piplet-unsafe.php';
$sourceHash = hash_file('sha256', $source);
$root = sys_get_temp_dir() . '/piplet-unsafe-test-' . bin2hex(random_bytes(5));
$copy = "$root/index.php";
$checks = 0;
$server = null;
$pipes = [];

function check(bool $value, string $message): void
{
    global $checks;
    $checks++;
    if (!$value) throw new RuntimeException($message);
}

function request(int $port, string $path = '/', array $fields = []): array
{
    $options = ['method' => $fields ? 'POST' : 'GET', 'ignore_errors' => true, 'follow_location' => 0];
    if ($fields) {
        $options['header'] = 'Content-Type: application/x-www-form-urlencoded';
        $options['content'] = http_build_query($fields);
    }
    $body = @file_get_contents("http://127.0.0.1:$port$path", false, stream_context_create(['http' => $options]));
    $headers = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $headers[0] ?? '', $match);
    $location = null;
    foreach ($headers as $header) if (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9));
    return [(int) ($match[1] ?? 0), is_string($body) ? $body : '', $location];
}

function pages(string $path, int $offset): array
{
    return json_decode(substr((string) file_get_contents($path), $offset), true, 16, JSON_THROW_ON_ERROR);
}

function page_id(array $pages, string $title): ?string
{
    foreach ($pages as $id => $page) if (($page['title'] ?? null) === $title) return (string) $id;
    return null;
}

function is_editor(string $html): bool
{
    return str_contains($html, '<form id="editor"') && str_contains($html, '<textarea')
        && str_contains($html, '<button form="editor">Save</button>') && !str_contains($html, '<article class="reading">');
}

function is_reader(string $html): bool
{
    return str_contains($html, '<article class="reading">') && !str_contains($html, '<form id="editor"') && !str_contains($html, '<textarea')
        && str_contains($html, '>Edit</a>') && !str_contains($html, '>Save</button>') && !str_contains($html, '>Delete</button>');
}

function has_safe_preview(string $html): bool
{
    return str_contains($html, '<input class="title" name="title"')
        && str_contains($html, '<textarea name="body"')
        && str_contains($html, 'id="preview-title"') && str_contains($html, 'id="preview-body"')
        && str_contains($html, 'document.forms.editor.elements')
        && str_contains($html, 'previewTitle.textContent=title.value')
        && str_contains($html, 'previewBody.textContent=body.value')
        && str_contains($html, 'title.oninput=body.oninput=preview')
        && !str_contains($html, 'previewTitle.innerHTML') && !str_contains($html, 'previewBody.innerHTML');
}

function verify_copy(string $path, string $prefix): void
{
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path), $output, $status);
    check($status === 0, 'A rewrite made the unsafe piplet invalid PHP.');
    check(str_starts_with((string) file_get_contents($path), $prefix), 'A rewrite changed the executable prefix.');
}

function remove_tree(string $path): void
{
    if (!is_dir($path)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($path);
}

try {
    check(mkdir($root, 0700) && copy($source, $copy), 'Could not create the disposable unsafe fixture.');
    $raw = (string) file_get_contents($copy);
    $needle = '<?php __halt_compiler();';
    $offset = strpos($raw, $needle);
    check($offset !== false, 'The unsafe data boundary is missing.');
    $offset += strlen($needle);
    $prefix = substr($raw, 0, $offset);

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    check($socket !== false, "Could not reserve a test port: $errorMessage");
    $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    check(is_resource($server), 'Could not start the unsafe test server.');
    foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
    for ($attempt = 0; $attempt < 100 && request($port)[0] !== 200; $attempt++) usleep(20000);

    $beforeGet = hash_file('sha256', $copy);
    [$status, $html] = request($port);
    check($status === 200 && is_reader($html) && str_contains($html, '<h1>Hello, piplet</h1>') && str_contains($html, 'deliberately tiny') && str_contains($html, 'href="?p=welcome&amp;edit=1"'), 'The initial reading page did not render.');
    [$status, $html] = request($port, '/?p=welcome&edit=1');
    check($status === 200 && is_editor($html) && str_contains($html, 'value="Hello, piplet"') && str_contains($html, '<button class="delete" form="editor" name="delete"'), 'The existing-page editor did not render.');
    check(has_safe_preview($html), 'The live preview stopped treating page text as text.');
    [$status, $html] = request($port, '/?new=1');
    check($status === 200 && is_editor($html) && str_contains($html, '<title>New page · piplet unsafe</title>') && str_contains($html, 'placeholder="Untitled" value=""') && str_contains($html, '<textarea name="body"></textarea>') && !str_contains($html, 'aria-current="page"') && !str_contains($html, '>Delete</button>'), 'The new-page editor did not render blank.');
    [$status, $html] = request($port, '/?p=missing');
    check($status === 200 && is_reader($html) && str_contains($html, '<title>Hello, piplet · piplet unsafe</title>'), 'An invalid page link did not fall back to the first reading page.');
    [$status, $html] = request($port, '/?p=missing&edit=1');
    check($status === 200 && is_editor($html) && str_contains($html, 'value="Hello, piplet"'), 'An invalid edit link did not fall back to the first editor.');
    check(hash_file('sha256', $copy) === $beforeGet, 'A GET rewrote the unsafe piplet.');

    [$status, , $location] = request($port, '/', ['id' => '', 'title' => 'Alpha', 'body' => 'first page']);
    check($status === 303, 'Could not create Alpha.');
    verify_copy($copy, $prefix);
    $alpha = page_id(pages($copy, $offset), 'Alpha');
    check($alpha !== null && $location === '?p=' . rawurlencode($alpha), 'Alpha was not embedded or selected after creation.');
    [$status, , $location] = request($port, '/', ['id' => '', 'title' => 'Beta', 'body' => 'second page']);
    check($status === 303, 'Could not create Beta.');
    verify_copy($copy, $prefix);
    $beta = page_id(pages($copy, $offset), 'Beta');
    check($beta !== null && $beta !== $alpha && $location === '?p=' . rawurlencode($beta), 'The second page was not independent or selected.');
    [$status, $html] = request($port, '/?p=' . rawurlencode($beta));
    check($status === 200 && is_reader($html) && str_contains($html, '<h1>Beta</h1>') && str_contains($html, 'second page') && str_contains($html, 'href="?p=' . rawurlencode($beta) . '&amp;edit=1"'), 'Selecting the second reading page did not render it.');
    [$status, $html] = request($port, '/?p=' . rawurlencode($beta) . '&edit=1');
    check($status === 200 && is_editor($html) && str_contains($html, 'value="Beta"') && str_contains($html, 'second page') && str_contains($html, 'href="?p=' . rawurlencode($beta) . '" aria-current="page"') && !str_contains($html, 'edit=1'), 'The second-page editor or its reading links were wrong.');

    [$status, , $location] = request($port, '/', ['id' => $alpha, 'title' => 'Alpha edited', 'body' => 'changed']);
    check($status === 303 && $location === '?p=' . rawurlencode($alpha), 'Could not edit Alpha.');
    verify_copy($copy, $prefix);
    $stored = pages($copy, $offset);
    check($stored[$alpha]['body'] === 'changed' && $stored[$beta]['body'] === 'second page', 'Editing one page damaged another.');
    [$status, $html] = request($port, '/' . $location);
    check($status === 200 && is_reader($html) && str_contains($html, '<h1>Alpha edited</h1>') && str_contains($html, 'changed'), 'Saving did not return to the edited reading page.');
    [$status, , $location] = request($port, '/', ['id' => $alpha, 'delete' => '']);
    check($status === 303 && $location === '?p=welcome', 'Could not delete Alpha.');
    verify_copy($copy, $prefix);
    $stored = pages($copy, $offset);
    check(!isset($stored[$alpha]) && isset($stored[$beta]), 'Deleting one page damaged another.');

    check(request($port, '/', ['id' => $beta, 'title' => '0', 'body' => 'zero title'])[0] === 303, 'Could not save the title 0.');
    verify_copy($copy, $prefix);
    [$status, $html] = request($port, '/?p=' . rawurlencode($beta));
    check($status === 200 && is_reader($html) && str_contains($html, '<title>0 · piplet unsafe</title>') && str_contains($html, '<h1>0</h1>'), 'The title 0 was treated as empty in the reading page.');
    [$status, $html] = request($port, '/?p=' . rawurlencode($beta) . '&edit=1');
    check($status === 200 && is_editor($html) && str_contains($html, 'value="0"'), 'The title 0 was treated as empty in the editor.');

    $hostileTitle = "\"'><img src=x onerror=alert(1)> ☃";
    $hostileBody = "<?php __halt_compiler();\n<?php file_put_contents(__DIR__.'/PWNED','1');?>\n</textarea><script id=pwn>1</script>";
    check(request($port, '/', ['id' => '', 'title' => $hostileTitle, 'body' => $hostileBody])[0] === 303, 'Could not save hostile text.');
    verify_copy($copy, $prefix);
    $stored = pages($copy, $offset);
    $hostile = page_id($stored, $hostileTitle);
    check($hostile !== null && $stored[$hostile]['body'] === $hostileBody, 'Hostile text did not round-trip exactly.');
    [$status, $html] = request($port, '/?p=' . rawurlencode($hostile));
    check($status === 200 && is_reader($html) && str_contains($html, '&lt;/textarea&gt;&lt;script id=pwn&gt;') && !str_contains($html, '</textarea><script id=pwn>'), 'Stored text was not escaped in the reading page.');
    check(str_contains($html, '&lt;img src=x onerror=alert(1)&gt;') && !str_contains($html, '<img src=x onerror=alert(1)>'), 'The hostile title was not escaped in the reading page.');
    [$status, $html] = request($port, '/?p=' . rawurlencode($hostile) . '&edit=1');
    check($status === 200 && is_editor($html) && has_safe_preview($html) && str_contains($html, '&lt;/textarea&gt;&lt;script id=pwn&gt;') && !str_contains($html, '</textarea><script id=pwn>'), 'Stored text was not escaped in the editor.');
    check(str_contains($html, '&lt;img src=x onerror=alert(1)&gt;') && !str_contains($html, '<img src=x onerror=alert(1)>'), 'The hostile title was not escaped in the editor.');
    check(!file_exists("$root/PWNED"), 'Stored PHP text executed.');
    check(request($port, '/', ['id' => $hostile, 'title' => $hostileTitle, 'body' => $hostileBody . "\nstill works"])[0] === 303, 'A marker-like body broke the next save.');
    verify_copy($copy, $prefix);
    check(pages($copy, $offset)[$hostile]['body'] === $hostileBody . "\nstill works", 'The final hostile update was lost.');

    foreach (array_keys(pages($copy, $offset)) as $remaining) {
        [$status, , $location] = request($port, '/', ['id' => $remaining, 'delete' => '']);
        check($status === 303, 'Could not delete a remaining page.');
        verify_copy($copy, $prefix);
    }
    check(pages($copy, $offset) === [] && $location === '?new=1', 'Deleting the last page did not lead to a new-page editor.');
    [$status, $html] = request($port, '/?new=1');
    check($status === 200 && is_editor($html) && str_contains($html, '<title>New page · piplet unsafe</title>'), 'The empty piplet was not usable.');
    [$status, , $location] = request($port, '/', ['id' => '', 'title' => '   ', 'body' => 'back again']);
    verify_copy($copy, $prefix);
    $stored = pages($copy, $offset);
    $recreated = array_key_first($stored);
    check($status === 303 && count($stored) === 1 && $stored[$recreated]['title'] === 'Untitled' && $location === '?p=' . rawurlencode((string) $recreated), 'Could not recreate a page with the Untitled fallback.');
    [$status, $html] = request($port, '/' . $location);
    check($status === 200 && is_reader($html) && str_contains($html, '<h1>Untitled</h1>') && str_contains($html, 'back again'), 'Recreating the first page did not return to reading mode.');
    check(hash_file('sha256', $source) === $sourceHash, 'The test changed the checked-in unsafe piplet.');

    echo "ok — $checks assertions; canonical unsafe piplet untouched\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        usleep(100000);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($server);
    }
    remove_tree($root);
}
