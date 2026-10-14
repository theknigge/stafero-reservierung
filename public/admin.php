<?php
require __DIR__ . '/../src/bootstrap.php';

$pdo = db();
$error = null;

if (cfg('admin_password') === '') {
    http_response_code(503);
    exit('Admin-Bereich deaktiviert: ADMIN_PASSWORD ist nicht gesetzt.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!csrf_check()) {
        $error = 'Die Sitzung ist abgelaufen. Bitte noch einmal versuchen.';
    } elseif ($action === 'login') {
        if (db_fail_count($pdo, client_ip()) >= FAIL_LIMIT) {
            $error = 'Zu viele Fehlversuche. Bitte 15 Minuten warten.';
        } elseif (hash_equals(cfg('admin_password'), (string) ($_POST['password'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            redirect('admin.php');
        } else {
            db_register_fail($pdo, client_ip());
            $error = 'Falsches Passwort.';
        }
    } elseif (empty($_SESSION['admin'])) {
        $error = 'Bitte zuerst anmelden.';
    } elseif ($action === 'delete') {
        db_delete($pdo, (int) ($_POST['id'] ?? 0));
        $_SESSION['flash'] = 'Reservierung #' . (int) ($_POST['id'] ?? 0) . ' wurde gelöscht.';
        redirect('admin.php');
    } elseif ($action === 'logout') {
        unset($_SESSION['admin']);
        redirect('admin.php');
    }
}

$isAdmin = !empty($_SESSION['admin']);
$reservations = $isAdmin ? db_all($pdo) : [];

if ($isAdmin && ($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reservierungen-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, damit Excel UTF-8 erkennt
    fputcsv($out, ['Nr.', 'Nachname', 'Vorname', 'Telefon', 'E-Mail', 'Plätze', 'Tische', 'Änderungscode', 'Angelegt', 'Geändert'], ';');
    foreach (array_reverse($reservations) as $r) {
        fputcsv($out, [
            $r['id'], $r['last_name'], $r['first_name'], $r['phone'], $r['email'], $r['seats'], alloc_label($r['alloc']),
            $r['code'], fmt_datetime($r['created_at']), fmt_datetime($r['updated_at']),
        ], ';');
    }
    exit;
}

if ($isAdmin && in_array($_GET['view'] ?? '', ['badges', 'plan'], true)) {
    require __DIR__ . '/../src/print.php';
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

page_header('Reservierungen', cfg('event_name') . ' · Verwaltung');
?>
<main class="wide">
<?php if (!$isAdmin): ?>
  <form class="card narrow" method="post" action="admin.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="login">
    <label for="password">Passwort</label>
    <input id="password" name="password" type="password" required autocomplete="current-password" autofocus>
    <button type="submit">Anmelden</button>
    <?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>
  </form>
<?php else: ?>
<?php
    $byUnit = table_occupancy($reservations);
    $occ = [];
    $titles = [];
    foreach ($byUnit as $u => $rows) {
        $occ[$u] = ['taken' => array_sum(array_column($rows, 'count'))];
        $titles[$u] = "Tisch $u – " . implode('; ', array_map(fn($row) => $row['count'] . '× ' . display_name($row['res']), $rows));
    }
    $seatCount = array_sum(array_column($reservations, 'seats'));
?>
  <?php if ($flash): ?><p class="notice"><?= h($flash) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="notice error-notice"><?= h($error) ?></p><?php endif; ?>

  <div class="stats">
    <div><strong><?= count($reservations) ?></strong><span>Reservierungen</span></div>
    <div><strong><?= count($byUnit) ?> / <?= count(plan_units()) ?></strong><span>Tische (ganz oder teilweise) belegt</span></div>
    <div><strong><?= $seatCount ?> / <?= plan_capacity() ?></strong><span>Plätze reserviert</span></div>
    <div><strong><?= deadline_label() ?></strong><span>Änderungsschluss<?= is_closed() ? ' (vorbei)' : '' ?></span></div>
  </div>

  <div class="toolbar">
    <a class="btn" href="admin.php?export=csv">CSV-Export</a>
    <a class="btn" href="admin.php?view=badges">Schilder drucken (A4)</a>
    <a class="btn" href="admin.php?view=plan">Saalplan mit Tischnummern</a>
    <button type="button" class="btn" onclick="window.print()">Drucken</button>
    <form method="post" action="admin.php"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button type="submit" class="btn">Abmelden</button></form>
  </div>

  <div class="admin-grid">
    <section class="plan-card">
      <h2>Saalplan</h2>
      <div class="plan"><?= render_plan($occ, false, $titles) ?></div>
      <?= plan_legend(false) ?>
    </section>

    <section class="plan-card">
      <h2>Belegung nach Tisch</h2>
      <table class="list">
        <thead><tr><th>Tisch</th><th>Plätze</th><th>Reserviert für</th></tr></thead>
        <tbody>
        <?php foreach (plan_units() as $u => $tid): $rows = $byUnit[$u] ?? []; ?>
          <tr class="<?= $rows ? '' : 'free' ?>"><td><?= $u ?></td><td><?= $occ[$u]['taken'] ?? 0 ?> / <?= SEATS_PER_UNIT ?></td>
            <td><?= $rows ? implode('<br>', array_map(fn($row) => $row['count'] . '× ' . h(display_name($row['res'])) . ' <span class="muted">#' . $row['res']['id'] . '</span>', $rows)) : 'frei' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  </div>

  <section class="plan-card">
    <h2>Alle Reservierungen</h2>
    <div class="table-scroll">
    <table class="list">
      <thead><tr><th>Nr.</th><th>Name</th><th>Telefon</th><th>E-Mail</th><th>Plätze</th><th>Tische</th><th>Code</th><th>Geändert</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if (!$reservations): ?><tr><td colspan="9">Noch keine Reservierungen.</td></tr><?php endif; ?>
      <?php foreach ($reservations as $r): ?>
        <tr>
          <td>#<?= $r['id'] ?></td>
          <td><?= h(display_name($r)) ?></td>
          <td><a href="tel:<?= h($r['phone_norm']) ?>"><?= h($r['phone']) ?></a></td>
          <td><?= $r['email'] !== '' ? '<a href="mailto:' . h($r['email']) . '">' . h($r['email']) . '</a>' : '' ?></td>
          <td><?= $r['seats'] ?></td>
          <td><?= h(alloc_label($r['alloc'])) ?></td>
          <td><?= h($r['code']) ?></td>
          <td><?= h(fmt_datetime($r['updated_at'])) ?></td>
          <td class="no-print">
            <form method="post" action="admin.php" onsubmit="return confirm('Reservierung #<?= $r['id'] ?> (<?= h(addslashes(display_name($r))) ?>) wirklich löschen?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button type="submit" class="link danger">löschen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>
<?php endif; ?>
</main>
<?php page_footer(); ?>
