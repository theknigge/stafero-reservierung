<?php
// Saalplan des STAFERO-Balls (nach "Stafero-Ball Tische.pdf", 24er-Tafeln durch 20er ersetzt).
// Eine Tafel besteht aus Tischen à 4 Plätzen; reserviert wird pro Platz, ein Tisch kann also
// zwischen mehreren Gruppen geteilt werden.
//   A–E: 5 Tafeln à 20 Plätze (5 Tische) oben
//   F–K: 6 Tafeln à 12 Plätze (3 Tische) links, schräg gestellt
//   L–O: 4 Tafeln à  8 Plätze (2 Tische) rechts
// Gesamt: 51 Tische = 204 Plätze.
// Nummerierung: Buchstabe = Tafel, Ziffer = Tisch, gezählt ab der Wand (1 = Wandseite).

const SEATS_PER_UNIT = 4;

function plan_tables(): array
{
    static $tables = null;
    if ($tables !== null) {
        return $tables;
    }
    $tables = [];
    foreach (['A', 'B', 'C', 'D', 'E'] as $i => $id) {
        $tables[$id] = ['id' => $id, 'kind' => 'top', 'units' => 5, 'x' => 207 + $i * 115, 'y' => 50];
    }
    foreach (['F', 'G', 'H', 'I', 'J', 'K'] as $i => $id) {
        $tables[$id] = ['id' => $id, 'kind' => 'left', 'units' => 3, 'cx' => 138, 'cy' => 590 + $i * 100];
    }
    foreach (['L', 'M', 'N', 'O'] as $i => $id) {
        $tables[$id] = ['id' => $id, 'kind' => 'right', 'units' => 2, 'x' => 720, 'y' => [660, 765, 870, 975][$i]];
    }
    return $tables;
}

/** Alle Tische (Reservierungseinheiten) als ['A1' => 'A', 'A2' => 'A', ...]. */
function plan_units(): array
{
    $units = [];
    foreach (plan_tables() as $id => $t) {
        for ($k = 1; $k <= $t['units']; $k++) {
            $units[$id . $k] = $id;
        }
    }
    return $units;
}

function plan_capacity(): int
{
    return count(plan_units()) * SEATS_PER_UNIT;
}

/** Sortiert Tisch-IDs in Plan-Reihenfolge (A1, A2, …, O2). */
function sort_units(array $units): array
{
    $order = array_flip(array_keys(plan_units()));
    usort($units, fn($a, $b) => ($order[$a] ?? 999) <=> ($order[$b] ?? 999));
    return $units;
}

/** Sortiert eine Zuteilung ['B2' => 2, 'A1' => 4] in Plan-Reihenfolge. */
function sort_alloc(array $alloc): array
{
    $sorted = [];
    foreach (sort_units(array_keys($alloc)) as $u) {
        $sorted[$u] = $alloc[$u];
    }
    return $sorted;
}

/** "A1 (4 Plätze), A2 (2 Plätze)" */
function alloc_label(array $alloc): string
{
    $parts = [];
    foreach (sort_alloc($alloc) as $u => $n) {
        $parts[] = $u . ' (' . plural($n, 'Platz', 'Plätze') . ')';
    }
    return implode(', ', $parts);
}

/**
 * Belegung je Tisch in Plan-Reihenfolge: ['A1' => [['res' => Reservierung, 'count' => 2], ...], ...]
 * Innerhalb eines Tisches nach Reservierungsnummer sortiert.
 */
function table_occupancy(array $reservations): array
{
    usort($reservations, fn($a, $b) => $a['id'] <=> $b['id']);
    $byUnit = [];
    foreach ($reservations as $r) {
        foreach ($r['alloc'] as $u => $n) {
            $byUnit[$u][] = ['res' => $r, 'count' => $n];
        }
    }
    return sort_alloc($byUnit);
}

/**
 * Rechteck jedes Tisches im SVG: ['A1' => ['tafel' => 'A', 'x', 'y', 'w', 'h', 'cx', 'cy', 'rot' => ?[winkel, cx, cy]]].
 * cx/cy ist die Tischmitte in Saalkoordinaten (für die Abstandsberechnung der Zuweisung).
 */
