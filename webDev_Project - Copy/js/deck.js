/*
 * File:
 * deck.js
 *
 * Purpose:
 * For deck.php scripting
 *
 */

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
