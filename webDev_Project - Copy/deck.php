<?php
// deck.php (editable deck cards + totals + inline Scryfall search)
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

$deckId = (int)($_GET['id'] ?? 0);
if ($deckId <= 0) {
  http_response_code(400);
  header("Content-Type: text/plain; charset=utf-8");
  exit("Missing deck id.");
}

$stmt = $pdo->prepare("
  SELECT id, name, format, description, is_public, updated_at
  FROM decks
  WHERE id = ? AND user_id = ?
  LIMIT 1
");
$stmt->execute([$deckId, $uid]);
$deck = $stmt->fetch();
if (!$deck) {
  http_response_code(404);
  header("Content-Type: text/plain; charset=utf-8");
  exit("Deck not found.");
}

$stmt = $pdo->prepare("
  SELECT
    dc.id AS deck_card_id,
    dc.section,
    dc.qty,
    dc.finish,
    dc.updated_at,

    c.id AS card_id,
    c.scryfall_id,
    c.name,
    c.type_line,
    c.set_code,
    c.set_name,
    c.collector_number,
    c.image_small,
    c.image_normal,
    c.price_usd,
    c.price_usd_foil,
    c.price_usd_etched,
    c.price_updated_at
  FROM deck_cards dc
  JOIN cards c ON c.id = dc.card_id
  WHERE dc.deck_id = ?
  ORDER BY dc.section ASC, c.name ASC
");
$stmt->execute([$deckId]);
$deckCards = $stmt->fetchAll();

$main = [];
$side = [];
foreach ($deckCards as $r) {
  if (($r['section'] ?? 'main') === 'side') $side[] = $r;
  else $main[] = $r;
}

function money_val($v): string {
  if ($v === null || $v === '') return '';
  return '$' . number_format((float)$v, 2);
}
function finish_price(array $r): ?float {
  $finish = (string)($r['finish'] ?? 'nonfoil');
  if ($finish === 'foil') {
    return ($r['price_usd_foil'] !== null && $r['price_usd_foil'] !== '') ? (float)$r['price_usd_foil'] : null;
  }
  if ($finish === 'etched') {
    return ($r['price_usd_etched'] !== null && $r['price_usd_etched'] !== '') ? (float)$r['price_usd_etched'] : null;
  }
  return ($r['price_usd'] !== null && $r['price_usd'] !== '') ? (float)$r['price_usd'] : null;
}
function sum_qty(array $rows): int {
  $t = 0;
  foreach ($rows as $r) $t += (int)$r['qty'];
  return $t;
}

$mainQty = sum_qty($main);
$sideQty = sum_qty($side);

$mainEst = 0.0; $mainKnown = 0;
foreach ($main as $r) { $p = finish_price($r); if ($p !== null) { $mainEst += $p * (int)$r['qty']; $mainKnown += (int)$r['qty']; } }
$sideEst = 0.0; $sideKnown = 0;
foreach ($side as $r) { $p = finish_price($r); if ($p !== null) { $sideEst += $p * (int)$r['qty']; $sideKnown += (int)$r['qty']; } }
$deckEst = $mainEst + $sideEst;
$deckKnown = $mainKnown + $sideKnown;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= h((string)$deck['name']) ?> — Deck</title>
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
    h1,h2,h3{ margin:0 0 10px; line-height:1.2; }
    p{ margin:0 0 12px; color:var(--muted); }

    .pill{ display:inline-block; padding:6px 10px; border:1px solid var(--border); border-radius:999px; color:var(--muted); font-size:.9rem; }
    .statusline{ margin-top:10px; padding:10px 12px; border-radius:12px; border:1px solid var(--border); }
    .statusline.ok{ border-color:rgba(114,230,166,.35); color:var(--ok); background:rgba(114,230,166,.06); }
    .statusline.bad{ border-color:rgba(255,107,107,.35); color:var(--danger); background:rgba(255,107,107,.06); }

    .summary{ display:grid; grid-template-columns:1fr; gap:10px; margin-top:12px; }
    @media (min-width: 900px){ .summary{ grid-template-columns:1fr 1fr 1fr; } }
    .summaryItem{ border:1px solid var(--border); border-radius:14px; padding:12px; background:rgba(255,255,255,.02); }
    .summaryItem .big{ font-weight:900; font-size:1.35rem; }
    .small{ font-size:.92rem; color:var(--muted); }

    .grid{ display:grid; grid-template-columns:1fr; gap:12px; margin-top:12px; }
    @media (min-width: 950px){ .grid{ grid-template-columns: .95fr 1.05fr; align-items:start; } }

    label{ display:block; margin:10px 0 6px; color:var(--muted); font-size:.95rem; }
    input, select, textarea{
      width:100%; padding:10px 11px; border-radius:12px;
      border:1px solid var(--border); background:#0c121a; color:var(--text);
    }
    textarea{ min-height:110px; resize:vertical; }

    button{
      border:1px solid transparent; border-radius:12px; padding:10px 12px;
      cursor:pointer; font-weight:900; background:var(--accent); color:#04111c;
    }
    .btnSecondary{ background:transparent; color:var(--text); border-color:var(--border); font-weight:800; }
    .dangerBtn{ background:transparent; color:var(--danger); border-color:rgba(255,107,107,.55); font-weight:900; }
    .dangerBtn:hover{ border-color:rgba(255,107,107,.85); }

    .list{ display:grid; grid-template-columns:1fr; gap:10px; }
    .rowCard{ border:1px solid var(--border); border-radius:14px; padding:12px; background:rgba(255,255,255,.02); }
    .rowGrid{ display:grid; grid-template-columns: 56px 1fr; gap:10px; align-items:start; }
    .thumb{ width:56px; height:auto; border-radius:10px; border:1px solid var(--border); background:#0c121a; display:block; }
    .name{ font-weight:900; }

    .editGrid{ display:grid; grid-template-columns: 90px 140px 160px auto; gap:10px; align-items:end; margin-top:10px; }
    @media (max-width: 520px){ .editGrid{ grid-template-columns: 1fr 1fr; } }

    .btnRow{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .srOnly{ position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); border:0; }

    /* Inline search results */
    .results{ margin-top:12px; display:grid; grid-template-columns:1fr; gap:10px; }
    .result{ border:1px solid var(--border); border-radius:14px; padding:12px; background:rgba(255,255,255,.02); }
    .resultGrid{ display:grid; grid-template-columns:92px 1fr; gap:12px; align-items:start; }
    .thumbWrap{ position:relative; display:inline-block; }
    .thumbLg{ width:92px; height:auto; border-radius:10px; border:1px solid var(--border); background:#0c121a; display:block; }
    .pop{
      position:absolute; left:100%; top:0; margin-left:12px; width:240px;
      display:none; border:1px solid var(--border); border-radius:14px;
      background:linear-gradient(180deg,var(--panel),var(--panel2));
      padding:10px; box-shadow:0 18px 40px rgba(0,0,0,.45); z-index:5;
    }
    .pop img{ width:100%; height:auto; border-radius:10px; border:1px solid var(--border); display:block; }
    .thumbWrap:hover .pop, .thumbWrap:focus-within .pop{ display:block; }
    @media (max-width: 900px){ .pop{ display:none !important; } }

    .addControls{ display:flex; gap:10px; flex-wrap:wrap; align-items:end; margin-top:10px; }

    footer{ border-top:1px solid var(--border); color:var(--muted); padding:14px 0; }
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
      <section class="card" aria-labelledby="deckTitle">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:end;">
          <div>
            <h1 id="deckTitle"><?= h((string)$deck['name']) ?></h1>
            <p>
              <?php if (!empty($deck['format'])): ?>
                <span class="pill">Format: <?= h((string)$deck['format']) ?></span>
              <?php else: ?>
                <span class="pill">No format set</span>
              <?php endif; ?>
              <span class="pill">Main: <?= (int)$mainQty ?></span>
              <span class="pill">Side: <?= (int)$sideQty ?></span>
            </p>
          </div>
          <p class="small" style="margin:0;">Signed in as <span class="pill"><?= h((string)($user['username'] ?? 'User')) ?></span></p>
        </div>

        <?php if ($flash): ?>
          <div class="statusline ok" role="status" aria-live="polite"><?= h($flash) ?></div>
        <?php endif; ?>

        <section class="summary" aria-label="Deck totals">
          <div class="summaryItem">
            <div class="small">Estimated value (Scryfall)</div>
            <div class="big"><?= h(money_val((string)$deckEst)) ?></div>
            <div class="small">Known for <?= (int)$deckKnown ?> cards (main+side).</div>
          </div>
          <div class="summaryItem">
            <div class="small">Mainboard value</div>
            <div class="big"><?= h(money_val((string)$mainEst)) ?></div>
            <div class="small">Known for <?= (int)$mainKnown ?> / <?= (int)$mainQty ?>.</div>
          </div>
          <div class="summaryItem">
            <div class="small">Sideboard value</div>
            <div class="big"><?= h(money_val((string)$sideEst)) ?></div>
            <div class="small">Known for <?= (int)$sideKnown ?> / <?= (int)$sideQty ?>.</div>
          </div>
        </section>

        <?php if (!empty($deck['description'])): ?>
          <p style="margin-top:12px;"><?= h((string)$deck['description']) ?></p>
        <?php endif; ?>

        <form action="delete_deck.php" method="post" style="margin-top:12px;">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="deck_id" value="<?= (int)$deckId ?>">
          <button type="submit" class="dangerBtn" aria-label="Delete deck <?= h((string)$deck['name']) ?>">
            Delete this deck
          </button>
          <p class="small" style="margin-top:8px;">Deleting a deck removes its decklist (deck cards). It does not delete your collection.</p>
        </form>
      </section>

      <section class="grid" aria-label="Deck tools and list">
        <section class="card" aria-labelledby="addTitle">
          <h2 id="addTitle">Add cards to this deck</h2>
          <p class="small">Search uses Scryfall syntax. Example: <code>!"Lightning Bolt"</code> or <code>t:elf set:khm</code>.</p>

          <form id="deckSearchForm" class="addControls" action="#" method="get" novalidate>
            <label class="srOnly" for="q">Search</label>
            <input id="q" name="q" maxlength="200" placeholder='e.g., !"Sol Ring"' />

            <label class="srOnly" for="section">Section</label>
            <select id="section" name="section">
              <option value="main">Mainboard</option>
              <option value="side">Sideboard</option>
            </select>

            <label class="srOnly" for="qty">Qty</label>
            <input id="qty" name="qty" type="number" min="1" max="999" value="1" style="max-width:120px;">

            <label class="srOnly" for="finish">Finish</label>
            <select id="finish" name="finish">
              <option value="nonfoil">Non-foil</option>
              <option value="foil">Foil</option>
              <option value="etched">Etched</option>
            </select>

            <button type="submit">Search</button>

            <a class="btnSecondary" href="import_deck.php?deck_id=<?= (int)$deckId ?>" style="text-decoration:none;padding:10px 12px;border-radius:12px;border:1px solid var(--border);">
              Import decklist
            </a>
          </form>

          <div id="searchStatus" class="statusline" role="status" aria-live="polite">Ready.</div>
          <div id="searchResults" class="results" aria-label="Scryfall search results"></div>
        </section>

        <section class="card" aria-labelledby="listTitle">
          <h2 id="listTitle">Decklist (editable)</h2>

          <h3>Mainboard (<?= (int)$mainQty ?>)</h3>
          <?php if (!$main): ?>
            <p class="small">No mainboard cards yet.</p>
          <?php else: ?>
            <div class="list" role="list" aria-label="Mainboard cards">
              <?php foreach ($main as $r): ?>
                <?php
                  $p = finish_price($r);
                  $deckCardId = (int)$r['deck_card_id'];
                ?>
                <article class="rowCard" role="listitem" aria-label="Deck card <?= h((string)$r['name']) ?>">
                  <div class="rowGrid">
                    <div>
                      <?php if (!empty($r['image_small'])): ?>
                        <img class="thumb" src="<?= h((string)$r['image_small']) ?>" alt="Card image: <?= h((string)$r['name']) ?>" loading="lazy">
                      <?php else: ?>
                        <div class="thumb" role="img" aria-label="No image available"></div>
                      <?php endif; ?>
                    </div>
                    <div>
                      <div class="name"><?= h((string)$r['name']) ?></div>
                      <div class="small"><?= h((string)($r['type_line'] ?? '')) ?></div>
                      <div class="small">
                        Price: <?= $p === null ? '—' : h(money_val((string)$p)) ?>
                        <?php if (!empty($r['price_updated_at'])): ?> • Updated: <?= h((string)$r['price_updated_at']) ?><?php endif; ?>
                      </div>

                      <form action="update_deck_card.php" method="post" style="margin-top:10px;">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="deck_id" value="<?= (int)$deckId ?>">
                        <input type="hidden" name="deck_card_id" value="<?= (int)$deckCardId ?>">

                        <div class="editGrid">
                          <div>
                            <label for="qty-<?= $deckCardId ?>">Qty</label>
                            <input id="qty-<?= $deckCardId ?>" name="qty" type="number" min="0" max="999" value="<?= (int)$r['qty'] ?>">
                          </div>

                          <div>
                            <label for="section-<?= $deckCardId ?>">Section</label>
                            <select id="section-<?= $deckCardId ?>" name="section">
                              <option value="main" selected>Mainboard</option>
                              <option value="side">Sideboard</option>
                            </select>
                          </div>

                          <div>
                            <label for="finish-<?= $deckCardId ?>">Finish</label>
                            <select id="finish-<?= $deckCardId ?>" name="finish">
                              <option value="nonfoil"<?= ((string)$r['finish'] === 'nonfoil') ? ' selected' : '' ?>>Non-foil</option>
                              <option value="foil"<?= ((string)$r['finish'] === 'foil') ? ' selected' : '' ?>>Foil</option>
                              <option value="etched"<?= ((string)$r['finish'] === 'etched') ? ' selected' : '' ?>>Etched</option>
                            </select>
                          </div>

                          <div class="btnRow">
                            <button type="submit" name="action" value="update">Save</button>
                            <button type="submit" name="action" value="delete" class="dangerBtn"
                                    aria-label="Remove <?= h((string)$r['name']) ?> from deck">
                              Remove
                            </button>
                          </div>
                        </div>

                        <div class="small" style="margin-top:8px;">Updated: <?= h((string)$r['updated_at']) ?></div>
                      </form>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <h3 style="margin-top:14px;">Sideboard (<?= (int)$sideQty ?>)</h3>
          <?php if (!$side): ?>
            <p class="small">No sideboard cards yet.</p>
          <?php else: ?>
            <div class="list" role="list" aria-label="Sideboard cards">
              <?php foreach ($side as $r): ?>
                <?php
                  $p = finish_price($r);
                  $deckCardId = (int)$r['deck_card_id'];
                ?>
                <article class="rowCard" role="listitem" aria-label="Deck card <?= h((string)$r['name']) ?>">
                  <div class="rowGrid">
                    <div>
                      <?php if (!empty($r['image_small'])): ?>
                        <img class="thumb" src="<?= h((string)$r['image_small']) ?>" alt="Card image: <?= h((string)$r['name']) ?>" loading="lazy">
                      <?php else: ?>
                        <div class="thumb" role="img" aria-label="No image available"></div>
                      <?php endif; ?>
                    </div>
                    <div>
                      <div class="name"><?= h((string)$r['name']) ?></div>
                      <div class="small"><?= h((string)($r['type_line'] ?? '')) ?></div>
                      <div class="small">
                        Price: <?= $p === null ? '—' : h(money_val((string)$p)) ?>
                        <?php if (!empty($r['price_updated_at'])): ?> • Updated: <?= h((string)$r['price_updated_at']) ?><?php endif; ?>
                      </div>

                      <form action="update_deck_card.php" method="post" style="margin-top:10px;">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="deck_id" value="<?= (int)$deckId ?>">
                        <input type="hidden" name="deck_card_id" value="<?= (int)$deckCardId ?>">

                        <div class="editGrid">
                          <div>
                            <label for="qty-<?= $deckCardId ?>">Qty</label>
                            <input id="qty-<?= $deckCardId ?>" name="qty" type="number" min="0" max="999" value="<?= (int)$r['qty'] ?>">
                          </div>

                          <div>
                            <label for="section-<?= $deckCardId ?>">Section</label>
                            <select id="section-<?= $deckCardId ?>" name="section">
                              <option value="main">Mainboard</option>
                              <option value="side" selected>Sideboard</option>
                            </select>
                          </div>

                          <div>
                            <label for="finish-<?= $deckCardId ?>">Finish</label>
                            <select id="finish-<?= $deckCardId ?>" name="finish">
                              <option value="nonfoil"<?= ((string)$r['finish'] === 'nonfoil') ? ' selected' : '' ?>>Non-foil</option>
                              <option value="foil"<?= ((string)$r['finish'] === 'foil') ? ' selected' : '' ?>>Foil</option>
                              <option value="etched"<?= ((string)$r['finish'] === 'etched') ? ' selected' : '' ?>>Etched</option>
                            </select>
                          </div>

                          <div class="btnRow">
                            <button type="submit" name="action" value="update">Save</button>
                            <button type="submit" name="action" value="delete" class="dangerBtn"
                                    aria-label="Remove <?= h((string)$r['name']) ?> from deck">
                              Remove
                            </button>
                          </div>
                        </div>

                        <div class="small" style="margin-top:8px;">Updated: <?= h((string)$r['updated_at']) ?></div>
                      </form>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      </section>

      <footer>
        <div class="wrap">
          <small>School project. Not affiliated with Wizards of the Coast.</small>
        </div>
      </footer>
    </div>
  </main>

<script>
  const deckId = <?= (int)$deckId ?>;
  const csrfToken = <?= json_encode(csrf_token()) ?>;

  const form = document.getElementById('deckSearchForm');
  const statusEl = document.getElementById('searchStatus');
  const resultsEl = document.getElementById('searchResults');

  function setStatus(msg, kind=""){
    statusEl.textContent = msg;
    statusEl.className = "statusline" + (kind ? (" " + kind) : "");
  }
  function esc(s){
    return String(s ?? "").replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    }[c]));
  }
  function pickImage(card){
    if (card?.image_uris?.small || card?.image_uris?.normal) {
      return { small: card.image_uris.small || "", normal: card.image_uris.normal || card.image_uris.small || "" };
    }
    const f0 = card?.card_faces?.[0];
    if (f0?.image_uris?.small || f0?.image_uris?.normal) {
      return { small: f0.image_uris.small || "", normal: f0.image_uris.normal || f0.image_uris.small || "" };
    }
    return { small:"", normal:"" };
  }

  function resultHTML(card, defaults){
    const name = card.name ?? "Unknown";
    const typeLine = card.type_line ?? "";
    const setCode = (card.set ?? "").toUpperCase();
    const setName = card.set_name ?? "";
    const cn = card.collector_number ?? "";
    const scryfallUrl = card.scryfall_uri ?? "";
    const scryfallId = card.id ?? "";
    const oracleId = card.oracle_id ?? "";
    const img = pickImage(card);

    const usd = card?.prices?.usd ?? "";
    const usdFoil = card?.prices?.usd_foil ?? "";
    const usdEtched = card?.prices?.usd_etched ?? "";

    const thumb = img.small
      ? `<img class="thumbLg" src="${esc(img.small)}" loading="lazy" alt="Card image: ${esc(name)}">`
      : `<div class="thumbLg" role="img" aria-label="No image available"></div>`;

    const pop = img.normal
      ? `<div class="pop" aria-hidden="true"><img src="${esc(img.normal)}" alt=""></div>`
      : ``;

    return `
      <article class="result">
        <div class="resultGrid">
          <div class="thumbWrap">
            <a href="${esc(scryfallUrl)}" target="_blank" rel="noreferrer" aria-label="Open ${esc(name)} on Scryfall">
              ${thumb}
            </a>
            ${pop}
          </div>

          <div>
            <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:baseline;">
              <div class="name">${esc(name)}</div>
              <div class="small">${setCode ? esc(setCode) : ""}${cn ? " #" + esc(cn) : ""}</div>
            </div>

            ${typeLine ? `<div class="small">${esc(typeLine)}</div>` : ``}
            ${setName ? `<div class="small">Set: ${esc(setName)}</div>` : ``}

            <div class="small" style="margin-top:8px;">
              USD: ${usd ? "$" + esc(usd) : "—"} • Foil: ${usdFoil ? "$" + esc(usdFoil) : "—"} • Etched: ${usdEtched ? "$" + esc(usdEtched) : "—"}
            </div>

            <form action="add_to_deck.php" method="post" style="margin-top:10px;">
              <input type="hidden" name="csrf" value="${esc(csrfToken)}">
              <input type="hidden" name="deck_id" value="${esc(deckId)}">

              <input type="hidden" name="scryfall_id" value="${esc(scryfallId)}">
              <input type="hidden" name="oracle_id" value="${esc(oracleId)}">
              <input type="hidden" name="name" value="${esc(name)}">
              <input type="hidden" name="type_line" value="${esc(typeLine)}">
              <input type="hidden" name="set_code" value="${esc(setCode)}">
              <input type="hidden" name="set_name" value="${esc(setName)}">
              <input type="hidden" name="collector_number" value="${esc(cn)}">
              <input type="hidden" name="image_small" value="${esc(img.small)}">
              <input type="hidden" name="image_normal" value="${esc(img.normal)}">

              <input type="hidden" name="price_usd" value="${esc(usd)}">
              <input type="hidden" name="price_usd_foil" value="${esc(usdFoil)}">
              <input type="hidden" name="price_usd_etched" value="${esc(usdEtched)}">

              <div class="addControls" aria-label="Add card options">
                <div>
                  <label for="sec-${esc(scryfallId)}">Section</label>
                  <select id="sec-${esc(scryfallId)}" name="section">
                    <option value="main"${defaults.section === 'main' ? ' selected' : ''}>Mainboard</option>
                    <option value="side"${defaults.section === 'side' ? ' selected' : ''}>Sideboard</option>
                  </select>
                </div>

                <div>
                  <label for="qty-${esc(scryfallId)}">Qty</label>
                  <input id="qty-${esc(scryfallId)}" name="qty" type="number" min="1" max="999" value="${esc(defaults.qty)}" style="max-width:120px;">
                </div>

                <div>
                  <label for="fin-${esc(scryfallId)}">Finish</label>
                  <select id="fin-${esc(scryfallId)}" name="finish">
                    <option value="nonfoil"${defaults.finish === 'nonfoil' ? ' selected' : ''}>Non-foil</option>
                    <option value="foil"${defaults.finish === 'foil' ? ' selected' : ''}>Foil</option>
                    <option value="etched"${defaults.finish === 'etched' ? ' selected' : ''}>Etched</option>
                  </select>
                </div>

                <div>
                  <button type="submit">Add to deck</button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </article>
    `;
  }

  async function runSearch(query, defaults){
    setStatus("Searching Scryfall…");
    resultsEl.innerHTML = "";

    const url = new URL("https://api.scryfall.com/cards/search");
    url.searchParams.set("q", query);
    url.searchParams.set("unique", "prints");
    url.searchParams.set("order", "name");
    url.searchParams.set("dir", "auto");

    try{
      const res = await fetch(url.toString(), { headers: { "Accept": "application/json" }});
      const data = await res.json();

      if (!res.ok){
        setStatus(data?.details || "Scryfall request failed.", "bad");
        return;
      }

      const list = Array.isArray(data?.data) ? data.data.slice(0, 10) : [];
      if (!list.length){
        setStatus("No results.", "bad");
        return;
      }

      resultsEl.innerHTML = list.map(c => resultHTML(c, defaults)).join("");
      setStatus(`Found ${data.total_cards ?? list.length}. Showing ${list.length}.`, "ok");
    } catch {
      setStatus("Network error talking to Scryfall.", "bad");
    }
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    const q = (fd.get('q') || '').toString().trim();
    if (!q){
      setStatus("Enter a search query.", "bad");
      document.getElementById('q').focus();
      return;
    }
    const defaults = {
      section: (fd.get('section') || 'main').toString(),
      qty: (fd.get('qty') || '1').toString(),
      finish: (fd.get('finish') || 'nonfoil').toString()
    };
    runSearch(q, defaults);
  });
</script>
</body>
</html>