function plan_geometry(): array
{
    static $geo = null;
    if ($geo !== null) {
        return $geo;
    }
    $geo = [];
    foreach (plan_tables() as $id => $t) {
        for ($k = 0; $k < $t['units']; $k++) {
            $u = $id . ($k + 1);
            if ($t['kind'] === 'top') {
                $g = ['x' => $t['x'], 'y' => $t['y'] + $k * 72, 'w' => 56, 'h' => 72, 'rot' => null];
            } elseif ($t['kind'] === 'left') {
                $g = ['x' => $t['cx'] - 108 + $k * 72, 'y' => $t['cy'] - 25, 'w' => 72, 'h' => 50, 'rot' => [-15, $t['cx'], $t['cy']]];
            } else {
                // Tisch 1 an der Wand (rechts)
                $g = ['x' => $t['x'] + ($t['units'] - 1 - $k) * 80, 'y' => $t['y'], 'w' => 80, 'h' => 52, 'rot' => null];
            }
            $cx = $g['x'] + $g['w'] / 2;
            $cy = $g['y'] + $g['h'] / 2;
            if ($g['rot']) {
                [$deg, $ox, $oy] = $g['rot'];
                $a = deg2rad($deg);
                [$cx, $cy] = [$ox + ($cx - $ox) * cos($a) - ($cy - $oy) * sin($a), $oy + ($cx - $ox) * sin($a) + ($cy - $oy) * cos($a)];
            }
            $geo[$u] = $g + ['tafel' => $id, 'cx' => $cx, 'cy' => $cy];
        }
    }
    return $geo;
}

// --- Automatische Zuweisung ---

const ASSIGN_TAFEL_PENALTY = 60;  // Aufschlag auf den Abstand, wenn zwei Tische an verschiedenen Tafeln stehen
const ASSIGN_WASTE_COST = 40;     // Kosten je Platz, der an den gewählten Tischen frei bleibt (Teiltische zuerst füllen)
const ASSIGN_SPLIT_COST = 250;    // Kosten je weiterer Tafel, über die sich die Gruppe verteilt

/** Abstand zweier Tische; Nachbarn an derselben Tafel sind deutlich "näher" als die Tafel gegenüber. */
function unit_distance(string $a, string $b): float
{
    $geo = plan_geometry();
    $d = hypot($geo[$a]['cx'] - $geo[$b]['cx'], $geo[$a]['cy'] - $geo[$b]['cy']);
    return $d + ($geo[$a]['tafel'] === $geo[$b]['tafel'] ? 0 : ASSIGN_TAFEL_PENALTY);
}

/**
 * Verteilt $n Plätze auf möglichst wenige, nebeneinanderliegende Tische.
 * $free: ['A1' => freie Plätze, ...]. Ergebnis: ['A1' => 4, 'A2' => 2] oder null, wenn nicht genug frei ist.
 *
 * Von jedem Tisch aus wird eine Gruppe gebildet, indem immer der nächstgelegene Tisch mit freien Plätzen
 * dazukommt, bis alle Plätze untergebracht sind. Gewählt wird die beste Gruppe: wenige Tische, dicht
 * beieinander, möglichst an einer Tafel und ohne angebrochene Tische liegen zu lassen.
 */
function auto_assign(int $n, array $free): ?array
{
    $free = array_filter($free, fn($f) => $f > 0);
    if ($n < 1 || array_sum($free) < $n) {
        return null;
    }
    $free = sort_alloc($free);
    $best = null;
    $bestScore = INF;
    foreach ($free as $start => $_) {
        $cluster = [$start];
        $sum = $free[$start];
        $dist = [];  // kleinster Abstand jedes Kandidaten zur Gruppe
        while ($sum < $n) {
            $next = null;
            foreach ($free as $u => $f) {
                if (in_array($u, $cluster, true)) {
                    continue;
                }
                $dist[$u] = min($dist[$u] ?? INF, unit_distance($u, end($cluster)));
                if ($next === null || $dist[$u] < $dist[$next]) {
                    $next = $u;
                }
            }
            $cluster[] = $next;
            $sum += $free[$next];
        }
        $spread = 0;
        foreach ($cluster as $i => $a) {
            foreach (array_slice($cluster, $i + 1) as $b) {
                $spread = max($spread, unit_distance($a, $b));
            }
        }
        $tafeln = count(array_unique(array_map(fn($u) => plan_geometry()[$u]['tafel'], $cluster)));
        $score = count($cluster) * 1000 + ($tafeln - 1) * ASSIGN_SPLIT_COST + $spread + ($sum - $n) * ASSIGN_WASTE_COST;
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = $cluster;
        }
    }

    // Volle Tische zuerst, der Rest kommt an den letzten Tisch der Gruppe
    $alloc = [];
    $left = $n;
    foreach ($best as $u) {
        $alloc[$u] = min($free[$u], $left);
        $left -= $alloc[$u];
    }
    return sort_alloc($alloc);
}

// --- Darstellung ---

/**
 * Rendert den Saalplan als SVG. Jeder Tisch zeigt vier Stühle: grau = belegt, gelb = eure Plätze, weiß = frei.
 * $occ: ['A1' => ['taken' => belegte Plätze anderer Gruppen, 'mine' => eigene Plätze], ...] (fehlend = frei)
 * $interactive: Tische mit freien Plätzen sind anklickbar (siehe assets/plan.js)
 * $titles: optionale Tooltips je Tisch (z. B. Namen in der Admin-Ansicht)
 */
