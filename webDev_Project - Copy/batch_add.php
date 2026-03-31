<?php
// batch_add.php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/auth.php";

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

<style>
:root{
  --bg:#0b0f14; --panel:#121a24; --panel2:#0e141d;
  --text:#e8eef7; --muted:#9bb0c9; --accent:#7cc4ff;
  --border:#223246; --danger:#ff6b6b; --ok:#72e6a6;
  --focus:0 0 0 3px rgba(124,196,255,.35);
}

*{ box-sizing:border-box; }

body{
  margin:0;
  font-family:system-ui, Arial, sans-serif;
  background:var(--bg);
  color:var(--text);
  line-height:1.5;
}

a{ color:var(--text); }
a:hover{ color:var(--accent); }
a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible{
  outline:none; box-shadow:var(--focus); border-color:rgba(124,196,255,.7);
}

.skip{ position:absolute; left:-9999px; top:auto; width:1px; height:1px; overflow:hidden; }
.skip:focus{
  position:static; width:auto; height:auto; display:inline-block;
  margin:10px; padding:10px 12px; background:var(--panel);
  border:1px solid var(--border); border-radius:12px;
}

header{ border-bottom:1px solid var(--border); background:linear-gradient(90deg,var(--panel),var(--bg)); }
.wrap{ max-width:1100px; margin:0 auto; padding:16px; }
.top{ display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.brand{ font-weight:900; letter-spacing:.2px; }
nav ul{ list-style:none; margin:0; padding:0; display:flex; gap:10px; flex-wrap:wrap; }
nav a{ display:inline-block; padding:10px 12px; border-radius:12px; border:1px solid transparent; text-decoration:none; }
nav a:hover{ background:rgba(255,255,255,.03); border-color:var(--border); }

main{
  padding:18px 0 28px;
}

.card{
  background:linear-gradient(180deg,var(--panel),var(--panel2));
  border:1px solid var(--border);
  border-radius:16px;
  padding:14px;
}

h1,h2{ margin:0 0 10px; }

p{
  margin:0 0 12px;
  color:var(--muted);
}

label{
  display:block;
  margin:10px 0 6px;
  color:var(--muted);
  font-size:.95rem;
}

textarea,input,select{
  width:100%;
  padding:10px 11px;
  border-radius:12px;
  border:1px solid var(--border);
  background:#0c121a;
  color:var(--text);
}

textarea{
  min-height:170px;
  resize:vertical;
}

.row{
  display:grid;
  grid-template-columns:1fr;
  gap:12px;
}

@media (min-width:900px){
  .row{ grid-template-columns:1.1fr .9fr; }
}

fieldset{
  border:1px solid var(--border);
  border-radius:14px;
  padding:12px;
}

legend{
  padding:0 6px;
  color:var(--muted);
}

.actions{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  margin-top:10px;
}

.btn{
  display:inline-block;
  padding:10px 12px;
  border-radius:12px;
  border:1px solid transparent;
  background:var(--accent);
  color:#04111c;
  font-weight:900;
  cursor:pointer;
}

.btn.secondary{
  background:transparent;
  border-color:var(--border);
  color:var(--text);
}

.btn.danger{
  background:transparent;
  border-color:rgba(255,107,107,.5);
  color:var(--danger);
}

.statusline{
  margin-top:10px;
  padding:10px 12px;
  border-radius:12px;
  border:1px solid var(--border);
}

.statusline.ok{
  border-color:rgba(114,230,166,.35);
  color:var(--ok);
  background:rgba(114,230,166,.06);
}

.preview{
  margin-top:12px;
  display:grid;
  grid-template-columns:1fr;
  gap:10px;
}

.item{
  border:1px solid var(--border);
  border-radius:14px;
  padding:12px;
  background:rgba(255,255,255,.02);
}

.thumb{
  width:72px;
  border-radius:10px;
  border:1px solid var(--border);
}

.itemGrid{
  display:grid;
  grid-template-columns:72px 1fr;
  gap:12px;
}

.small{
  color:var(--muted);
  font-size:.92rem;
}
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