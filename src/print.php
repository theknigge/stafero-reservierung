<?php
// Druckansichten für den Admin-Bereich (Browser → Drucken / Als PDF speichern):
//   ?view=badges   ein A4-Schild (quer) pro Tisch mit allen Gruppen, z. B. "2× Müller, Vanessa";
//                  gesperrte Tische nur mit "Reserviert" und Tischnummer
//   ?view=plan     Übersichtsplan mit allen Tischnummern (A4 hoch)
// Erwartet $reservations aus admin.php.

$view = $_GET['view'] ?? '';

/** Schriftgröße der Namenszeilen, damit alle Gruppen eines Tisches auf eine Seite passen. */
function badge_name_size(array $lines): string
{
    $len = max(array_map('mb_strlen', $lines));
    $byLength = match (true) {
        $len <= 16 => 60,
        $len <= 24 => 46,
        $len <= 34 => 34,
        default    => 26,
    };
    $byCount = [1 => 60, 2 => 52, 3 => 40, 4 => 32][count($lines)] ?? 28;
    return min($byLength, $byCount) . 'pt';
}

$sheets = [];
if ($view === 'badges') {
    // Gesperrte Tische bekommen ein Schild nur mit "Reserviert" und Tischnummer
    $byUnit = table_occupancy($reservations);
    foreach (db_blocked_units($pdo) as $u) {
        $byUnit[$u] = [];
    }
    foreach (sort_alloc($byUnit) as $u => $rows) {
        $sheets[] = [
            'unit'  => $u,
            'lines' => array_map(fn($row) => $row['count'] . '× ' . display_name($row['res']), $rows),
            'seats' => array_sum(array_column($rows, 'count')),
        ];
    }
}

$title = $view === 'plan' ? 'Saalplan mit Tischnummern' : 'Reservierungsschilder';
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> – <?= h(cfg('event_name')) ?></title>
<link rel="icon" href="assets/stafero-logo.png">
<link rel="stylesheet" href="assets/style.css?v=5">
<style>
  html, body { background:#d9d9d9; color:#000; }
  .print-bar { position:sticky; top:0; z-index:2; background:#000; color:#fff; padding:10px 16px; display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
  .print-bar a, .print-bar button { width:auto; margin:0; padding:8px 14px; font-size:.9rem; background:#fff; color:#000; border:2px solid #fff; text-decoration:none; }
  .print-bar a:hover, .print-bar button:hover { background:var(--yellow); }
  .print-bar span { color:var(--grey-light); font-size:.9rem; }
  .sheet { background:#fff; margin:20px auto; box-shadow:0 2px 12px rgba(0,0,0,.25); overflow:hidden; position:relative; }

  /* Schild: A4 quer */
  .badge { width:297mm; height:210mm; padding:14mm 18mm; display:flex; flex-direction:column; align-items:center; justify-content:space-between; text-align:center; border-top:10mm solid var(--yellow); }
  .badge .logo { height:30mm; width:auto; }
  .badge .label { font:400 34pt/1 'Luckiest Guy', sans-serif; letter-spacing:.04em; margin:0; }
  .badge .table { font:400 120pt/1 'Luckiest Guy', sans-serif; margin:0; }
  .badge .names { list-style:none; margin:0; padding:0; font-weight:700; line-height:1.15; max-width:100%; overflow-wrap:anywhere; }
  .badge .meta { font-size:12pt; color:var(--grey); margin:0; }

  /* Übersichtsplan: A4 hoch */
  .overview-sheet { width:210mm; min-height:297mm; padding:10mm 12mm; }
  .overview-sheet h1 { font:400 22pt/1.1 'Luckiest Guy', sans-serif; margin:0; }
  .overview-sheet .sub { margin:2mm 0 4mm; font-size:10pt; color:var(--grey); }
  .overview-sheet .plan svg { height:205mm; width:auto; margin:0 auto; }
  .overview-sheet table { width:100%; border-collapse:collapse; font-size:9.5pt; margin-top:4mm; }
  .overview-sheet th, .overview-sheet td { text-align:left; border-bottom:1px solid #ccc; padding:1mm 2mm; }
  .empty { text-align:center; padding:40px; }

  @page { margin:0; }
  @media print {
    html, body { background:#fff; }
    .print-bar { display:none; }
    .sheet { margin:0; box-shadow:none; break-after:page; }
    .sheet:last-child { break-after:auto; }
  }
</style>
<?php if ($view === 'badges'): ?><style>@page { size:A4 landscape; }</style><?php else: ?><style>@page { size:A4 portrait; }</style><?php endif; ?>
</head>
<body>
<div class="print-bar">
  <a href="admin.php">← Zurück</a>
  <button type="button" onclick="window.print()">Drucken / als PDF speichern</button>
  <?php if ($view === 'badges'): ?>
    <span><?= count($sheets) ?> Seiten · A4 quer, Ränder im Druckdialog auf „Keine“ stellen, Hintergrundgrafiken an</span>
  <?php else: ?>
    <span>A4 hoch · Hintergrundgrafiken im Druckdialog einschalten</span>
  <?php endif; ?>
</div>

<?php if ($view === 'badges'): ?>
  <?php if (!$sheets): ?><div class="sheet empty">Noch keine Reservierungen.</div><?php endif; ?>
  <?php foreach ($sheets as $s): ?>
    <section class="sheet badge">
      <img class="logo" src="assets/stafero-logo.png" alt="STAFERO e.V.">
      <p class="label">Reserviert</p>
      <p class="table"><?= h($s['unit']) ?></p>
      <?php if ($s['lines']): ?>
        <ul class="names" style="font-size:<?= badge_name_size($s['lines']) ?>">
          <?php foreach ($s['lines'] as $line): ?><li><?= h($line) ?></li><?php endforeach; ?>
        </ul>
        <p class="meta"><?= h(cfg('event_name')) ?> · Tisch <?= h($s['unit']) ?> · <?= h(plural($s['seats'], 'Platz', 'Plätze')) ?> reserviert</p>
      <?php else: ?>
        <p class="meta"><?= h(cfg('event_name')) ?> · Tisch <?= h($s['unit']) ?></p>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

<?php else: ?>
  <section class="sheet overview-sheet">
    <h1>Saalplan <?= h(cfg('event_name')) ?></h1>
    <p class="sub">Buchstabe = Tafel, Ziffer = Tisch à 4 Plätze, gezählt ab der Wand (1 = Wandseite) · <?= count(plan_units()) ?> Tische · <?= plan_capacity() ?> Plätze</p>
    <div class="plan"><?= render_plan([], false) ?></div>
    <table>
      <thead><tr><th>Tafeln</th><th>Lage</th><th>Plätze je Tafel</th><th>Tische</th></tr></thead>
      <tbody>
        <tr><td>A – E</td><td>oben, längs</td><td>20</td><td>1 – 5 (1 = hintere Wand)</td></tr>
        <tr><td>F – K</td><td>links, schräg</td><td>12</td><td>1 – 3 (1 = linke Wand)</td></tr>
        <tr><td>L – O</td><td>rechts</td><td>8</td><td>1 – 2 (1 = rechte Wand)</td></tr>
      </tbody>
    </table>
  </section>
<?php endif; ?>
</body>
</html>
