<?php
// collection.php
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

// --- Summary totals (always over full collection, unfiltered) ---
$stmt = $pdo->prepare("
  SELECT
    uc.qty,
    uc.purchase_price,
    uc.finish,
    c.price_usd,
    c.price_usd_foil,
    c.price_usd_etched
  FROM user_collection uc
  JOIN cards c ON c.id = uc.card_id
  WHERE uc.user_id = ?
");
$stmt->execute([$uid]);
$allRows = $stmt->fetchAll();

function money_val($v): string {
  if ($v === null || $v === '') return '';
  return '$' . number_format((float)$v, 2);
}
function finish_price(array $r): ?float {
  $finish = (string)($r['finish'] ?? 'nonfoil');
  if ($finish === 'foil')   return ($r['price_usd_foil']   !== null && $r['price_usd_foil']   !== '') ? (float)$r['price_usd_foil']   : null;
  if ($finish === 'etched') return ($r['price_usd_etched'] !== null && $r['price_usd_etched'] !== '') ? (float)$r['price_usd_etched'] : null;
  return ($r['price_usd'] !== null && $r['price_usd'] !== '') ? (float)$r['price_usd'] : null;
}

$totalQty = 0; $totalPaid = 0.0; $totalPaidKnown = 0;
$totalEst = 0.0; $totalEstKnown = 0;
foreach ($allRows as $r) {
  $qty = (int)$r['qty'];
  $totalQty += $qty;
  if ($r['purchase_price'] !== null && $r['purchase_price'] !== '') {
    $totalPaid += ((float)$r['purchase_price']) * $qty;
    $totalPaidKnown += $qty;
  }
  $p = finish_price($r);
  if ($p !== null && $p >= 0) { $totalEst += $p * $qty; $totalEstKnown += $qty; }
}

// --- Distinct sets for filter dropdown ---
$setsStmt = $pdo->prepare("
  SELECT DISTINCT c.set_code, c.set_name
  FROM user_collection uc
  JOIN cards c ON c.id = uc.card_id
  WHERE uc.user_id = ?
  ORDER BY c.set_name ASC
");
$setsStmt->execute([$uid]);
$sets = $setsStmt->fetchAll();

$hasCards = $totalQty > 0;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>My Collection</title>
  <link rel="stylesheet" href="./css/collection.css" />
</head>
<body data-csrf="<?= h(csrf_token()) ?>">
  <a class="skip" href="#main">Skip to main content</a>

  <?php require_once __DIR__ . "/partials/header.php"; ?>

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
              Based on stored Scryfall price for each row's finish.
              <?php if ($totalQty > 0): ?>
                (Price known for <?= (int)$totalEstKnown ?> / <?= (int)$totalQty ?> copies.)
              <?php endif; ?>
            </div>
          </div>
          <div class="summaryItem">
            <h2>Total paid (your entries)</h2>
            <div class="big"><?= h(money_val((string)$totalPaid)) ?></div>
            <div class="small">
              Based on your "Paid" field.
              <?php if ($totalQty > 0): ?>
                (Paid known for <?= (int)$totalPaidKnown ?> / <?= (int)$totalQty ?> copies.)
              <?php endif; ?>
            </div>
          </div>
        </section>
      </section>

      <?php if (!$hasCards): ?>
        <section class="card" style="margin-top:12px;">
          <p>No cards in your collection yet.</p>
        </section>
      <?php else: ?>

      <!-- Filter bar -->
      <section class="card" style="margin-top:12px;" aria-labelledby="filterTitle">
        <h2 id="filterTitle" class="srOnly">Filter collection</h2>
        <div id="filterBar" class="filters">
          <div class="filterField">
            <label for="f_search">Card name</label>
            <input id="f_search" type="text" placeholder="e.g., Lightning Bolt" maxlength="200" autocomplete="off" />
          </div>

          <div class="filterField">
            <label for="f_set">Set</label>
            <select id="f_set">
              <option value="">All sets</option>
              <?php foreach ($sets as $s): ?>
                <option value="<?= h((string)$s['set_code']) ?>">
                  <?= h((string)$s['set_name']) ?> (<?= h(strtoupper((string)$s['set_code'])) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="filterField">
            <label for="f_condition">Condition</label>
            <select id="f_condition">
              <option value="">All conditions</option>
              <option value="NM">NM</option>
              <option value="LP">LP</option>
              <option value="MP">MP</option>
              <option value="HP">HP</option>
              <option value="DMG">DMG</option>
            </select>
          </div>

          <div class="filterField">
            <label for="f_finish">Finish</label>
            <select id="f_finish">
              <option value="">All finishes</option>
              <option value="nonfoil">Non-foil</option>
              <option value="foil">Foil</option>
              <option value="etched">Etched</option>
            </select>
          </div>

          <div class="filterField">
            <label for="f_per_page">Show</label>
            <select id="f_per_page">
              <option value="20" selected>20 per page</option>
              <option value="50">50 per page</option>
              <option value="100">100 per page</option>
            </select>
          </div>

          <div class="rowActions" style="align-self:flex-end;">
            <button type="button" id="filterBtn">Filter</button>
            <button type="button" id="clearFilterBtn" class="btnSecondary">Clear</button>
          </div>
        </div>
        <div id="collectionStatus" class="statusline" role="status" aria-live="polite"></div>
      </section>

      <!-- Results table -->
      <section class="card" style="margin-top:12px;">
        <div class="tableWrap" role="region" aria-label="Editable collection table" tabindex="0">
          <table id="collectionTable">
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
            <tbody id="collectionBody">
              <!-- Rows injected by JS -->
            </tbody>
          </table>
        </div>
        <div id="loadMoreWrap" style="margin-top:12px;"></div>
        <p class="small" style="margin-top:10px;">Tip: In the table region, you can scroll horizontally on small screens.</p>
      </section>

      <?php endif; ?>
    </div>
  </main>

  <footer>
    <div class="wrap">
      <small>School project. Not affiliated with Wizards of the Coast.</small>
    </div>
  </footer>

<script>
  const CSRF = document.body.dataset.csrf;

  const statusEl    = document.getElementById('collectionStatus');
  const tbody       = document.getElementById('collectionBody');
  const loadMoreWrap = document.getElementById('loadMoreWrap');

  const fSearch    = document.getElementById('f_search');
  const fSet       = document.getElementById('f_set');
  const fCondition = document.getElementById('f_condition');
  const fFinish    = document.getElementById('f_finish');
  const fPerPage   = document.getElementById('f_per_page');

  // --- State ---
  let currentOffset = 0;
  let currentTotal  = 0;
  let isLoading     = false;

  function getFilters() {
    return {
      search:    fSearch.value.trim(),
      set:       fSet.value,
      condition: fCondition.value,
      finish:    fFinish.value,
      per_page:  fPerPage.value,
    };
  }

  function setStatus(msg, kind = '') {
    statusEl.textContent = msg;
    statusEl.className = 'statusline' + (kind ? ' ' + kind : '');
  }

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    }[c]));
  }

  function finishPrice(r) {
    const finish = r.finish ?? 'nonfoil';
    if (finish === 'foil'  && r.price_usd_foil   != null && r.price_usd_foil   !== '') return parseFloat(r.price_usd_foil);
    if (finish === 'etched'&& r.price_usd_etched != null && r.price_usd_etched !== '') return parseFloat(r.price_usd_etched);
    if (r.price_usd != null && r.price_usd !== '') return parseFloat(r.price_usd);
    return null;
  }

  function moneyVal(v) {
    if (v === null || v === '') return '';
    return '$' + parseFloat(v).toFixed(2);
  }

  function rowHTML(r) {
    const itemId = esc(r.collection_id);
    const p = finishPrice(r);
    const priceDisplay = p !== null
      ? `<strong>${esc(moneyVal(String(p)))}</strong><div class="small">(${esc(r.finish)})</div>`
      : 'No price listed';

    return `
      <tr>
        <td>
          <div class="cellCard">
            ${r.image_small
              ? `<img class="thumb" src="${esc(r.image_small)}" alt="Card image: ${esc(r.name)}" loading="lazy">`
              : `<div class="thumb" role="img" aria-label="No image available"></div>`
            }
            <div>
              <div style="font-weight:900;">${esc(r.name)}</div>
              <div class="small">${esc(r.type_line ?? '')}</div>
              <div class="small">
                ${esc(r.set_name ?? '')}
                ${r.set_code       ? ' (' + esc(r.set_code.toUpperCase()) + ')' : ''}
                ${r.collector_number ? ' #' + esc(r.collector_number) : ''}
              </div>
              ${r.price_updated_at ? `<div class="small">Price updated: ${esc(r.price_updated_at)}</div>` : ''}
            </div>
          </div>
        </td>

        <td colspan="11" style="padding:0;">
          <form action="update_collection_item.php" method="post" style="padding:10px;">
            <input type="hidden" name="csrf" value="${esc(CSRF)}">
            <input type="hidden" name="collection_id" value="${itemId}">

            <div style="display:grid;grid-template-columns:90px 120px 160px 140px 120px 120px 140px 140px 1.2fr 170px 170px;gap:10px;align-items:start;">
              <div>
                <label for="qty-${itemId}" class="srOnly">Quantity</label>
                <input id="qty-${itemId}" name="qty" type="number" min="0" max="999" value="${esc(r.qty)}">
              </div>
              <div>
                <label for="cond-${itemId}" class="srOnly">Condition</label>
                <select id="cond-${itemId}" name="card_condition">
                  ${['NM','LP','MP','HP','DMG'].map(c =>
                    `<option value="${c}"${r.card_condition === c ? ' selected' : ''}>${c}</option>`
                  ).join('')}
                </select>
              </div>
              <div>
                <label for="lang-${itemId}" class="srOnly">Language</label>
                <input id="lang-${itemId}" name="card_language" maxlength="32" value="${esc(r.card_language ?? '')}">
              </div>
              <div>
                <label for="finish-${itemId}" class="srOnly">Finish</label>
                <select id="finish-${itemId}" name="finish">
                  ${['nonfoil','foil','etched'].map(f =>
                    `<option value="${f}"${r.finish === f ? ' selected' : ''}>${f.charAt(0).toUpperCase()+f.slice(1)}</option>`
                  ).join('')}
                </select>
              </div>
              <div>
                <label style="display:flex;gap:8px;align-items:center;color:var(--muted);">
                  <input type="checkbox" name="is_signed" value="1"${parseInt(r.is_signed) === 1 ? ' checked' : ''}>
                  <span>Signed</span>
                </label>
              </div>
              <div>
                <label style="display:flex;gap:8px;align-items:center;color:var(--muted);">
                  <input type="checkbox" name="is_altered" value="1"${parseInt(r.is_altered) === 1 ? ' checked' : ''}>
                  <span>Altered</span>
                </label>
              </div>
              <div>
                <label for="acq-${itemId}" class="srOnly">Acquired date</label>
                <input id="acq-${itemId}" name="acquired_at" type="date" value="${esc(r.acquired_at ?? '')}">
              </div>
              <div>
                <label for="paid-${itemId}" class="srOnly">Purchase price</label>
                <input id="paid-${itemId}" name="purchase_price" type="number" min="0" step="0.01" inputmode="decimal" value="${esc(r.purchase_price ?? '')}">
              </div>
              <div>
                <label for="notes-${itemId}" class="srOnly">Notes</label>
                <textarea id="notes-${itemId}" name="notes" maxlength="500">${esc(r.notes ?? '')}</textarea>
                <div class="small">Max 500 characters.</div>
              </div>
              <div class="small" aria-label="Scryfall price for this finish">
                ${priceDisplay}
              </div>
              <div>
                <div class="btnRow">
                  <button type="submit" name="action" value="update">Save</button>
                  <button class="dangerBtn" type="submit" name="action" value="delete"
                          aria-label="Remove ${esc(r.name)} from collection">Remove</button>
                </div>
                <div class="small" style="margin-top:8px;">Updated: ${esc(r.updated_at)}</div>
              </div>
            </div>
          </form>
        </td>
      </tr>
    `;
  }

  async function loadRows(reset = true) {
    if (isLoading) return;
    isLoading = true;

    if (reset) {
      currentOffset = 0;
      currentTotal  = 0;
      tbody.innerHTML = '';
      loadMoreWrap.innerHTML = '';
    }

    const filters = getFilters();
    const params  = new URLSearchParams({
      search:    filters.search,
      set:       filters.set,
      condition: filters.condition,
      finish:    filters.finish,
      per_page:  filters.per_page,
      offset:    currentOffset,
    });

    setStatus('Loading…');

    try {
      const res  = await fetch('collection_api.php?' + params.toString());
      const data = await res.json();

      if (!res.ok) {
        setStatus('Failed to load collection.', 'bad');
        isLoading = false;
        return;
      }

      currentTotal   = data.total;
      currentOffset += data.rows.length;

      if (reset && data.rows.length === 0) {
        setStatus('No cards match your filters.', 'bad');
        isLoading = false;
        return;
      }

      tbody.insertAdjacentHTML('beforeend', data.rows.map(rowHTML).join(''));
      setStatus(`Showing ${currentOffset} of ${currentTotal} card${currentTotal !== 1 ? 's' : ''}.`, 'ok');

      loadMoreWrap.innerHTML = '';
      if (data.has_more) {
        loadMoreWrap.innerHTML = `<button type="button" id="loadMoreBtn">Load more</button>`;
        document.getElementById('loadMoreBtn').addEventListener('click', () => loadRows(false));
      }

    } catch (e) {
      setStatus('Network error loading collection.', 'bad');
      console.error(e);
    }

    isLoading = false;
  }

  // Wire up filter controls
  document.getElementById('filterBtn').addEventListener('click', () => loadRows(true));

  document.getElementById('clearFilterBtn').addEventListener('click', () => {
    fSearch.value    = '';
    fSet.value       = '';
    fCondition.value = '';
    fFinish.value    = '';
    fPerPage.value   = '20';
    loadRows(true);
  });

  // Allow Enter in the name search field
  fSearch.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') loadRows(true);
  });

  // Re-run immediately when per-page changes
  fPerPage.addEventListener('change', () => loadRows(true));

  // Load on page open
  loadRows(true);
</script>
</body>
<?php require_once __DIR__ . "/partials/footer.php"; ?>
</html>