function render_plan(array $occ, bool $interactive, array $titles = []): string
{
    $seat = function (string $unit, array $g) use ($occ, $interactive, $titles): string {
        $taken = (int) ($occ[$unit]['taken'] ?? 0);
        $mine = (int) ($occ[$unit]['mine'] ?? 0);
        $free = SEATS_PER_UNIT - $taken - $mine;
        $state = match (true) {
            $mine > 0                => 'mine',
            $taken >= SEATS_PER_UNIT => 'taken',
            $taken > 0               => 'partial',
            default                  => 'free',
        };
        $attrs = 'class="seat is-' . $state . '" data-unit="' . $unit . '" data-taken="' . $taken . '"';
        if ($interactive && $taken < SEATS_PER_UNIT) {
            // Lage für die Zuweisung im Browser (assets/plan.js rechnet wie auto_assign())
            $attrs .= sprintf(' role="button" tabindex="0" data-tafel="%s" data-x="%d" data-y="%d"', $g['tafel'], $g['cx'], $g['cy']);
        }
        $title = $titles[$unit] ?? ('Tisch ' . $unit . ' – ' . match (true) {
            $free <= 0 && $mine === 0 => 'belegt',
            $free === SEATS_PER_UNIT  => 'frei',
            default                   => plural($free, 'Platz', 'Plätze') . ' frei',
        });
        if ($g['rot']) {
            $attrs .= sprintf(' transform="rotate(%s %s %s)"', ...$g['rot']);
        }
        // Stühle: zuerst die belegten, dann eure, dann die freien
        $chairs = '';
        $pos = [[0.2, 0.18], [0.8, 0.18], [0.2, 0.82], [0.8, 0.82]];
        foreach ($pos as $i => [$fx, $fy]) {
            $cls = $i < $taken ? 'is-taken' : ($i < $taken + $mine ? 'is-mine' : 'is-free');
            $chairs .= sprintf('<circle class="chair %s" cx="%s" cy="%s" r="8"/>', $cls, $g['x'] + $g['w'] * $fx, $g['y'] + $g['h'] * $fy);
        }
        return sprintf(
            '<g %s><title>%s</title><rect x="%s" y="%s" width="%s" height="%s"/>%s<text x="%s" y="%s">%s</text></g>',
            $attrs, h($title), $g['x'], $g['y'], $g['w'], $g['h'], $chairs, $g['x'] + $g['w'] / 2, $g['y'] + $g['h'] / 2, $unit
        );
    };

    $o = '<svg viewBox="0 0 900 1280" xmlns="http://www.w3.org/2000/svg" role="group" aria-label="Saalplan">';
    $o .= '<rect class="wall" x="10" y="10" width="880" height="1260"/>';
    foreach ([[470, 610], [1060, 1180]] as [$a, $b]) {
        $mid = ($a + $b) / 2;
        $o .= sprintf('<line class="door-gap" x1="890" y1="%d" x2="890" y2="%d"/>', $a, $b);
        $o .= sprintf('<text class="door" x="866" y="%d" transform="rotate(-90 866 %d)">Eingang</text>', $mid, $mid);
    }
    $o .= '<rect class="stage" x="110" y="1212" width="610" height="48"/><text class="stage-label" x="415" y="1238">Bühne</text>';

    // Tafel-Buchstabe neben jeder Tafel
    foreach (plan_tables() as $id => $t) {
        if ($t['kind'] === 'top') {
            $o .= sprintf('<text class="tafel" x="%d" y="%d">%s</text>', $t['x'] + 28, $t['y'] + 5 * 72 + 34, $id);
        } elseif ($t['kind'] === 'left') {
            $o .= sprintf('<text class="tafel" x="%d" y="%d">%s</text>', $t['cx'] + 136, $t['cy'] - 36, $id);
        } else {
            $o .= sprintf('<text class="tafel" x="%d" y="%d">%s</text>', $t['x'] - 24, $t['y'] + 26, $id);
        }
    }
    foreach (plan_geometry() as $unit => $g) {
        $o .= $seat($unit, $g);
    }
    return $o . '</svg>';
}

function plan_legend(bool $withMine = true): string
{
    return '<ul class="legend">'
        . '<li><span class="sw sw-free"></span>frei</li>'
        . ($withMine ? '<li><span class="sw sw-mine"></span>eure Plätze</li>' : '')
        . '<li><span class="sw sw-taken"></span>belegt</li>'
        . '<li class="legend-note">1 Tisch = 4 Plätze</li>'
        . '</ul>';
}
