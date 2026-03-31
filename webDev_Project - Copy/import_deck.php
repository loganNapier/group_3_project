<?php
// import_deck.php
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

function back_with_flash(string $msg, int $deckId = 0): void {
  $_SESSION['flash'] = $msg;
  $to = $deckId > 0 ? ("import_deck.php?deck_id=" . $deckId) : "decks.php";
  header("Location: " . $to);
  exit;
}

function scryfall_search_first_print(string $query): ?array {
  $url = "https://api.scryfall.com/cards/search?q=" . rawurlencode($query) . "&unique=prints&order=released";
  $json = @file_get_contents($url);
  if ($json === false) return null;

  $data = json_decode($json, true);
  if (!is_array($data)) return null;
  if (!empty($data['object']) && $data['object'] === 'error') return null;
  if (empty($data['data'][0]) || !is_array($data['data'][0])) return null;

  return $data['data'][0];
}

function pick_images(array $card): array {
  if (!empty($card['image_uris'])) {
    return [
      'small' => (string)($card['image_uris']['small'] ?? ''),
      'normal' => (string)($card['image_uris']['normal'] ?? ($card['image_uris']['small'] ?? '')),
    ];
  }
  if (!empty($card['card_faces'][0]['image_uris'])) {
    $f0 = $card['card_faces'][0]['image_uris'];
    return [
      'small' => (string)($f0['small'] ?? ''),
      'normal' => (string)($f0['normal'] ?? ($f0['small'] ?? '')),
    ];
  }
  return ['small' => '', 'normal' => ''];
}

function parse_decklist_lines(string $raw): array {
  $lines = preg_split("/\r\n|\r|\n/", $raw) ?: [];
  $out = [];

  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') continue;
    if (str_starts_with($line, '#') || str_starts_with($line, '//')) continue;

    // Accept:
    // "4 Lightning Bolt"
    // "Lightning Bolt"
    // "SB: 2 Wear // Tear"
    $section = 'main';
    $line2 = $line;

    if (preg_match('/^(SB:|SIDE:)\s*/i', $line2)) {
      $section = 'side';
      $line2 = preg_replace('/^(SB:|SIDE:)\s*/i', '', $line2);
      $line2 = trim((string)$line2);
    }

    $qty = 1;
    $name = $line2;

    if (preg_match('/^(\d+)\s+(.+)$/', $line2, $m)) {
      $qty = max(1, min(999, (int)$m[1]));
      $name = trim($m[2]);
    }

    // Strip common suffixes like "(SET) 123" if pasted from other sites.
    // Keep it basic so we don't break valid names.
    $name = preg_replace('/\s+\([A-Za-z0-9]{2,6}\)\s+\d+[A-Za-z]*$/', '', (string)$name);
    $name = trim((string)$name);

    if ($name === '') continue;

    // We use exact name search first; it’s the most reliable.
    $query = '!"' . str_replace('"', '\"', $name) . '"';

    $out[] = [
      'raw' => $line,
      'section' => $section,
      'qty' => $qty,
      'name' => $name,
      'query' => $query,
    ];
  }

  return $out;
}

function to_nullable_decimal_2(string $raw): ?float {
  $raw = trim($raw);
  if ($raw === '') return null;
  if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) return null;
  return (float)$raw;
}

$deckId = (int)($_GET['deck_id'] ?? ($_POST['deck_id'] ?? 0));
if ($deckId <= 0) {
  back_with_flash("Missing deck_id. Open a deck, then choose Import decklist.", 0);
}

