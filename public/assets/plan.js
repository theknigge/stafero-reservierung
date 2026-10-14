// Saalplan-Auswahl: Ein Klick auf einen Tisch setzt die ganze Gruppe dorthin – reicht der Tisch nicht,
// kommen die nächstgelegenen Tische dazu (bevorzugt an derselben Tafel). Ändert sich die Anzahl, wird
// sofort neu verteilt. Die Rechnung entspricht auto_assign() in src/layout.php.
// Die Zuteilung steht in den versteckten Feldern "alloc[A1]"; ohne JavaScript gilt der Vorschlag vom Server.
(() => {
  const form = document.getElementById('res-form');
  if (!form) return;

  const SEATS = 4;
  const TAFEL_PENALTY = 60; // wie ASSIGN_TAFEL_PENALTY
  const WASTE_COST = 40;    // wie ASSIGN_WASTE_COST
  const SPLIT_COST = 250;   // wie ASSIGN_SPLIT_COST

  const seatsInput = form.querySelector('#seats');
  const proposeBtn = form.querySelector('#propose');
  const saveBtn = form.querySelector('#save');
  const details = document.getElementById('res-details');

  // Enter im Feld "Anzahl" berechnet neu, in allen anderen Feldern wird gespeichert
  form.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || e.target.tagName !== 'INPUT') return;
    e.preventDefault();
    (e.target === seatsInput || !saveBtn ? proposeBtn : saveBtn).click();
  });

  const inputs = new Map([...form.querySelectorAll('input[name^="alloc["]')].map((i) => [i.dataset.unit, i]));
  const counter = document.getElementById('counter');
  if (!counter) return;
  // Tische in Plan-Reihenfolge, nur solche mit freien Plätzen
  const tables = [...document.querySelectorAll('.plan .seat[data-unit]')].filter((t) => inputs.has(t.dataset.unit));
  const byUnit = new Map(tables.map((t) => [t.dataset.unit, t]));
  const room = (u) => Number(inputs.get(u).max);

  const val = (i) => Math.max(0, Math.min(Number(i.max), Math.floor(Number(i.value) || 0)));
  const target = () => Math.floor(Number(seatsInput.value) || 0);
  const assigned = () => [...inputs.values()].reduce((s, i) => s + val(i), 0);
  const mineUnits = () => [...inputs.keys()].filter((u) => val(inputs.get(u)) > 0);
  const plural = (n) => `${n} ${n === 1 ? 'Platz' : 'Plätze'}`;

  function dist(a, b) {
    const ta = byUnit.get(a).dataset;
    const tb = byUnit.get(b).dataset;
    return Math.hypot(ta.x - tb.x, ta.y - tb.y) + (ta.tafel === tb.tafel ? 0 : TAFEL_PENALTY);
  }

  // Gruppe ab Tisch "start": immer den nächstgelegenen Tisch dazunehmen, bis n Plätze zusammen sind
  function grow(start, n) {
    const cluster = [start];
    let sum = room(start);
    const d = new Map();
    while (sum < n) {
      let next = null;
      for (const u of inputs.keys()) {
        if (cluster.includes(u)) continue;
        d.set(u, Math.min(d.get(u) ?? Infinity, dist(u, cluster[cluster.length - 1])));
        if (next === null || d.get(u) < d.get(next)) next = u;
      }
      cluster.push(next);
      sum += room(next);
    }
    let spread = 0;
    cluster.forEach((a, i) => cluster.slice(i + 1).forEach((b) => { spread = Math.max(spread, dist(a, b)); }));
    const tafeln = new Set(cluster.map((u) => byUnit.get(u).dataset.tafel)).size;
    return { cluster, score: cluster.length * 1000 + (tafeln - 1) * SPLIT_COST + spread + (sum - n) * WASTE_COST };
  }

  /** Verteilt n Plätze – ab "start" oder an der besten Stelle im Saal. false, wenn nicht genug frei ist. */
  function assign(n, start = null) {
    const total = [...inputs.keys()].reduce((s, u) => s + room(u), 0);
    if (n < 1 || n > total) return false;
    let best = null;
    for (const s of start ? [start] : inputs.keys()) {
      const g = grow(s, n);
      if (!best || g.score < best.score) best = g;
    }
    let left = n;
    for (const i of inputs.values()) i.value = 0;
    for (const u of best.cluster) {
      const k = Math.min(room(u), left);
      inputs.get(u).value = k;
      left -= k;
    }
    return true;
  }

  function sync() {
    for (const t of tables) {
      const taken = Number(t.dataset.taken);
      const mine = val(inputs.get(t.dataset.unit));
      t.classList.toggle('is-mine', mine > 0);
      t.classList.toggle('is-partial', mine === 0 && taken > 0);
      t.classList.toggle('is-free', mine === 0 && taken === 0);
      [...t.querySelectorAll('.chair')].forEach((c, k) => {
        c.setAttribute('class', 'chair ' + (k < taken ? 'is-taken' : k < taken + mine ? 'is-mine' : 'is-free'));
      });
      t.setAttribute('aria-label', `Tisch ${t.dataset.unit}: ${mine} eure, ${SEATS - taken - mine} frei – hierher setzen`);
    }
    const sum = assigned();
    const want = target();
    const tablesTxt = mineUnits().join(', ');
    counter.textContent = sum === want
      ? `Eure ${want} Plätze: Tisch ${tablesTxt} ✓`
      : `Verteilt: ${sum} von ${want} – tippt auf einen Tisch, um alle ${plural(want)} dort zu platzieren`;
    counter.classList.toggle('is-over', sum !== want);
    counter.classList.toggle('is-ok', sum === want);
  }

  /** Prüft die Anzahl; bei Fehler Hinweis zeigen und ins Feld springen. */
  function countOk() {
    const n = target();
    if (n >= Number(seatsInput.min) && n <= Number(seatsInput.max)) return true;
    counter.textContent = `Gebt zuerst ein, wie viele Plätze ihr braucht (${seatsInput.min}–${seatsInput.max}).`;
    counter.classList.add('is-over');
    seatsInput.focus();
    return false;
  }

  function place(unit) {
    if (!countOk()) return;
    if (!assign(target(), unit)) {
      counter.textContent = `Leider sind keine ${plural(target())} mehr frei.`;
      counter.classList.add('is-over');
      return;
    }
    details.hidden = false;
    sync();
  }

  // "Vorschlagen" ohne Neuladen: beste Stelle im ganzen Saal
  proposeBtn.addEventListener('click', (e) => {
    e.preventDefault();
    if (!countOk()) return;
    if (!assign(target())) {
      counter.textContent = `Leider sind keine ${plural(target())} mehr frei.`;
      counter.classList.add('is-over');
      return;
    }
    details.hidden = false;
    sync();
  });

  for (const t of tables) {
    t.addEventListener('click', () => place(t.dataset.unit));
    t.addEventListener('keydown', (e) => {
      if (e.key === ' ' || e.key === 'Enter') {
        e.preventDefault();
        place(t.dataset.unit);
      }
    });
  }

  // Neue Anzahl: an derselben Stelle neu verteilen (oder an der besten, wenn noch nichts gewählt ist)
  seatsInput.addEventListener('input', () => {
    const n = target();
    if (details.hidden) return; // noch kein Vorschlag – erst auf "Vorschlagen" oder einen Tisch tippen
    if (n >= Number(seatsInput.min) && n <= Number(seatsInput.max)) {
      const anchor = mineUnits()[0] ?? null;
      if (!assign(n, anchor) && anchor) assign(n);
    }
    sync();
  });
  if (!details.hidden) sync();
})();
