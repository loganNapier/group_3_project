<?php
// collection.php (updated nav to include Decks)
declare(strict_types=1);

require_once (__DIR__ . "/auth/config.php");
require_once (__DIR__ . "/auth/auth.php");

require_login();
$uid = (int)$_SESSION['uid'];

$user = current_user($pdo);
$loggedIn = true;

$flash = null;
if (!empty($_SESSION['flash'])) {
  $flash = (string)$_SESSION['flash'];
  unset($_SESSION['flash']);
}

$stmt = $pdo->prepare("
  SELECT
    uc.id AS collection_id,
    uc.qty,
    uc.card_condition,
    uc.card_language,
    uc.finish,
    uc.is_signed,
    uc.is_altered,
    uc.notes,
    uc.acquired_at,
    uc.purchase_price,
    uc.updated_at,

    c.name,
    c.type_line,
    c.set_code,
    c.set_name,
    c.collector_number,
    c.image_small,
    c.price_usd,
    c.price_usd_foil,
    c.price_usd_etched,
    c.price_updated_at
  FROM user_collection uc
  JOIN cards c ON c.id = uc.card_id
  WHERE uc.user_id = ?
  ORDER BY c.name ASC, uc.card_language ASC, uc.card_condition ASC, uc.finish ASC
");
$stmt->execute([$uid]);
$rows = $stmt->fetchAll();

function money_val($v): string {
  if ($v === null || $v === '') return '';
  return '$' . number_format((float)$v, 2);
}
function finish_price(array $r): ?float {
  $finish = (string)($r['finish'] ?? 'nonfoil');
  if ($finish === 'foil')   return ($r['price_usd_foil'] !== null && $r['price_usd_foil'] !== '') ? (float)$r['price_usd_foil'] : null;
  if ($finish === 'etched') return ($r['price_usd_etched'] !== null && $r['price_usd_etched'] !== '') ? (float)$r['price_usd_etched'] : null;
  return ($r['price_usd'] !== null && $r['price_usd'] !== '') ? (float)$r['price_usd'] : null;
}

$totalQty = 0;
$totalPaid = 0.0;
$totalPaidKnown = 0;

$totalEst = 0.0;
$totalEstKnown = 0;

foreach ($rows as $r) {
  $qty = (int)$r['qty'];
  $totalQty += $qty;

  if ($r['purchase_price'] !== null && $r['purchase_price'] !== '') {
    $totalPaid += ((float)$r['purchase_price']) * $qty;
    $totalPaidKnown += $qty;
  }

  $p = finish_price($r);
  if ($p !== null && $p >= 0) {
    $totalEst += $p * $qty;
    $totalEstKnown += $qty;
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>My Collection</title>
  <link rel="stylesheet" href="./css/collection.css" />
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
            <li><a href="collection.php" aria-current="page">My collection</a></li>
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
      <section class="card" aria-labelledby="title">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:end;">
          <div>
            <h1 id="title">My Collection</h1>
            <p>Signed in as <span class="pill"><?= h($user ? $user['username'] : 'User') ?></span></p>
          </div>
          <p class="small" style="margin:0;">Tip: Add cards from <a href="cards.php">Browse cards</a> or <a href="batch_add.php">Batch add</a>.</p>
        </div>

        <?php if ($flash): ?>
          <div class="statusline ok" role="status" aria-live="polite"><?= h($flash) ?></div>
        <?php endif; ?>

        <section class="summary" aria-label="Collection totals">
          <div class="summaryItem">
            <h2>Total cards</h2>
            <div class="big"><?= (int)$totalQty ?></div>
            <div class="small">Sum of quantities across your collection.</div>
          </div>

          <div class="summaryItem">
            <h2>Estimated value (Scryfall)</h2>
            <div class="big"><?= h(money_val((string)$totalEst)) ?></div>
            <div class="small">
              Based on stored Scryfall price for each row’s finish.
              <?php if ($totalQty > 0): ?>
                (Price known for <?= (int)$totalEstKnown ?> / <?= (int)$totalQty ?> copies.)
              <?php endif; ?>
            </div>
          </div>

          <div class="summaryItem">
            <h2>Total paid (your entries)</h2>
            <div class="big"><?= h(money_val((string)$totalPaid)) ?></div>
            <div class="small">
              Based on your “Paid” field.
              <?php if ($totalQty > 0): ?>
                (Paid known for <?= (int)$totalPaidKnown ?> / <?= (int)$totalQty ?> copies.)
              <?php endif; ?>
            </div>
          </div>
        </section>

        <?php if (!$rows): ?>
          <p style="margin-top:12px;">No cards in your collection yet.</p>
        <?php else: ?>
          <div class="tableWrap" role="region" aria-label="Editable collection table" tabindex="0">
            <table>
              <thead>
                <tr>
                  <th scope="col">Card</th>
                  <th scope="col">Qty</th>
                  <th scope="col">Condition</th>
                  <th scope="col">Language</th>
                  <th scope="col">Finish</th>
                  <th scope="col">Signed</th>
                  <th scope="col">Altered</th>
                  <th scope="col">Acquired</th>
                  <th scope="col">Paid</th>
                  <th scope="col">Notes</th>
                  <th scope="col">Scryfall price</th>
                  <th scope="col">Actions</th>
                </tr>
              </thead>

              <tbody>
                <?php foreach ($rows as $r): ?>
                  <?php
                    $itemId = (int)$r['collection_id'];
                    $p = finish_price($r);
                  ?>
                  <tr>
                    <td>
                      <div class="cellCard">
                        <?php if (!empty($r['image_small'])): ?>
                          <img class="thumb" src="<?= h((string)$r['image_small']) ?>"
                               alt="Card image: <?= h((string)$r['name']) ?>" loading="lazy">
                        <?php else: ?>
                          <div class="thumb" role="img" aria-label="No image available"></div>
                        <?php endif; ?>

                        <div>
                          <div style="font-weight:900;"><?= h((string)$r['name']) ?></div>
                          <div class="small"><?= h((string)($r['type_line'] ?? '')) ?></div>
                          <div class="small">
                            <?= h((string)($r['set_name'] ?? '')) ?>
                            <?php if (!empty($r['set_code'])): ?> (<?= h((string)$r['set_code']) ?>)<?php endif; ?>
                            <?php if (!empty($r['collector_number'])): ?> #<?= h((string)$r['collector_number']) ?><?php endif; ?>
                          </div>
                          <?php if (!empty($r['price_updated_at'])): ?>
                            <div class="small">Price updated: <?= h((string)$r['price_updated_at']) ?></div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>

                    <td colspan="11" style="padding:0;">
                      <form action="update_collection_item.php" method="post" style="padding:10px;">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="collection_id" value="<?= $itemId ?>">

                        <div style="display:grid;grid-template-columns: 90px 120px 160px 140px 120px 120px 140px 140px 1.2fr 170px 170px;gap:10px;align-items:start;">
                          <div>
                            <label for="qty-<?= $itemId ?>" class="srOnly">Quantity</label>
                            <input id="qty-<?= $itemId ?>" name="qty" type="number" min="0" max="999" value="<?= (int)$r['qty'] ?>">
                          </div>

                          <div>
                            <label for="cond-<?= $itemId ?>" class="srOnly">Condition</label>
                            <select id="cond-<?= $itemId ?>" name="card_condition">
                              <?php foreach (['NM','LP','MP','HP','DMG'] as $c): ?>
                                <option value="<?= h($c) ?>"<?= ((string)$r['card_condition'] === $c) ? ' selected' : '' ?>><?= h($c) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>

                          <div>
                            <label for="lang-<?= $itemId ?>" class="srOnly">Language</label>
                            <input id="lang-<?= $itemId ?>" name="card_language" maxlength="32" value="<?= h((string)$r['card_language']) ?>">
                          </div>

                          <div>
                            <label for="finish-<?= $itemId ?>" class="srOnly">Finish</label>
                            <select id="finish-<?= $itemId ?>" name="finish">
                              <option value="nonfoil"<?= ((string)$r['finish'] === 'nonfoil') ? ' selected' : '' ?>>Non-foil</option>
                              <option value="foil"<?= ((string)$r['finish'] === 'foil') ? ' selected' : '' ?>>Foil</option>
                              <option value="etched"<?= ((string)$r['finish'] === 'etched') ? ' selected' : '' ?>>Etched</option>
                            </select>
                          </div>

                          <div>
                            <label style="display:flex;gap:8px;align-items:center;color:var(--muted);">
                              <input type="checkbox" name="is_signed" value="1"<?= ((int)$r['is_signed'] === 1) ? ' checked' : '' ?>>
                              <span>Signed</span>
                            </label>
                          </div>

                          <div>
                            <label style="display:flex;gap:8px;align-items:center;color:var(--muted);">
                              <input type="checkbox" name="is_altered" value="1"<?= ((int)$r['is_altered'] === 1) ? ' checked' : '' ?>>
                              <span>Altered</span>
                            </label>
                          </div>

                          <div>
                            <label for="acq-<?= $itemId ?>" class="srOnly">Acquired date</label>
                            <input id="acq-<?= $itemId ?>" name="acquired_at" type="date" value="<?= h((string)($r['acquired_at'] ?? '')) ?>">
                          </div>

                          <div>
                            <label for="paid-<?= $itemId ?>" class="srOnly">Purchase price</label>
                            <input id="paid-<?= $itemId ?>" name="purchase_price" type="number" min="0" step="0.01" inputmode="decimal"
                                   value="<?= h((string)($r['purchase_price'] ?? '')) ?>">
                          </div>

                          <div>
                            <label for="notes-<?= $itemId ?>" class="srOnly">Notes</label>
                            <textarea id="notes-<?= $itemId ?>" name="notes" maxlength="500"><?= h((string)($r['notes'] ?? '')) ?></textarea>
                            <div class="small">Max 500 characters.</div>
                          </div>

                          <div class="small" aria-label="Scryfall price for this finish">
                            <?php if ($p !== null): ?>
                              <strong><?= h(money_val((string)$p)) ?></strong>
                              <div class="small">(<?= h((string)$r['finish']) ?>)</div>
                            <?php else: ?>
                              No price listed
                            <?php endif; ?>
                          </div>

                          <div>
                            <div class="btnRow">
                              <button type="submit" name="action" value="update">Save</button>
                              <button class="dangerBtn" type="submit" name="action" value="delete"
                                      aria-label="Remove <?= h((string)$r['name']) ?> from collection">
                                Remove
                              </button>
                            </div>
                            <div class="small" style="margin-top:8px;">Updated: <?= h((string)$r['updated_at']) ?></div>
                          </div>
                        </div>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <p class="small" style="margin-top:10px;">Tip: In the table region, you can scroll horizontally on small screens.</p>
        <?php endif; ?>
      </section>
    </div>
  </main>

  <footer>
    <div class="wrap">
      <small>School project. Not affiliated with Wizards of the Coast.</small>
    </div>
  </footer>
</body>
</html>
