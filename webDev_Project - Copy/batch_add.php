<?php
declare(strict_types=1);

require_once __DIR__ . "/auth/config.php";
require_once __DIR__ . "/auth/auth.php";


require_login();
$user = current_user($pdo);
$loggedIn = (bool)$user;
$uid = (int)$_SESSION['uid'];



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
  if ($json === false) return [];

  $json = @gzdecode($json) ?: $json;

  $data = json_decode($json, true);
  if (!is_array($data)) return [];

  return $data['data'] ?? $data;
}

function findCardNames(array $card): array {
  $names = [];

  if (!empty($card['name'])) {
    $names[] = (string)$card['name'];
  }

  if (!empty($card['card_faces']) && is_array($card['card_faces'])) {
    foreach ($card['card_faces'] as $face) {
      if (!empty($face['name'])) {
        $names[] = (string)$face['name'];
      }
    }
  }

  return array_values(array_unique($names, SORT_STRING));
}

function findCardInLocalJson(string $query, array $allCards): ?array {
  $needle = mb_strtolower(trim($query));

  foreach ($allCards as $card) {
    if (!isset($card['name'])) continue;

    foreach (findCardNames($card) as $name) {
      if (mb_strtolower($name) === $needle) {
        return $card;
      }
    }
  }

  foreach ($allCards as $card) {
    if (!isset($card['name'])) continue;

    foreach (findCardNames($card) as $name) {
      if (mb_stripos($name, $query) !== false) {
        return $card;
      }
    }
  }

  return null;
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

        $qty = 1;
        $query = $line;

        if (preg_match('/^(\d+)\s+(.+)$/', $line, $m)) {
          $qty = max(1, min(999, (int)$m[1]));
          $query = trim($m[2]);
        }

        $rows[] = [
          'query' => $query,
          'qty' => $qty
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

    $allCardsPath = __DIR__ . '/oracle-cards.json';
    $allCards = loadAllCards($allCardsPath);

    if (empty($allCards)) {
      back("Missing oracle-cards.json");
    }

    foreach ($rows as $r) {
      $card = findCardInLocalJson($r['query'], $allCards);
      if ($card === null) {
        $errors[] = "{$r['query']}: not found";
        continue;
      }

      try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
          INSERT INTO cards (
            scryfall_id, oracle_id, name, type_line,
            set_code, set_name, collector_number,
            image_small, image_normal,
            price_usd, price_usd_foil, price_usd_etched, price_updated_at
          ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
          ON DUPLICATE KEY UPDATE name = VALUES(name)
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

        $stmt = $pdo->prepare("SELECT id FROM cards WHERE scryfall_id = ?");
        $stmt->execute([$card['id']]);
        $cardId = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
          INSERT INTO user_collection (
            user_id, card_id, qty,
            card_condition, card_language,
            finish, batch_id
          ) VALUES (?, ?, ?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
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

      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = "{$r['query']}: DB error";
      }
    }

    unset($_SESSION['batch_preview']);

    $msg = "Imported {$added} cards.";
    if ($errors) {
      $_SESSION['batch_errors'] = $errors;
      $msg .= " (" . count($errors) . " errors)";
    }

    back($msg);
  }

  if ($action === 'undo') {
    unset($_SESSION['batch_preview']);
    back("Cleared.");
  }
}
?>

<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Batch Add</title>
<link rel="stylesheet" href="./css/batch_add.css">
<link rel="icon" href="/img/mtg_collection_tracker_favicon.ico" type="image/x-icon">
</head>

<body>
  <?php require_once __DIR__ . "/../partials/header.php"; ?>


<main>
<div class="wrap">

<?php if ($flash): ?>
  <div class="statusline ok"><?= h($flash) ?></div>
<?php endif; ?>

<section class="card">

<h1>Batch Add</h1>

<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

<textarea name="lines"></textarea>
<input type="file" name="csv_file">

<div class="actions">
  <button class="btn" name="action" value="preview">Preview</button>
  <button class="btn danger" name="action" value="undo">Clear</button>
</div>
</form>

</section>

<?php if (!empty($_SESSION['batch_preview'])): ?>

<?php
$allCards = loadAllCards(__DIR__ . '/oracle-cards.json');
?>

<section class="card">
<h2>Preview</h2>

<form method="post">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

<table class="previewTable">
<tr><th>Card</th><th>Qty</th></tr>

<?php foreach ($_SESSION['batch_preview']['rows'] as $r): ?>
  <?php $card = findCardInLocalJson($r['query'], $allCards); ?>

  <tr>
    <td>
      <div class="previewCard">

        <?php if (!empty($card['image_uris']['small'])): ?>
          <img src="<?= h($card['image_uris']['small']) ?>">
        <?php else: ?>
          <div class="noImg"></div>
        <?php endif; ?>

        <div>
          <div class="previewName">
            <?= h($card['name'] ?? $r['query']) ?>
          </div>
          <div class="small">
            <?= h($card['set_name'] ?? '') ?>
          </div>
        </div>

      </div>
    </td>

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