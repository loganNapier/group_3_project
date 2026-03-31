<?php
// cards.php (Scryfall search) — updated nav to include Decks when logged in
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
  <title>Browse Cards (Scryfall)</title>
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
    input, select, textarea{
      width:100%; padding:10px 11px; border-radius:12px;
      border:1px solid var(--border); background:#0c121a; color:var(--text);
    }
    textarea{ min-height:90px; resize:vertical; }
    button{
      border:1px solid transparent; border-radius:12px; padding:10px 12px;
      cursor:pointer; font-weight:900; background:var(--accent); color:#04111c;
    }
    .btnSecondary{ background:transparent; color:var(--text); border-color:var(--border); font-weight:800; }

    .statusline{ padding:10px 12px; border-radius:12px; border:1px solid var(--border); margin-top:10px; }
    .statusline.ok{ border-color:rgba(114,230,166,.35); color:var(--ok); background:rgba(114,230,166,.06); }
    .statusline.bad{ border-color:rgba(255,107,107,.35); color:var(--danger); background:rgba(255,107,107,.06); }

    .filters{ display:grid; grid-template-columns:1fr; gap:10px; }
    .filterField{ display:flex; flex-direction:column; min-width:0; }
    .filterField .help{ margin-top:6px; min-height:1.25em; font-size:.92rem; color:var(--muted); }
    .filterField .help:empty{ visibility:hidden; }
    .rowActions{ display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
    .rowActions .help{ min-height:1.25em; margin-top:6px; visibility:hidden; }
    @media (min-width: 900px){ .filters{ grid-template-columns:1.2fr 1fr 1fr auto; align-items:start; } }

    .results{ margin-top:12px; display:grid; grid-template-columns:1fr; gap:10px; }
    .result{ border:1px solid var(--border); border-radius:14px; padding:12px; background:rgba(255,255,255,.02); }
    .resultGrid{ display:grid; grid-template-columns:92px 1fr; gap:12px; align-items:start; }

    .thumbWrap{ position:relative; display:inline-block; }
    .thumb{ width:92px; height:auto; border-radius:10px; border:1px solid var(--border); background:#0c121a; display:block; }
    .pop{
      position:absolute; left:100%; top:0; margin-left:12px; width:240px;
      display:none; border:1px solid var(--border); border-radius:14px;
      background:linear-gradient(180deg,var(--panel),var(--panel2));
      padding:10px; box-shadow:0 18px 40px rgba(0,0,0,.45); z-index:5;
    }
    .pop img{ width:100%; height:auto; border-radius:10px; border:1px solid var(--border); display:block; }
    .thumbWrap:hover .pop, .thumbWrap:focus-within .pop{ display:block; }
    @media (max-width: 900px){ .pop{ display:none !important; } }

    .name{ font-weight:900; }
    .meta{ color:var(--muted); font-size:.95rem; margin-top:6px; }
    .small{ font-size:.92rem; color:var(--muted); }
    .pill{ display:inline-block; padding:6px 10px; border:1px solid var(--border); border-radius:999px; color:var(--muted); font-size:.9rem; }

    .priceRow{ display:flex; gap:10px; flex-wrap:wrap; margin-top:8px; }
    .priceTag{ display:inline-block; padding:6px 10px; border:1px solid var(--border); border-radius:999px; color:var(--muted); font-size:.92rem; }
    .priceTag strong{ color:var(--text); }

    .addForm{ margin-top:10px; display:grid; grid-template-columns:120px 140px; gap:10px; align-items:end; }
    .addForm .full{ grid-column:1 / -1; }
    .checkRow{ display:flex; gap:14px; flex-wrap:wrap; }
    .checkRow label{ margin:0; display:flex; gap:8px; align-items:center; color:var(--muted); font-size:.95rem; }
    @media (min-width: 900px){ .addForm{ grid-template-columns:130px 160px 1fr; } .addForm .full{ grid-column:auto; } }
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
            <li><a href="cards.php" aria-current="page">Browse cards</a></li>
            <?php if ($loggedIn): ?>
              <li><a href="collection.php">My collection</a></li>
              <li><a href="batch_add.php">Batch add</a></li>
              <li><a href="decks.php">Decks</a></li>
              <li><a href="logout.php">Logout</a></li>
            <?php else: ?>
              <li><a href="index.php#login">Login</a></li>
              <li><a href="index.php#register">Register</a></li>
            <?php endif; ?>
          </ul>
        </nav>
      </div>
    </div>
  </header>

  <main id="main">
    <div class="wrap">
      <section class="card" aria-labelledby="title">
        <h1 id="title">Browse cards (Scryfall)</h1>
        <p>
          Search Scryfall and show images and prices.
          <?php if ($loggedIn): ?>
            You are signed in as <span class="pill"><?= h($user['username']) ?></span>.
          <?php else: ?>
            Log in to add cards to your collection.
          <?php endif; ?>
        </p>

        <?php if ($flash): ?>
          <div class="statusline ok" role="status" aria-live="polite"><?= h($flash) ?></div>
        <?php endif; ?>

        <form id="searchForm" class="filters" action="#" method="get" novalidate>
          <div class="filterField">
            <label for="q">Search</label>
            <input id="q" name="q" maxlength="200" autocomplete="off" placeholder="e.g., t:elf set:khm" />
            <div class="help">Uses Scryfall advanced search syntax.</div>
          </div>

          <div class="filterField">
            <label for="unique">Unique</label>
            <select id="unique" name="unique">
              <option value="cards">Cards</option>
              <option value="prints">Prints</option>
              <option value="art">Art</option>
            </select>
            <div class="help"></div>
          </div>

          <div class="filterField">
            <label for="order">Order</label>
            <select id="order" name="order">
              <option value="name">Name</option>
              <option value="released">Released</option>
              <option value="set">Set</option>
              <option value="rarity">Rarity</option>
              <option value="usd">Price (USD)</option>
            </select>
            <div class="help"></div>
          </div>

          <div class="rowActions">
            <button type="submit">Search</button>
            <button type="button" class="btnSecondary" id="clearBtn">Clear</button>
            <div class="help"></div>
          </div>
        </form>

        <div id="status" class="statusline" role="status" aria-live="polite">Ready.</div>
      </section>

      <section class="card" style="margin-top:12px;" aria-labelledby="resultsTitle">
        <h2 id="resultsTitle">Results</h2>
        <p class="small">Shows up to 20 results per search.</p>
        <div id="results" class="results" aria-label="Search results"></div>
      </section>
    </div>
  </main>

<script>
  const loggedIn = <?= $loggedIn ? 'true' : 'false' ?>;
  const csrfToken = <?= $loggedIn ? json_encode(csrf_token()) : '""' ?>;

  const form = document.getElementById('searchForm');
  const qEl = document.getElementById('q');
  const uniqueEl = document.getElementById('unique');
  const orderEl = document.getElementById('order');
  const statusEl = document.getElementById('status');
  const resultsEl = document.getElementById('results');
  const clearBtn = document.getElementById('clearBtn');

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
  function priceText(label, value){
    if (!value) return "";
    return `<span class="priceTag"><strong>${esc(label)}:</strong> $${esc(value)}</span>`;
  }

  function resultHTML(card){
    const name = card.name ?? "Unknown";
    const typeLine = card.type_line ?? "";
    const setCode = (card.set ?? "").toUpperCase();
    const setName = card.set_name ?? "";
    const cn = card.collector_number ?? "";
    const scryfallUrl = card.scryfall_uri ?? "";
    const oracle = card.oracle_text ?? "";
    const scryfallId = card.id ?? "";
    const oracleId = card.oracle_id ?? "";
    const img = pickImage(card);

    const usd = card?.prices?.usd ?? "";
    const usdFoil = card?.prices?.usd_foil ?? "";
    const usdEtched = card?.prices?.usd_etched ?? "";

    const imgThumb = img.small
      ? `<img class="thumb" src="${esc(img.small)}" loading="lazy" alt="Card image: ${esc(name)}">`
      : `<div class="thumb" role="img" aria-label="No image available"></div>`;

    const pop = img.normal
      ? `<div class="pop" aria-hidden="true"><img src="${esc(img.normal)}" alt=""></div>`
      : ``;

    let addBlock = `<div class="small">Log in to add this card to your collection.</div>`;
    if (loggedIn) {
      addBlock = `
        <form class="addForm" action="add_to_collection.php" method="post">
          <input type="hidden" name="csrf" value="${esc(csrfToken)}">

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

          <div>
            <label for="qty-${esc(scryfallId)}">Qty</label>
            <input id="qty-${esc(scryfallId)}" name="qty" type="number" min="1" max="999" value="1">
          </div>

          <div>
            <label for="cond-${esc(scryfallId)}">Condition</label>
            <select id="cond-${esc(scryfallId)}" name="card_condition">
              <option value="NM">NM</option><option value="LP">LP</option><option value="MP">MP</option>
              <option value="HP">HP</option><option value="DMG">DMG</option>
            </select>
          </div>

          <div>
            <label for="lang-${esc(scryfallId)}">Language</label>
            <input id="lang-${esc(scryfallId)}" name="card_language" value="English" maxlength="32">
          </div>

          <div>
            <label for="finish-${esc(scryfallId)}">Finish</label>
            <select id="finish-${esc(scryfallId)}" name="finish">
              <option value="nonfoil">Non-foil</option>
              <option value="foil">Foil</option>
              <option value="etched">Etched</option>
            </select>
          </div>

          <div class="full checkRow" aria-label="Card flags">
            <label><input type="checkbox" name="is_signed" value="1"> Signed</label>
            <label><input type="checkbox" name="is_altered" value="1"> Altered</label>
          </div>

          <div class="full">
            <label for="notes-${esc(scryfallId)}">Notes (optional)</label>
            <textarea id="notes-${esc(scryfallId)}" name="notes" maxlength="500"></textarea>
          </div>

          <div>
            <label for="acq-${esc(scryfallId)}">Acquired date</label>
            <input id="acq-${esc(scryfallId)}" name="acquired_at" type="date">
          </div>

          <div>
            <label for="paid-${esc(scryfallId)}">Purchase price (USD)</label>
            <input id="paid-${esc(scryfallId)}" name="purchase_price" type="number" min="0" step="0.01" placeholder="0.00" inputmode="decimal">
          </div>

          <div class="full rowActions">
            <button type="submit">Add to my collection</button>
          </div>
        </form>
      `;
    }

    return `
      <article class="result">
        <div class="resultGrid">
          <div class="thumbWrap">
            <a href="${esc(scryfallUrl)}" target="_blank" rel="noreferrer" aria-label="Open ${esc(name)} on Scryfall">
              ${imgThumb}
            </a>
            ${pop}
          </div>

          <div>
            <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:baseline;">
              <div class="name">${esc(name)}</div>
              <div class="small">${setCode ? esc(setCode) : ""}${cn ? " #" + esc(cn) : ""}</div>
            </div>

            ${typeLine ? `<div class="meta">${esc(typeLine)}</div>` : ``}
            ${setName ? `<div class="small">Set: ${esc(setName)}</div>` : ``}

            <div class="priceRow" aria-label="Prices from Scryfall">
              ${priceText("USD", usd)}
              ${priceText("Foil", usdFoil)}
              ${priceText("Etched", usdEtched)}
              ${(!usd && !usdFoil && !usdEtched) ? `<span class="priceTag">No price listed</span>` : ``}
            </div>

            ${oracle ? `<details style="margin-top:8px;">
              <summary>Rules text</summary>
              <div class="meta">${esc(oracle)}</div>
            </details>` : ``}

            <div style="margin-top:10px;">${addBlock}</div>
          </div>
        </div>
      </article>
    `;
  }

  async function runSearch(){
    const q = qEl.value.trim();
    if (!q){
      resultsEl.innerHTML = "";
      setStatus("Enter a search query.", "bad");
      qEl.focus();
      return;
    }

    setStatus("Searching Scryfall…");
    resultsEl.innerHTML = "";

    const url = new URL("https://api.scryfall.com/cards/search");
    url.searchParams.set("q", q);
    url.searchParams.set("unique", uniqueEl.value);
    url.searchParams.set("order", orderEl.value);
    url.searchParams.set("dir", "auto");

    try{
      const res = await fetch(url.toString(), { headers: { "Accept": "application/json" }});
      const data = await res.json();

      if (!res.ok){
        setStatus(data?.details || "Scryfall request failed.", "bad");
        return;
      }

      const list = Array.isArray(data?.data) ? data.data.slice(0, 20) : [];
      if (!list.length){
        setStatus("No results.", "bad");
        return;
      }

      resultsEl.innerHTML = list.map(resultHTML).join("");
      setStatus(`Found ${data.total_cards ?? list.length}. Showing ${list.length}.`, "ok");
    } catch {
      setStatus("Network error talking to Scryfall.", "bad");
    }
  }

  form.addEventListener('submit', (e) => { e.preventDefault(); runSearch(); });
  clearBtn.addEventListener('click', () => {
    qEl.value = "";
    resultsEl.innerHTML = "";
    setStatus("Cleared.");
    qEl.focus();
  });
</script>
</body>
</html>