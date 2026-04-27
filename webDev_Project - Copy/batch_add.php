<?php
// batch_add.php
declare(strict_types=1);

require_once (__DIR__ . "/auth/config.php");
require_once (__DIR__ . "/auth/auth.php");

require_login();
$uid = (int)$_SESSION['uid'];

$user = current_user($pdo);
$loggedIn = true;

$pageTitle = "Batch add";
$activeNav = "batch";

$flash = null;
if (!empty($_SESSION['flash'])) {
  $flash = (string)$_SESSION['flash'];
  unset($_SESSION['flash']);
}

function back_with_flash(string $msg): void {
  $_SESSION['flash'] = $msg;
  header("Location: batch_add.php");
  exit;
}

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* ---- ALL YOUR EXISTING PHP LOGIC REMAINS THE SAME ---- */
/* (preview, CSV parsing, Scryfall lookup, DB inserts etc.) */

/* I am not repeating that block here since nothing changed */
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($pageTitle) ?></title>
<link rel="stylesheet" href="./css/batch_add.css" />
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
          <li><a href="batch_add.php" aria-current="page">Batch add</a></li>
          <li><a href="decks.php">Decks</a></li>
          <li><a href="logout.php">Logout</a></li>
        </ul>
      </nav>
    </div>
  </div>
</header>

<main id="main">
<div class="wrap">

<section class="card">

<h1>Batch add to collection</h1>

<p>
Paste one Scryfall query per line, or upload a CSV with
<code>query,qty</code>.
</p>

<?php if ($flash): ?>
<div class="statusline ok" role="status" aria-live="polite"><?= h($flash) ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">

<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

<div class="row">

<div>

<label for="lines">Paste queries</label>
<textarea id="lines" name="lines" placeholder="lightning bolt
t:dragon set:khm"></textarea>

<label for="csv_file">Upload CSV</label>
<input id="csv_file" type="file" name="csv_file" accept=".csv">

</div>

<div>

<fieldset>
<legend>Defaults for this batch</legend>

<label for="card_condition">Condition</label>
<select id="card_condition" name="card_condition">
<option>NM</option>
<option>LP</option>
<option>MP</option>
<option>HP</option>
<option>DMG</option>
</select>

<label for="card_language">Language</label>
<input id="card_language" name="card_language" value="English">

<label for="finish">Finish</label>
<select id="finish" name="finish">
<option value="nonfoil">Nonfoil</option>
<option value="foil">Foil</option>
<option value="etched">Etched</option>
</select>

<div class="actions">
<button class="btn" name="action" value="preview">Preview</button>
<button class="btn danger" name="action" value="undo">Undo batch</button>
</div>

</fieldset>

</div>

</div>

</form>

</section>

</div>
</main>

<footer style="border-top:1px solid var(--border); color:var(--muted); padding:14px 0;">
  <div class="wrap">
    <small>School project. Not affiliated with Wizards of the Coast.</small>
  </div>
</footer>

</body>
</html>
