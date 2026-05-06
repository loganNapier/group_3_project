<?php
declare(strict_types=1);

require_once __DIR__ . "/auth/config.php";
require_once __DIR__ . "/auth/auth.php";

require_login();
$uid = (int)$_SESSION['uid'];

ob_start();

function back(string $msg): void {
  $_SESSION['flash'] = $msg;
  if (!headers_sent()) {
    header("Location: batch_add.php");
    exit;
  }

  echo '<script>window.location.href="batch_add.php";</script>';
  exit;
}

function loadAllCards(string $path): array {
  if (!is_file($path) || !is_readable($path)) {
    return [];
  }

  $json = @file_get_contents($path);
  if ($json === false) {
    return [];
  }

  $data = json_decode($json, true);
  if (!is_array($data)) {
    return [];
  }

  if (isset($data['data']) && is_array($data['data'])) {
    return $data['data'];
  }

  return $data;
}

function findCardInLocalJson(string $query, array $allCards): ?array {
  $needle = mb_strtolower(trim($query));

  foreach ($allCards as $card) {
    if (!isset($card['name'])) {
      continue;
    }

    if (mb_strtolower($card['name']) === $needle) {
      return $card;
    }
  }

  foreach ($allCards as $card) {
    if (!isset($card['name'])) {
      continue;
    }

    if (mb_stripos($card['name'], $query) !== false) {
      return $card;
    }
  }

  return null;
}

function fetchScryfall(string $url): ?string {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 10);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: MTG-Collection-Tracker/1.0',
    'Accept: application/json'
  ]);

  $res = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);

  if ($err) {
    return null;
  }

  return $res;
}

