<?php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/actions.php';

$pdo = db();
$error = null;
$form = null; // Formularzustand nach "Vorschlagen" oder einem Fehler beim Speichern

// Bestehende Reservierungen lassen sich nur über den persönlichen Link aus der Bestätigungsmail öffnen: ?code=K7M2QX
if (isset($_GET['code']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $error = action_login($pdo, (string) $_GET['code']);
    if ($error === null) {
        redirect('./#top');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!csrf_check()) {
        $error = 'Die Sitzung ist abgelaufen. Bitte versucht es noch einmal.';
    } elseif ($action === 'propose' || $action === 'save') {
        $form = [
            'seats'      => (int) ($_POST['seats'] ?? 0),
            'alloc'      => isset($_POST['alloc']) ? parse_alloc((array) $_POST['alloc']) : null,
            'first_name' => (string) ($_POST['first_name'] ?? ''),
            'last_name'  => (string) ($_POST['last_name'] ?? ''),
            'phone'      => (string) ($_POST['phone'] ?? ''),
            'email'      => (string) ($_POST['email'] ?? ''),
        ];
        if ($action === 'propose') {
            if (is_closed()) {
                $error = msg_closed();
            } else {
                [$error, $proposal] = action_propose($pdo, $form['seats'], $_SESSION['res_id'] ?? null);
                $form['alloc'] = $proposal ?? $form['alloc'];
            }
        } else {
            $form['alloc'] ??= [];
            $error = action_save($pdo, $form);
            if ($error === null) {
                redirect('./#top');
            }
        }
    } elseif ($action === 'delete') {
        $error = action_delete($pdo);
        if ($error === null) {
            redirect('./#top');
        }
    } elseif ($action === 'logout') {
        unset($_SESSION['res_id']);
        redirect('./#top');
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$res = null;
if (!empty($_SESSION['res_id'])) {
    $res = db_get_reservation($pdo, $_SESSION['res_id']);
    if (!$res) {
        unset($_SESSION['res_id']);
        $error ??= 'Diese Reservierung gibt es nicht mehr. Bei Fragen schreibt uns an ' . cfg('contact_email') . '.';
    }
}

$closed = is_closed();
$taken = db_taken_seats($pdo, $res['id'] ?? null);
$form ??= [
    'seats'      => $res['seats'] ?? 0,
    'alloc'      => $res['alloc'] ?? null,
    'first_name' => $res['first_name'] ?? '',
    'last_name'  => $res['last_name'] ?? '',
    'phone'      => $res['phone'] ?? '',
    'email'      => $res['email'] ?? '',
];
$editable = !$closed;
$alloc = $form['alloc'];

$occ = [];
foreach (plan_units() as $u => $_) {
    $t = $taken[$u] ?? 0;
    $occ[$u] = ['taken' => $t, 'mine' => min($alloc[$u] ?? 0, SEATS_PER_UNIT - $t)];
}
$allocSum = array_sum(array_column($occ, 'mine'));
$interactive = $editable;

page_header('Tischreservierung', cfg('event_name'));
?>
<main>
<?php if ($flash): ?><p class="notice"><?= h($flash) ?></p><?php endif; ?>

<?php if (!$res): ?>
  <div class="intro">
    <?php if ($closed): ?>
      <p>Der Reservierungszeitraum ist am <strong><?= deadline_label() ?></strong> zu Ende gegangen.
        Eure bestehende Reservierung könnt ihr über den Link in eurer Bestätigungsmail weiterhin ansehen.</p>
    <?php else: ?>
      <p>Reserviert euch eure Plätze für den <strong><?= h(cfg('event_name')) ?></strong>!</p>
      <ul class="rules">
        <li>Gebt ein, wie viele Plätze ihr braucht (<strong><?= MIN_SEATS ?> bis <?= cfg('max_seats') ?></strong>). Wir schlagen euch
          nebeneinanderliegende Plätze vor, die ihr im Saalplan noch ändern könnt.</li>
        <li>An jedem Tisch sitzen <strong>4 Personen</strong>. Ein Tisch kann auch mit einer anderen Gruppe geteilt werden.</li>
        <li>Nach dem Reservieren bekommt ihr eine <strong>Bestätigungsmail mit persönlichem Link</strong>. Darüber könnt ihr bis zum <strong><?= deadline_label() ?></strong> alles ändern oder stornieren.</li>
      </ul>
    <?php endif; ?>
  </div>
<?php endif; ?>

  <div class="booking">
    <section class="plan-card" aria-labelledby="plan-title">
      <h2 id="plan-title">Saalplan</h2>
      <?= $interactive ? '<p class="plan-hint">Tippt auf einen Tisch, um eure Gruppe dorthin zu setzen. Bei mehr als 4 Personen kommen die Nachbartische dazu.</p>' : '' ?>
      <div class="plan"><?= render_plan($occ, $interactive) ?></div>
      <?= plan_legend($editable || $res !== null) ?>
    </section>

    <aside class="side">
      <div class="card">
        <?php if ($res): ?>
          <p class="who">Reservierung #<?= $res['id'] ?></p>
          <h2>Eure Reservierung</h2>
          <p class="summary">
            <strong><?= h(display_name($res)) ?></strong> · <?= h(plural($res['seats'], 'Platz', 'Plätze')) ?><br>
            Tisch <?= h(alloc_label($res['alloc'])) ?>
          </p>
          <p class="hint">Mit diesem Link könnt ihr die Reservierung später wieder öffnen:<br>
            <a href="<?= h(change_url($res['code'])) ?>"><?= h(change_url($res['code'])) ?></a></p>
        <?php else: ?>
          <h2>Plätze reservieren</h2>
        <?php endif; ?>

        <?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>

        <?php if ($closed): ?>
          <p class="hint">Der Reservierungszeitraum ist beendet, Änderungen sind nicht mehr möglich.
            Bei Fragen schreibt uns an <a href="mailto:<?= h(cfg('contact_email')) ?>"><?= h(cfg('contact_email')) ?></a>.</p>
        <?php else: ?>
          <form id="res-form" method="post" action="./#res-form">
            <?= csrf_field() ?>
            <label for="seats"><?= $res ? 'Anzahl Plätze' : 'Wie viele Plätze möchtet ihr reservieren?' ?></label>
            <div class="seat-row">
              <input id="seats" name="seats" type="number" inputmode="numeric" min="<?= MIN_SEATS ?>" max="<?= cfg('max_seats') ?>"
                value="<?= $form['seats'] ?: '' ?>" required placeholder="<?= MIN_SEATS ?>–<?= cfg('max_seats') ?>">
              <button type="submit" name="action" value="propose" id="propose" formnovalidate><?= $alloc !== null ? 'Neu vorschlagen' : 'Plätze vorschlagen' ?></button>
            </div>

            <p class="counter" id="counter" aria-live="polite"><?= $alloc !== null ? 'Verteilt: ' . $allocSum . ' von ' . (int) $form['seats'] . ' Plätzen' : '' ?></p>

            <?php // Bis zum ersten Vorschlag ausgeblendet; mit JavaScript blendet plan.js den Teil ohne Neuladen ein ?>
            <div id="res-details"<?= $alloc === null ? ' hidden' : '' ?>>

              <?php // Zuteilung je Tisch – wird über Klicks im Saalplan gesetzt (assets/plan.js) ?>
              <?php foreach (plan_units() as $u => $_): $max = SEATS_PER_UNIT - $occ[$u]['taken']; if ($max > 0): ?>
                <input type="hidden" name="alloc[<?= $u ?>]" data-unit="<?= $u ?>" max="<?= $max ?>" value="<?= $occ[$u]['mine'] ?>">
              <?php endif; endforeach; ?>

              <div class="name-row">
                <div>
                  <label for="first_name">Vorname</label>
                  <input id="first_name" name="first_name" value="<?= h($form['first_name']) ?>" required maxlength="50" autocomplete="given-name">
                </div>
                <div>
                  <label for="last_name">Nachname</label>
                  <input id="last_name" name="last_name" value="<?= h($form['last_name']) ?>" required maxlength="50" autocomplete="family-name">
                </div>
              </div>
              <label for="phone">Telefonnummer</label>
              <input id="phone" name="phone" type="tel" value="<?= h($form['phone']) ?>" required maxlength="30" autocomplete="tel">
              <label for="email">E-Mail <span class="optional">(dorthin schicken wir die Bestätigung)</span></label>
              <input id="email" name="email" type="email" value="<?= h($form['email']) ?>" required maxlength="200" autocomplete="email">
              <button type="submit" name="action" value="save" id="save"><?= $res ? 'Änderungen speichern' : 'Verbindlich reservieren' ?></button>
            </div>
          </form>
        <?php endif; ?>
      </div>

      <div class="actions">
        <?php if ($res): ?>
          <button type="button" class="secondary" onclick="window.print()">Bestätigung drucken</button>
          <?php if (!$closed): ?>
            <form method="post" action="./" onsubmit="return confirm('Reservierung wirklich stornieren? Die Plätze werden sofort wieder freigegeben.')">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete">
              <button type="submit" class="secondary danger">Reservierung stornieren</button>
            </form>
          <?php endif; ?>
          <form method="post" action="./">
            <?= csrf_field() ?><input type="hidden" name="action" value="logout">
            <button type="submit" class="link">Schließen</button>
          </form>
        <?php else: ?>
          <p class="side-note">Schon reserviert? Zum Ändern oder Stornieren nutzt bitte den Link aus eurer Bestätigungsmail.</p>
        <?php endif; ?>
      </div>
    </aside>
  </div>
</main>
<?php page_footer(); ?>
