<?php
// decks.php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/auth.php";

require_login();
$uid = (int)$_SESSION['uid'];

$user = current_user($pdo);

$flash = null;
if (!empty($_SESSION['flash'])) {
  $flash = (string)$_SESSION['flash'];
  unset($_SESSION['flash']);
}

$stmt = $pdo->prepare("
  SELECT id, name, format, description, is_public, updated_at
  FROM decks
  WHERE user_id = ?
  ORDER BY updated_at DESC, name ASC
");
$stmt->execute([$uid]);
$decks = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>My Decks</title>
  <style>
    :root{
      --bg:#0b0f14; --panel:#121a24; --panel2:#0e141d;
      --text:#e8eef7; --muted:#9bb0c9; --accent:#7cc4ff;
      --border:#223246; --danger:#ff6b6b; --ok:#72e6a6;
      --focus:0 0 0 3px rgba(124,196,255,.35);
    }
    *{ box-sizing:border-box; }
    body{ margin:0; font-family:system-ui, Arial, sans-serif; background:var(--bg); color:var(--text); line-height:1.5; }
    a{ color:var(--text); } a:hover{ color:var(--accent); }
    a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible{
      outline:none; box-shadow:var(--focus); border-color:rgba(124,196,255,.7);
    }

    .skip{ position:absolute; left:-9999px; top:auto; width:1px; height:1px; overflow:hidden; }
    .skip:focus{ position:static; width:auto; height:auto; display:inline-block; margin:10px; padding:10px 12px; background:var(--panel); border:1px solid var(--border); border-radius:12px; }

    header{ border-bottom:1px solid var(--border); background:linear-gradient(90deg,var(--panel),var(--bg)); }
    .wrap{ max-width:1100px; margin:0 auto; padding:16px; }
    .top{ display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .brand{ font-weight:900; letter-spacing:.2px; }
    nav ul{ list-style:none; margin:0; padding:0; display:flex; gap:10px; flex-wrap:wrap; }
    nav a{ display:inline-block; padding:10px 12px; border-radius:12px; border:1px solid transparent; text-decoration:none; }
    nav a:hover{ background:rgba(255,255,255,.03); border-color:var(--border); }

    main{ padding:14px 0 26px; }
    .card{ background:linear-gradient(180deg,var(--panel),var(--panel2)); border:1px solid var(--border); border-radius:16px; padding:14px; }
    h1,h2{ margin:0 0 10px; line-height:1.2; }
    p{ margin:0 0 12px; color:var(--muted); }

    .statusline{ margin-top:10px; padding:10px 12px; border-radius:12px; border:1px solid var(--border); }
    .statusline.ok{ border-color:rgba(114,230,166,.35); color:var(--ok); background:rgba(114,230,166,.06); }

    .grid{ display:grid; grid-template-columns: 1fr; gap:12px; margin-top:12px; }
    @media (min-width: 900px){ .grid{ grid-template-columns: .9fr 1.1fr; align-items:start; } }

    label{ display:block; margin:10px 0 6px; color:var(--muted); font-size:.95rem; }
    input, select, textarea{
      width:100%; padding:10px 11px; border-radius:12px;
      border:1px solid var(--border); background:#0c121a; color:var(--text);
    }
    textarea{ min-height:90px; resize:vertical; }
    .row{ display:grid; grid-template-columns: 1fr; gap:10px; }
    @media (min-width: 900px){ .row{ grid-template-columns: 1fr 1fr; } }

    .actions{ display:flex; gap:10px; flex-wrap:wrap; }
    .btn{
      display:inline-block; padding:10px 12px; border-radius:12px;
      border:1px solid transparent; background:var(--accent); color:#04111c;
      font-weight:900; cursor:pointer; text-decoration:none;
    }
    .btn.secondary{ background:transparent; border-color:var(--border); color:var(--text); font-weight:800; }
    .btn:hover{ filter:brightness(1.05); }

    .list{ display:grid; grid-template-columns:1fr; gap:10px; }
    .deckItem{
      border:1px solid var(--border); border-radius:14px; padding:12px;
      background:rgba(255,255,255,.02);
    }
    .deckTop{ display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:baseline; }
    .deckName{ font-weight:900; }
    .meta{ color:var(--muted); font-size:.92rem; margin-top:6px; }
    .pill{ display:inline-block; padding:6px 10px; border:1px solid var(--border); border-radius:999px; color:var(--muted); font-size:.85rem; }
  </style>
</head>
<body>
  <a class="skip" href="#main">Skip to main content</a>

  <header>
    <div class="wrap">
      <div class="top">
        <div class="brand">MTG Collection DB</div>
        <nav aria-label="Primary navigation">
          <ul>
            <li><a href="index.php">Home</a></li>
            <li><a href="cards.php">Browse cards</a></li>
            <li><a href="collection.php">My collection</a></li>
            <li><a href="batch_add.php">Batch add</a></li>
            <li><a href="decks.php" aria-current="page">Decks</a></li>
            <li><a href="logout.php">Logout</a></li>
          </ul>
        </nav>
      </div>
    </div>
  </header>

  <main id="main">
    <div class="wrap">
      <section class="card" aria-labelledby="title">
        <h1 id="title">My Decks</h1>
        <p>Create and manage decklists connected to your account.</p>

        <?php if ($flash): ?>
          <div class="statusline ok" role="status" aria-live="polite"><?= h($flash) ?></div>
        <?php endif; ?>

        <div class="grid" aria-label="Deck management">
          <section class="card" aria-labelledby="createTitle">
            <h2 id="createTitle">Create a deck</h2>

            <form action="create_deck.php" method="post">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

              <label for="name">Deck name</label>
              <input id="name" name="name" required maxlength="80" autocomplete="off" />

              <div class="row">
                <div>
                  <label for="format">Format (optional)</label>
                  <input id="format" name="format" maxlength="32" placeholder="Commander, Modern, Standard…" />
                </div>
                <div>
                  <label for="is_public">Visibility</label>
                  <select id="is_public" name="is_public">
                    <option value="0">Private</option>
                    <option value="1">Public (optional)</option>
                  </select>
                </div>
              </div>

              <label for="description">Notes (optional)</label>
              <textarea id="description" name="description" maxlength="800" placeholder="Strategy, budget, upgrade ideas…"></textarea>

              <div class="actions" style="margin-top:10px;">
                <button class="btn" type="submit">Create deck</button>
                <a class="btn secondary" href="import_deck.php">Import decklist</a>
              </div>
            </form>
          </section>

          <section class="card" aria-labelledby="listTitle">
            <h2 id="listTitle">Your decks</h2>

            <?php if (!$decks): ?>
              <p>You don’t have any decks yet. Create one using the form.</p>
            <?php else: ?>
              <div class="list" role="list" aria-label="Deck list">
                <?php foreach ($decks as $d): ?>
                  <article class="deckItem" role="listitem">
                    <div class="deckTop">
                      <div class="deckName"><?= h((string)$d['name']) ?></div>
                      <div class="pill"><?= !empty($d['is_public']) ? 'Public' : 'Private' ?></div>
                    </div>

                    <div class="meta">
                      <?php if (!empty($d['format'])): ?>
                        Format: <?= h((string)$d['format']) ?> •
                      <?php endif; ?>
                      Updated: <?= h((string)$d['updated_at']) ?>
                    </div>

                    <?php if (!empty($d['description'])): ?>
                      <div class="meta"><?= h((string)$d['description']) ?></div>
                    <?php endif; ?>

                    <div class="actions" style="margin-top:10px;">
                      <a class="btn secondary" href="deck.php?id=<?= (int)$d['id'] ?>">Open / edit</a>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
        </div>
      </section>

      <footer style="border-top:1px solid var(--border); color:var(--muted); padding:14px 0; margin-top:12px;">
        <small>School project. Not affiliated with Wizards of the Coast.</small>
      </footer>
    </div>
  </main>
</body>
</html>