/* FLASH */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ---------------- POST ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if (!csrf_check($_POST['csrf'] ?? null)) {
    back("Bad CSRF token.");
  }

  $action = $_POST['action'] ?? '';

  $condition = $_POST['card_condition'] ?? 'NM';
  $language  = $_POST['card_language'] ?? 'English';
  $finish    = $_POST['finish'] ?? 'nonfoil';

  /* -------- PREVIEW -------- */
  if ($action === 'preview') {

    $rows = [];

    $lines = trim($_POST['lines'] ?? '');
    if ($lines !== '') {
      foreach (explode("\n", $lines) as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $rows[] = [
          'query' => $line,
          'qty' => 1
        ];
      }
    }

    if (!empty($_FILES['csv_file']['tmp_name'])) {
      if (($h = fopen($_FILES['csv_file']['tmp_name'], 'r'))) {
        while (($data = fgetcsv($h)) !== false) {
          if (!$data[0]) continue;

          $rows[] = [
            'query' => trim($data[0]),
            'qty' => isset($data[1]) ? (int)$data[1] : 1
          ];
        }
        fclose($h);
      }
    }

    if (!$rows) back("No valid input.");

    $_SESSION['batch_preview'] = [
      'rows' => $rows,
      'condition' => $condition,
      'language' => $language,
      'finish' => $finish
    ];

    back("Preview ready (" . count($rows) . ")");
  }

  /* -------- CONFIRM -------- */
  if ($action === 'confirm') {

    $batch = $_SESSION['batch_preview'] ?? null;
    if (!$batch) back("No batch to import.");

    $rows = $batch['rows'];
    $condition = $batch['condition'];
    $language = $batch['language'];
    $finish = $batch['finish'];

    $batchId = bin2hex(random_bytes(8));
    $added = 0;
    $errors = [];

    $allCardsPath = __DIR__ . '/all-cards.json';
    $allCards = loadAllCards($allCardsPath);
    $useLocal = !empty($allCards);

    foreach ($rows as $r) {
      $card = null;

      if ($useLocal) {
        $card = findCardInLocalJson($r['query'], $allCards);
        if ($card === null) {
          $errors[] = "{$r['query']}: Card not found in local JSON";
          continue;
        }
      } else {
        $query = urlencode($r['query']);

        $url1 = "https://api.scryfall.com/cards/named?fuzzy={$query}";
        $url2 = "https://api.scryfall.com/cards/search?q={$query}&unique=cards";

        /* ---------------- try fuzzy ---------------- */
        $json = fetchScryfall($url1);
        if ($json === null) {
          $errors[] = "{$r['query']}: API error (fuzzy lookup)";
          continue;
        }

        $card = json_decode($json, true);

        /* ---------------- fallback if needed ---------------- */
        if (!is_array($card) || ($card['object'] ?? '') !== 'card') {

          $json = fetchScryfall($url2);
          if ($json === null) {
            $errors[] = "{$r['query']}: API error (search fallback)";
            continue;
          }

          $search = json_decode($json, true);

          if (!is_array($search)) {
            $errors[] = "{$r['query']}: Invalid search response";
            continue;
          }

          if (($search['object'] ?? '') === 'error') {
            $errors[] = "{$r['query']}: Search API error - " . ($search['details'] ?? 'unknown');
            continue;
          }

          if (!empty($search['data']) && is_array($search['data']) && !empty($search['data'][0])) {
            $card = $search['data'][0];
          } else {
            $errors[] = "{$r['query']}: Card not found";
            continue;
          }
        }

        /* ---------------- FINAL VALIDATION ---------------- */
        if (!is_array($card) || !isset($card['id'])) {
          $errors[] = "{$r['query']}: Invalid card data from API";
          continue;
        }
      }

      try {

        $pdo->beginTransaction();

        // UPSERT into cards table
        $stmt = $pdo->prepare("
          INSERT INTO cards (
            scryfall_id, oracle_id, name, type_line,
            set_code, set_name, collector_number,
            image_small, image_normal,
            price_usd, price_usd_foil, price_usd_etched, price_updated_at
          ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
          ON DUPLICATE KEY UPDATE
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

        $stmt->execute([
          $card['id'],
          $card['oracle_id'] ?? null,
          $card['name'],
          $card['type_line'] ?? null,
          strtoupper($card['set'] ?? ''),
          $card['set_name'] ?? null,
          $card['collector_number'] ?? null,
          $card['image_uris']['small'] ?? null,
          $card['image_uris']['normal'] ?? null,
          $card['prices']['usd'] ?? null,
          $card['prices']['usd_foil'] ?? null,
          $card['prices']['usd_etched'] ?? null
        ]);

        // Get card ID
        $stmt = $pdo->prepare("SELECT id FROM cards WHERE scryfall_id = ?");
        $stmt->execute([$card['id']]);
        $cardId = (int)$stmt->fetchColumn();

        if (!$cardId) {
          $pdo->rollBack();
          $errors[] = "{$card['name']}: Database error (card not inserted)";
          continue;
        }

        // Insert into user_collection
        $stmt = $pdo->prepare("
          INSERT INTO user_collection (
            user_id, card_id, qty,
            card_condition, card_language,
            finish, batch_id
          ) VALUES (?, ?, ?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE
            qty = qty + VALUES(qty),
            batch_id = VALUES(batch_id)
        ");

        $stmt->execute([
          $uid,
          $cardId,
          $r['qty'],
          $condition,
          $language,
          $finish,
          $batchId
        ]);

        $pdo->commit();
        $added++;
        if (!$useLocal) {
          usleep(200000); // 0.2s delay = safe under Scryfall limits
        }

      } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        $errors[] = "{$r['query']}: Database error - " . $e->getMessage();
      }
    }

    unset($_SESSION['batch_preview']);
    
    $msg = "Imported {$added} cards.";
    if (!empty($errors)) {
      $_SESSION['batch_errors'] = $errors;
      $msg .= " (" . count($errors) . " failed)";
    }
    
    back($msg);
  }

  if ($action === 'undo') {
    unset($_SESSION['batch_preview']);
    back("Batch cleared.");
  }
}
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Batch Add</title>
<link rel="stylesheet" href="./css/batch_add.css">
</head>

<body>

<header>
  <div class="wrap">
    <div class="top">
      <div class="brand">MTG Collection DB</div>

      <nav>
        <ul>
          <li><a href="index.php">Home</a></li>
          <li><a href="cards.php">Browse cards</a></li>
          <li><a href="collection.php">My collection</a></li>
          <li><a href="batch_add.php" aria-current="page">Batch add</a></li>
          <li><a href="decks.php">Decks</a></li>
          <li><a href="logout.php">Logout</a></li>
        </ul>
      </nav>
    </div>
  </div>
</header>

<main>
<div class="wrap">

<?php if ($flash): ?>
  <div class="statusline ok"><?= h($flash) ?></div>
<?php endif; ?>

<?php if (!empty($_SESSION['batch_errors'])): ?>
  <section class="card">
    <h2>Import Errors</h2>
    <ul style="color: #c33;">
    <?php foreach ($_SESSION['batch_errors'] as $err): ?>
      <li><?= h($err) ?></li>
    <?php endforeach; ?>
    </ul>
  </section>
  <?php unset($_SESSION['batch_errors']); ?>
<?php endif; ?>

<section class="card">

<h1>Batch Add</h1>

<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

<label>Paste queries</label>
<textarea name="lines"></textarea>

<label>CSV file</label>
<input type="file" name="csv_file">

<fieldset>
<legend>Defaults</legend>

<label>Condition</label>
<select name="card_condition">
<option>NM</option><option>LP</option><option>MP</option>
<option>HP</option><option>DMG</option>
</select>

<label>Language</label>
<input name="card_language" value="English">

<label>Finish</label>
<select name="finish">
<option value="nonfoil">Nonfoil</option>
<option value="foil">Foil</option>
<option value="etched">Etched</option>
</select>

</fieldset>

<div class="actions">
<button class="btn" name="action" value="preview">Preview</button>
<button class="btn danger" name="action" value="undo">Clear</button>
</div>

</form>

</section>

<?php if (!empty($_SESSION['batch_preview'])): ?>

<section class="card">

<h2>Preview</h2>

<form method="post">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

<table>
<tr><th>Query</th><th>Qty</th></tr>

<?php foreach ($_SESSION['batch_preview']['rows'] as $r): ?>
<tr>
<td><?= h($r['query']) ?></td>
<td><?= (int)$r['qty'] ?></td>
</tr>
<?php endforeach; ?>

</table>

<div class="actions">
<button class="btn" name="action" value="confirm">Confirm Import</button>
<button class="btn danger" name="action" value="undo">Cancel</button>
</div>

</form>

</section>

<?php endif; ?>

</div>
</main>

</body>
</html>