<?php
// index.php (no header/footer partials; in-page styling)
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/auth.php";

$user = current_user($pdo);
$loggedIn = (bool)$user;

$flash = null;
if (!empty($_SESSION['flash'])) {
  $flash = (string)$_SESSION['flash'];
  unset($_SESSION['flash']);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>MTG Collection DB</title>
  <style>
    :root{
      --bg:#0b0f14; --panel:#121a24; --panel2:#0e141d;
      --text:#e8eef7; --muted:#9bb0c9; --accent:#7cc4ff;
      --border:#223246; --danger:#ff6b6b; --ok:#72e6a6;
      --focus:0 0 0 3px rgba(124,196,255,.35);
    }
    *{ box-sizing:border-box; }
    body{ margin:0; font-family:system-ui, Arial, sans-serif; background:var(--bg); color:var(--text); line-height:1.5; }
    a{ color:var(--text); }
    a:hover{ color:var(--accent); }
    a:focus-visible, button:focus-visible, input:focus-visible{
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

    main{ padding:18px 0 28px; }
    .card{ background:linear-gradient(180deg,var(--panel),var(--panel2)); border:1px solid var(--border); border-radius:16px; padding:14px; }
    .grid{ display:grid; grid-template-columns: 1.15fr .85fr; gap:12px; margin-top:12px; }
    h1,h2{ margin:0 0 10px; line-height:1.2; }
    p{ margin:0 0 12px; color:var(--muted); }
    .pill{ display:inline-block; padding:6px 10px; border:1px solid var(--border); border-radius:999px; color:var(--muted); font-size:.9rem; }

    label{ display:block; margin:10px 0 6px; color:var(--muted); font-size:.95rem; }
    input{
      width:100%; padding:10px 11px; border-radius:12px;
      border:1px solid var(--border); background:#0c121a; color:var(--text);
    }
    .row{ display:grid; grid-template-columns:1fr 1fr; gap:10px; }

    .actions{ display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; }
    .btn{
      display:inline-block; padding:10px 12px; border-radius:12px;
      border:1px solid transparent; background:var(--accent); color:#04111c;
      font-weight:900; cursor:pointer; text-decoration:none;
    }
    .btn.secondary{ background:transparent; border-color:var(--border); color:var(--text); font-weight:800; }
    .btn:hover{ filter:brightness(1.05); }

    .statusline{ margin-top:10px; padding:10px 12px; border-radius:12px; border:1px solid var(--border); }
    .statusline.ok{ border-color:rgba(114,230,166,.35); color:var(--ok); background:rgba(114,230,166,.06); }
    .statusline.bad{ border-color:rgba(255,107,107,.35); color:var(--danger); background:rgba(255,107,107,.06); }

    footer{ border-top:1px solid var(--border); color:var(--muted); padding:14px 0; }

    @media (max-width: 900px){
      .grid{ grid-template-columns:1fr; }
      .row{ grid-template-columns:1fr; }
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
            <li><a href="index.php" aria-current="page">Home</a></li>
            <li><a href="cards.php">Browse cards</a></li>

            <?php if ($loggedIn): ?>
              <li><a href="collection.php">My collection</a></li>
              <li><a href="batch_add.php">Batch add</a></li>
              <li><a href="decks.php">Decks</a></li>
              <li><a href="logout.php">Logout</a></li>
            <?php else: ?>
              <li><a href="#login">Login</a></li>
              <li><a href="#register">Register</a></li>
            <?php endif; ?>
          </ul>
        </nav>
      </div>
    </div>
  </header>

  <main id="main">
    <div class="wrap">
      <section class="card" aria-labelledby="welcomeTitle">
        <h1 id="welcomeTitle">Track your Magic: The Gathering collection</h1>
        <p>Search cards via Scryfall, then add them to your collection with condition, language, finish, notes, and prices.</p>

        <?php if ($flash): ?>
          <div class="statusline ok" role="status" aria-live="polite"><?= h($flash) ?></div>
        <?php endif; ?>

        <?php if ($loggedIn): ?>
          <p>You are signed in as <span class="pill"><?= h((string)$user['username']) ?></span>.</p>
          <div class="actions">
            <a class="btn" href="collection.php">Go to My collection</a>
            <a class="btn secondary" href="cards.php">Browse cards</a>
            <a class="btn secondary" href="batch_add.php">Batch add</a>
            <a class="btn secondary" href="decks.php">Decks</a>
            <a class="btn secondary" href="logout.php">Logout</a>
          </div>
        <?php else: ?>
          <div class="statusline.bad statusline" role="status" aria-live="polite">Not signed in. Register or log in below.</div>
        <?php endif; ?>
      </section>

      <?php if (!$loggedIn): ?>
        <section class="grid" aria-label="Account actions">
          <section class="card" id="register" aria-labelledby="registerTitle">
            <h2 id="registerTitle">Register</h2>
            <p>Passwords are stored securely using <code>password_hash()</code>.</p>

            <form action="register.php" method="post" autocomplete="on">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

              <div class="row">
                <div>
                  <label for="r_user">Username</label>
                  <input id="r_user" name="username" required maxlength="32" autocomplete="username">
                </div>
                <div>
                  <label for="r_email">Email</label>
                  <input id="r_email" name="email" type="email" required maxlength="255" autocomplete="email">
                </div>
              </div>

              <div class="row">
                <div>
                  <label for="r_pass">Password</label>
                  <input id="r_pass" name="password" type="password" required minlength="8" autocomplete="new-password">
                </div>
                <div>
                  <label for="r_pass2">Confirm password</label>
                  <input id="r_pass2" name="password2" type="password" required minlength="8" autocomplete="new-password">
                </div>
              </div>

              <button class="btn" type="submit">Create account</button>
            </form>
          </section>

          <aside class="card" id="login" aria-labelledby="loginTitle">
            <h2 id="loginTitle">Login</h2>
            <p>Use your username (or email) and password.</p>

            <form action="login.php" method="post" autocomplete="on">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

              <label for="l_id">Username or email</label>
              <input id="l_id" name="identifier" required maxlength="255" autocomplete="username">

              <label for="l_pass">Password</label>
              <input id="l_pass" name="password" type="password" required autocomplete="current-password">

              <button class="btn" type="submit">Login</button>
            </form>

            <div class="actions" style="margin-top:10px;">
              <a class="btn secondary" href="cards.php">Browse without logging in</a>
            </div>
          </aside>
        </section>
      <?php endif; ?>
    </div>
  </main>

  <footer>
    <div class="wrap">
      <small>School project. Not affiliated with Wizards of the Coast.</small>
    </div>
  </footer>
</body>
</html>