// Verify ownership
$stmt = $pdo->prepare("SELECT id, name, format FROM decks WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->execute([$deckId, $uid]);
$deck = $stmt->fetch();
if (!$deck) {
  back_with_flash("Deck not found.", 0);
}

$mode = 'form'; // form | preview
$preview = [];
$rawDecklist = '';

$defaultFinish = 'nonfoil';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? null)) {
    http_response_code(400);
    header("Content-Type: text/plain; charset=utf-8");
    exit("Bad CSRF token.");
  }

  $action = (string)($_POST['action'] ?? 'preview');
  $defaultFinish = strtolower(trim((string)($_POST['finish'] ?? 'nonfoil')));
  if (!in_array($defaultFinish, ['nonfoil','foil','etched'], true)) {
    back_with_flash("Finish must be nonfoil/foil/etched.", $deckId);
  }

  $rawDecklist = (string)($_POST['decklist'] ?? '');

  if ($action === 'preview') {
    $items = parse_decklist_lines($rawDecklist);
    if (!$items) back_with_flash("Paste a decklist first.", $deckId);

    $mode = 'preview';

    foreach ($items as $it) {
      $card = scryfall_search_first_print($it['query']);
      if (!$card) {
        $preview[] = [
          'ok' => false,
          'raw' => $it['raw'],
          'section' => $it['section'],
          'qty' => $it['qty'],
          'name' => $it['name'],
          'error' => 'No match on Scryfall.',
        ];
        continue;
      }

      $img = pick_images($card);

      $preview[] = [
        'ok' => true,
        'raw' => $it['raw'],
        'section' => $it['section'],
        'qty' => $it['qty'],

        'scryfall_id' => (string)($card['id'] ?? ''),
        'oracle_id' => (string)($card['oracle_id'] ?? ''),
        'name' => (string)($card['name'] ?? $it['name']),
        'type_line' => (string)($card['type_line'] ?? ''),
        'set_code' => strtoupper((string)($card['set'] ?? '')),
        'set_name' => (string)($card['set_name'] ?? ''),
        'collector_number' => (string)($card['collector_number'] ?? ''),

        'image_small' => $img['small'],
        'image_normal' => $img['normal'],

        'price_usd' => (string)($card['prices']['usd'] ?? ''),
        'price_usd_foil' => (string)($card['prices']['usd_foil'] ?? ''),
        'price_usd_etched' => (string)($card['prices']['usd_etched'] ?? ''),
      ];
    }
  }

  if ($action === 'import') {
    $items = $_POST['items'] ?? [];
    if (!is_array($items) || !$items) back_with_flash("Nothing to import. Use Preview first.", $deckId);

    try {
      $pdo->beginTransaction();

      $upsertCard = $pdo->prepare("
        INSERT INTO cards
          (scryfall_id, oracle_id, name, type_line, set_code, set_name, collector_number,
           image_small, image_normal, price_usd, price_usd_foil, price_usd_etched, price_updated_at)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
          oracle_id = VALUES(oracle_id),
          name = VALUES(name),
          type_line = VALUES(type_line),
          set_code = VALUES(set_code),
          set_name = VALUES(set_name),
          collector_number = VALUES(collector_number),
          image_small = VALUES(image_small),
          image_normal = VALUES(image_normal),
          price_usd = VALUES(price_usd),
          price_usd_foil = VALUES(price_usd_foil),
          price_usd_etched = VALUES(price_usd_etched),
          price_updated_at = NOW()
      ");

      $getCardId = $pdo->prepare("SELECT id FROM cards WHERE scryfall_id = ? LIMIT 1");

      $upsertDeckCard = $pdo->prepare("
        INSERT INTO deck_cards (deck_id, card_id, section, qty, finish)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          qty = qty + VALUES(qty),
          updated_at = CURRENT_TIMESTAMP
      ");

      $added = 0;

      foreach ($items as $it) {
        if (!is_array($it)) continue;

        $scryfallId = trim((string)($it['scryfall_id'] ?? ''));
        $name = trim((string)($it['name'] ?? ''));
        $section = (string)($it['section'] ?? 'main');
        $qty = (int)($it['qty'] ?? 1);

        if ($scryfallId === '' || $name === '') continue;
        if ($section !== 'side') $section = 'main';
        if ($qty < 1) $qty = 1;
        if ($qty > 999) $qty = 999;

        $upsertCard->execute([
          $scryfallId,
          (($it['oracle_id'] ?? '') !== '' ? (string)$it['oracle_id'] : null),
          $name,
          (($it['type_line'] ?? '') !== '' ? (string)$it['type_line'] : null),
          (($it['set_code'] ?? '') !== '' ? (string)$it['set_code'] : null),
          (($it['set_name'] ?? '') !== '' ? (string)$it['set_name'] : null),
          (($it['collector_number'] ?? '') !== '' ? (string)$it['collector_number'] : null),
          (($it['image_small'] ?? '') !== '' ? (string)$it['image_small'] : null),
          (($it['image_normal'] ?? '') !== '' ? (string)$it['image_normal'] : null),
          to_nullable_decimal_2((string)($it['price_usd'] ?? '')),
          to_nullable_decimal_2((string)($it['price_usd_foil'] ?? '')),
          to_nullable_decimal_2((string)($it['price_usd_etched'] ?? '')),
        ]);

        $getCardId->execute([$scryfallId]);
        $row = $getCardId->fetch();
        if (!$row) continue;

        $cardId = (int)$row['id'];

        $upsertDeckCard->execute([
          $deckId,
          $cardId,
          $section,
          $qty,
          $defaultFinish
        ]);

        $added++;
      }

      $pdo->commit();

      $_SESSION['flash'] = "Imported {$added} line(s) into the deck.";
      header("Location: deck.php?id=" . $deckId);
      exit;
    } catch (PDOException $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      back_with_flash("Database error while importing decklist.", $deckId);
    }
  }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Import decklist — <?= h((string)$deck['name']) ?></title>
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

    label{ display:block; margin:10px 0 6px; color:var(--muted); font-size:.95rem; }
    textarea, select{
      width:100%; padding:10px 11px; border-radius:12px;
      border:1px solid var(--border); background:#0c121a; color:var(--text);
    }
    textarea{ min-height:220px; resize:vertical; }

    .btnRow{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    button{
      border:1px solid transparent; border-radius:12px; padding:10px 12px;
      cursor:pointer; font-weight:900; background:var(--accent); color:#04111c;
    }
    .btnSecondary{
      background:transparent; color:var(--text); border-color:var(--border); font-weight:800;
      display:inline-block; padding:10px 12px; border-radius:12px; text-decoration:none;
    }

    .statusline{ margin-top:10px; padding:10px 12px; border-radius:12px; border:1px solid var(--border); }
    .statusline.ok{ border-color:rgba(114,230,166,.35); color:var(--ok); background:rgba(114,230,166,.06); }
    .statusline.bad{ border-color:rgba(255,107,107,.35); color:var(--danger); background:rgba(255,107,107,.06); }

    .preview{ margin-top:12px; display:grid; grid-template-columns:1fr; gap:10px; }
    .item{ border:1px solid var(--border); border-radius:14px; padding:12px; background:rgba(255,255,255,.02); }
    .itemGrid{ display:grid; grid-template-columns:72px 1fr; gap:12px; align-items:start; }
    .thumb{ width:72px; height:auto; border-radius:10px; border:1px solid var(--border); background:#0c121a; display:block; }
    .small{ font-size:.92rem; color:var(--muted); }
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
            <li><a href="decks.php">Decks</a></li>
            <li><a href="logout.php">Logout</a></li>
          </ul>
        </nav>
      </div>
    </div>
  </header>

  <main id="main">
    <div class="wrap">
      <section class="card" aria-labelledby="t">
        <h1 id="t">Import decklist</h1>
        <p>
          Deck: <strong><?= h((string)$deck['name']) ?></strong>
          <?php if (!empty($deck['format'])): ?>
            <span class="small">(<?= h((string)$deck['format']) ?>)</span>
          <?php endif; ?>
        </p>

        <?php if ($flash): ?>
          <div class="statusline ok" role="status" aria-live="polite"><?= h($flash) ?></div>
        <?php endif; ?>

        <p class="small">
          Paste lines like <code>4 Lightning Bolt</code> or <code>SB: 2 Disenchant</code>.
          We’ll look up each card on Scryfall.
        </p>

        <form method="post" action="import_deck.php">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="deck_id" value="<?= (int)$deckId ?>">

          <label for="finish">Default finish for imported cards</label>
          <select id="finish" name="finish">
            <option value="nonfoil"<?= $defaultFinish === 'nonfoil' ? ' selected' : '' ?>>Non-foil</option>
            <option value="foil"<?= $defaultFinish === 'foil' ? ' selected' : '' ?>>Foil</option>
            <option value="etched"<?= $defaultFinish === 'etched' ? ' selected' : '' ?>>Etched</option>
          </select>

          <label for="decklist">Decklist text</label>
          <textarea id="decklist" name="decklist" required><?= h($rawDecklist) ?></textarea>

          <div class="btnRow">
            <button type="submit" name="action" value="preview">Preview</button>
            <a class="btnSecondary" href="deck.php?id=<?= (int)$deckId ?>">Cancel</a>
          </div>
        </form>

        <?php if ($mode === 'preview'): ?>
          <h2 style="margin-top:14px;">Preview</h2>
          <div class="preview" aria-label="Preview results">
            <?php foreach ($preview as $p): ?>
              <div class="item">
                <?php if (!$p['ok']): ?>
                  <div><strong><?= h((string)$p['raw']) ?></strong></div>
                  <div class="statusline bad" role="status">Not found: <?= h((string)$p['name']) ?></div>
                <?php else: ?>
                  <div class="itemGrid">
                    <div>
                      <?php if (!empty($p['image_small'])): ?>
                        <img class="thumb" src="<?= h((string)$p['image_small']) ?>" alt="Card image: <?= h((string)$p['name']) ?>" loading="lazy">
                      <?php else: ?>
                        <div class="thumb" role="img" aria-label="No image available"></div>
                      <?php endif; ?>
                    </div>
                    <div>
                      <div style="font-weight:900;"><?= h((string)$p['name']) ?></div>
                      <div class="small"><?= h((string)$p['type_line']) ?></div>
                      <div class="small">Section: <?= h((string)$p['section']) ?> • Qty: <?= (int)$p['qty'] ?></div>
                      <div class="small"><?= h((string)$p['set_name']) ?> <?= h((string)$p['set_code']) ?> #<?= h((string)$p['collector_number']) ?></div>
                      <div class="small">USD: <?= h((string)($p['price_usd'] ?: '—')) ?> • Foil: <?= h((string)($p['price_usd_foil'] ?: '—')) ?> • Etched: <?= h((string)($p['price_usd_etched'] ?: '—')) ?></div>
                      <div class="small">From: <code><?= h((string)$p['raw']) ?></code></div>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>

          <form method="post" action="import_deck.php" style="margin-top:12px;">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="deck_id" value="<?= (int)$deckId ?>">
            <input type="hidden" name="finish" value="<?= h($defaultFinish) ?>">

            <input type="hidden" name="action" value="import">

            <?php foreach ($preview as $p): ?>
              <?php if (!empty($p['ok'])): ?>
                <input type="hidden" name="items[][section]" value="<?= h((string)$p['section']) ?>">
                <input type="hidden" name="items[][qty]" value="<?= (int)$p['qty'] ?>">

                <input type="hidden" name="items[][scryfall_id]" value="<?= h((string)$p['scryfall_id']) ?>">
                <input type="hidden" name="items[][oracle_id]" value="<?= h((string)$p['oracle_id']) ?>">
                <input type="hidden" name="items[][name]" value="<?= h((string)$p['name']) ?>">
                <input type="hidden" name="items[][type_line]" value="<?= h((string)$p['type_line']) ?>">
                <input type="hidden" name="items[][set_code]" value="<?= h((string)$p['set_code']) ?>">
                <input type="hidden" name="items[][set_name]" value="<?= h((string)$p['set_name']) ?>">
                <input type="hidden" name="items[][collector_number]" value="<?= h((string)$p['collector_number']) ?>">
                <input type="hidden" name="items[][image_small]" value="<?= h((string)$p['image_small']) ?>">
                <input type="hidden" name="items[][image_normal]" value="<?= h((string)$p['image_normal']) ?>">

                <input type="hidden" name="items[][price_usd]" value="<?= h((string)$p['price_usd']) ?>">
                <input type="hidden" name="items[][price_usd_foil]" value="<?= h((string)$p['price_usd_foil']) ?>">
                <input type="hidden" name="items[][price_usd_etched]" value="<?= h((string)$p['price_usd_etched']) ?>">
              <?php endif; ?>
            <?php endforeach; ?>

            <div class="btnRow">
              <button type="submit">Import into deck</button>
              <a class="btnSecondary" href="deck.php?id=<?= (int)$deckId ?>">Cancel</a>
            </div>

            <p class="small" style="margin-top:10px;">
              Only preview rows that were found on Scryfall will be imported.
            </p>
          </form>
        <?php endif; ?>

      </section>

      <footer style="border-top:1px solid var(--border); color:var(--muted); padding:14px 0; margin-top:12px;">
        <small>School project. Not affiliated with Wizards of the Coast.</small>
      </footer>
    </div>
  </main>
</body>
</html>