<?php
// deck.php (editable deck cards + totals + inline Scryfall search)

declare(strict_types=1);

require_once (__DIR__ . "/auth/config.php");
require_once (__DIR__ . "/auth/auth.php");

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
    c.price_updated_at,

    COALESCE(owned.owned_qty, 0) AS owned_qty
  FROM deck_cards dc
  JOIN cards c ON c.id = dc.card_id
  LEFT JOIN (
    SELECT card_id, finish, SUM(qty) AS owned_qty
    FROM user_collection
    WHERE user_id = ?
    GROUP BY card_id, finish
  ) owned ON owned.card_id = dc.card_id AND owned.finish = dc.finish
  WHERE dc.deck_id = ?
  ORDER BY dc.section ASC, c.name ASC
");
$stmt->execute([$uid, $deckId]);
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
  <link rel="stylesheet" href="./css/deck.css" />
</head>
<body data-csrf="<?= h(csrf_token()) ?>" data-deck-id="<?= (int)$deckId ?>">
  <a class="skip" href="#main">Skip to main content</a>

<?php
$loggedIn = true;
$activeNav = "decks";
include 'partials/header.php';
?>
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

        <form action="/deck_config/delete_deck.php" method="post" style="margin-top:12px;">
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

          <!-- FIX: replaced <form> with <div> to prevent GET navigation wiping #searchResults -->
          <div id="deckSearchForm" class="addControls">
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

            <!-- FIX: type="button" so pressing Enter or clicking never triggers form submission -->
            <button type="button" id="deckSearchBtn">Search</button>

            <a class="btnSecondary" href="deck_config/import_deck.php?deck_id=<?= (int)$deckId ?>" style="text-decoration:none;padding:10px 12px;border-radius:12px;border:1px solid var(--border);">
              Import decklist
            </a>
          </div>

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
                      <div class="name">
                        <?= h((string)$r['name']) ?>
                        <?php if ((int)$r['owned_qty'] >= (int)$r['qty']): ?>
                          <span class="owned-icon" title="Owned in collection">✓</span>
                        <?php endif; ?>
                      </div>
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
                      <div class="name">
                        <?= h((string)$r['name']) ?>
                        <?php if ((int)$r['owned_qty'] >= (int)$r['qty']): ?>
                          <span class="owned-icon" title="Owned in collection">✓</span>
                        <?php endif; ?>
                      </div>
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

      <?php include 'partials/footer.php'; ?>
    </div>
  </main>

  <!-- Import CSRF Token and Deck ID -->
  <script>
  window.CSRF_TOKEN = document.body.dataset.csrf;
  window.DECK_ID = document.body.dataset.deckId;
  </script>
  <script src="./js/deck.js"></script>

</body>
</html>
