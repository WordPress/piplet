<?php
/* Unsafe local demo: no auth, CSRF, validation, atomic writes, or conflicts. Keep a backup; never put it on a network. */
$raw = file_get_contents(__FILE__);
$pages = json_decode(substr($raw, __COMPILER_HALT_OFFSET__), true);
$pages = is_array($pages) ? $pages : [];
$h = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $id = (string) ($_POST['id'] ?? '');
    if (isset($_POST['delete'])) {
        unset($pages[$id]);
        $id = array_key_first($pages);
    } else {
        if ($id === '' || !isset($pages[$id])) $id = uniqid('p');
        $title = trim((string) ($_POST['title'] ?? ''));
        $pages[$id] = ['title' => $title === '' ? 'Untitled' : $title, 'body' => (string) ($_POST['body'] ?? '')];
    }
    $json = json_encode($pages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (file_put_contents(__FILE__, substr($raw, 0, __COMPILER_HALT_OFFSET__) . "\n$json\n", LOCK_EX) === false) exit('Could not save. Make this file writable.');
    header('Location: ' . ($id === null ? '?new=1' : '?p=' . rawurlencode((string) $id)), true, 303);
    exit;
}

$new = isset($_GET['new']) || !$pages;
$id = $new ? '' : (string) ($_GET['p'] ?? array_key_first($pages));
if (!$new && !isset($pages[$id])) $id = (string) array_key_first($pages);
$page = $new ? ['title' => '', 'body' => ''] : $pages[$id];
$edit = $new || isset($_GET['edit']);
?>
<!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($page['title'] === '' ? 'New page' : $page['title']) ?> · phplet unsafe</title>
<style>
:root{--bg:#eeeae1;--paper:#fffdf8;--ink:#24231f;--muted:#625e56;--line:#d5cfc3;--hot:#a94730}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,sans-serif}:focus-visible{outline:2px solid var(--hot);outline-offset:2px}.skip{position:fixed;top:-4rem;left:.5rem;z-index:3;background:var(--hot);color:white;padding:.5rem;text-decoration:none}.skip:focus{top:.5rem}header,.brand{display:flex;align-items:center}header{height:3.5rem;padding:0 1rem;border-bottom:1px solid var(--line);background:var(--paper)}.brand{gap:.65rem;color:inherit;text-decoration:none;font:700 1.2rem Georgia,serif}.brand:before{content:"";width:.55rem;height:.55rem;border:2px solid var(--hot);transform:rotate(45deg)}.actions{display:flex;gap:.4rem;margin-left:auto}button,.button{border:1px solid var(--hot);border-radius:2px;background:var(--hot);color:white;padding:.55rem .85rem;text-decoration:none;font:600 .85rem system-ui;cursor:pointer}.delete{border-color:transparent;background:transparent;color:#8b3326}.layout{display:grid;grid-template-columns:14rem minmax(0,1fr);min-height:calc(100vh - 3.5rem)}aside{padding:1rem;border-right:1px solid var(--line)}.new{display:block;margin-bottom:1rem;text-align:center}nav a{display:block;overflow:hidden;padding:.7rem .75rem;border-left:2px solid transparent;color:var(--ink);text-decoration:none;text-overflow:ellipsis;white-space:nowrap}nav a[aria-current=page]{border-color:var(--hot);background:#e7ded1}.warning{margin:2rem .25rem 0;color:var(--muted);font-size:.72rem}main{padding:clamp(1.25rem,4vw,3.5rem);background:var(--paper)}form,.reading{max-width:75rem;margin:auto}.reading{max-width:48rem}.title,textarea{width:100%;border:0;background:transparent;color:inherit}.title{padding:0 0 .55rem;border-bottom:1px solid var(--line)}.title,.reading h1{font:600 clamp(2.2rem,6vw,4rem)/1.05 Georgia,serif;letter-spacing:-.04em}.reading h1{margin:0 0 2rem;overflow-wrap:anywhere}.title:focus{border-color:var(--hot)}.split{display:grid;grid-template-columns:1fr 1fr;margin-top:2rem;border:1px solid var(--line)}.pane{min-width:0;padding:1rem}.pane+.pane{border-left:1px solid var(--line)}.cap{display:block;margin-bottom:.7rem;color:var(--muted);font-size:.7rem;font-weight:750;letter-spacing:.12em;text-transform:uppercase}textarea{min-height:58vh;padding:0;resize:vertical;font:14px/1.65 ui-monospace,monospace}article{overflow-wrap:anywhere;white-space:pre-wrap;font:18px/1.7 Georgia,serif}.preview-title{margin:0 0 1rem;font:600 2rem/1.1 Georgia,serif;letter-spacing:-.03em}@media(max-width:700px){header{position:sticky;top:0;z-index:2}.layout{display:block}aside{padding:.65rem;border:0;border-bottom:1px solid var(--line)}.new{display:inline-block;margin:0 .5rem 0 0}nav{display:inline-flex;max-width:calc(100% - 7rem);overflow:auto;vertical-align:middle}nav a{flex:none;border:0;border-bottom:2px solid transparent}.warning{margin:.65rem .25rem 0}.split{grid-template-columns:1fr}.pane+.pane{border:0;border-top:1px solid var(--line)}textarea{min-height:35vh}}@media(max-width:360px){header{padding:0 .5rem}.brand{gap:.35rem;font-size:1rem}.actions{gap:.15rem}button{padding:.5rem .55rem}}
</style>
<a class="skip" href="#page">Skip to page</a>
<header>
    <a class="brand" href="?">phplet unsafe</a>
    <span class="actions">
        <?php if ($edit): ?>
            <?php if (!$new): ?><button class="delete" form="editor" name="delete" onclick="return confirm('Delete this page?')">Delete</button><?php endif ?>
            <button form="editor">Save</button>
        <?php else: ?>
            <a class="button" href="?p=<?= rawurlencode($id) ?>&amp;edit=1">Edit</a>
        <?php endif ?>
    </span>
</header>
<div class="layout">
    <aside>
        <a class="button new" href="?new=1">+ New page</a>
        <nav aria-label="Pages">
            <?php foreach ($pages as $key => $item): ?>
                <a href="?p=<?= rawurlencode((string) $key) ?>"<?= (string) $key === $id ? ' aria-current="page"' : '' ?>><?= $h($item['title'] ?? 'Untitled') ?></a>
            <?php endforeach ?>
        </nav>
        <p class="warning">Local only. No security. Keep a backup.</p>
    </aside>
    <main id="page" tabindex="-1">
        <?php if ($edit): ?>
        <form id="editor" method="post">
            <input type="hidden" name="id" value="<?= $h($id) ?>">
            <input class="title" id="title" name="title" aria-label="Title" placeholder="Untitled" value="<?= $h($page['title']) ?>">
            <div class="split">
                <label class="pane"><span class="cap">Write</span><textarea id="body" name="body"><?= $h($page['body']) ?></textarea></label>
                <section class="pane" aria-labelledby="preview-label"><span class="cap" id="preview-label">Preview</span><h1 class="preview-title" id="preview-title"></h1><article id="preview-body"></article></section>
            </div>
        </form>
        <?php else: ?>
        <section class="reading"><h1><?= $h($page['title']) ?></h1><article><?= $h($page['body']) ?></article></section>
        <?php endif ?>
    </main>
</div>
<?php if ($edit): ?><script>
const title=document.querySelector('#title'),body=document.querySelector('#body'),previewTitle=document.querySelector('#preview-title'),previewBody=document.querySelector('#preview-body');
function preview(){previewTitle.textContent=title.value||'Untitled';previewBody.textContent=body.value}title.oninput=body.oninput=preview;preview();
</script><?php endif ?>
<?php __halt_compiler();
{"welcome":{"title":"Hello, phplet","body":"This is a deliberately tiny, unsafe phplet.\n\nCreate another page, type beside the live preview, and save it back into this PHP file."}}
