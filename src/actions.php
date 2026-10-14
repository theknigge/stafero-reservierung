<?php
// Formular-Aktionen der Gästeseite. Jede Funktion gibt null (Erfolg) oder eine Fehlermeldung zurück.
//
// Reservieren kann jeder ohne Anmeldung. Wer reserviert hat, bekommt einen Änderungscode (und einen Link damit);
// mit dem Code lässt sich die Reservierung später ändern oder stornieren.
// Session: $_SESSION['res_id'] = geöffnete Reservierung.

function msg_closed(): string
{
    return 'Der Reservierungszeitraum ist am ' . deadline_label() . ' zu Ende gegangen. Bei Fragen schreibt uns an '
        . cfg('contact_email') . '.';
}

function normalize_code(string $raw): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
}

function action_login(PDO $pdo, string $raw): ?string
{
    unset($_SESSION['res_id']);
    if (db_fail_count($pdo, client_ip()) >= FAIL_LIMIT) {
        return 'Zu viele Fehlversuche. Bitte wartet 15 Minuten oder schreibt uns an ' . cfg('contact_email') . '.';
    }
    $code = normalize_code($raw);
    $res = $code !== '' ? db_find_by_code($pdo, $code) : null;
    if (!$res) {
        db_register_fail($pdo, client_ip());
        return 'Diesen Änderungscode kennen wir nicht. Ihr findet ihn in eurer Bestätigung (z. B. „K7M2QX“).';
    }
    session_regenerate_id(true);
    $_SESSION['res_id'] = $res['id'];
    return null;
}

/** Prüft die gewünschte Platzzahl; gibt eine Fehlermeldung oder null zurück. */
function check_seat_count(int $seats): ?string
{
    if ($seats < MIN_SEATS || $seats > cfg('max_seats')) {
        return sprintf('Ihr könnt zwischen %d und %d Plätze reservieren.', MIN_SEATS, cfg('max_seats'));
    }
    return null;
}

/**
 * Berechnet einen Vorschlag für $seats Plätze (eigene Plätze von $resId gelten dabei als frei).
 * @return array{0:?string, 1:?array} Fehlermeldung, Zuteilung
 */
function action_propose(PDO $pdo, int $seats, ?int $resId): array
{
    if (($err = check_seat_count($seats)) !== null) {
        return [$err, null];
    }
    $alloc = auto_assign($seats, db_free_seats($pdo, $resId));
    if ($alloc === null) {
        return [sprintf('Leider sind keine %d Plätze mehr frei. Bei Fragen schreibt uns an %s.', $seats, cfg('contact_email')), null];
    }
    return [null, $alloc];
}

/** Liest die Zuteilung aus dem Formular: nur gültige Tische mit mindestens einem Platz. */
function parse_alloc(array $raw): array
{
    $units = plan_units();
    $alloc = [];
    foreach ($raw as $unit => $n) {
        $n = (int) $n;
        if (isset($units[$unit]) && $n > 0) {
            $alloc[$unit] = min($n, SEATS_PER_UNIT);
        }
    }
    return sort_alloc($alloc);
}

/**
 * @param array{seats:int, alloc:array, first_name:string, last_name:string, phone:string, email:string} $form
 * War jemand schneller (gleichzeitige Buchung), wird $form['alloc'] durch einen neuen Vorschlag ersetzt.
 */
function action_save(PDO $pdo, array &$form): ?string
{
    if (is_closed()) {
        return msg_closed();
    }
    $resId = $_SESSION['res_id'] ?? null;

    if (($err = check_seat_count($form['seats'])) !== null) {
        return $err;
    }
    $sum = array_sum($form['alloc']);
    if ($sum !== $form['seats']) {
        return sprintf('Ihr habt %s im Plan verteilt, möchtet aber %s reservieren. Bitte passt die Auswahl an.',
            plural($sum, 'Platz', 'Plätze'), plural($form['seats'], 'Platz', 'Plätze'));
    }

    $first = preg_replace('/\s+/u', ' ', trim($form['first_name']));
    $last = preg_replace('/\s+/u', ' ', trim($form['last_name']));
    if ($first === '' || $last === '' || mb_strlen($first) > 50 || mb_strlen($last) > 50) {
        return 'Bitte gebt Vor- und Nachnamen an, auf die reserviert wird.';
    }
    $phone = trim($form['phone']);
    $phoneNorm = normalize_phone($phone);
    if (!preg_match('#^[0-9+()/\s.\-]{6,30}$#', $phone) || strlen($phoneNorm) < 6 || strlen($phoneNorm) > 20) {
        return 'Bitte gebt eine gültige Telefonnummer an.';
    }
    $email = trim($form['email']);
    if ($email === '') {
        return 'Bitte gebt eure E-Mail-Adresse an, damit wir euch die Bestätigung schicken können.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) {
        return 'Bitte prüft die E-Mail-Adresse.';
    }

    try {
        $id = db_save($pdo, $resId, [
            'first_name' => $first,
            'last_name'  => $last,
            'phone'      => $phone,
            'phone_norm' => $phoneNorm,
            'email'      => $email,
        ], $form['alloc']);
    } catch (SeatConflict $e) {
        $msg = 'Da war jemand schneller: An Tisch ' . implode(', ', array_keys($e->free)) . ' wurde gerade reserviert. ';
        $alloc = auto_assign($form['seats'], db_free_seats($pdo, $resId));
        if ($alloc === null) {
            return $msg . sprintf('Leider sind keine %d Plätze mehr frei.', $form['seats']);
        }
        $form['alloc'] = $alloc;
        return $msg . 'Wir haben euch neue Plätze vorgeschlagen (Tisch ' . alloc_label($alloc) . ') – bitte prüfen und noch einmal speichern.';
    }

    $isNew = $resId === null;
    $_SESSION['res_id'] = $id;
    $res = db_get_reservation($pdo, $id);
    $_SESSION['flash'] = $isNew
        ? 'Eure Reservierung ist gespeichert. Euer Änderungscode lautet ' . $res['code'] . ' – bitte notiert ihn euch!'
        : 'Eure Änderungen sind gespeichert.';
    if (send_reservation_mail($res, $isNew ? 'created' : 'changed')) {
        $_SESSION['flash'] .= ' Eine Bestätigung haben wir an ' . $email . ' geschickt.';
    }
    return null;
}

function action_delete(PDO $pdo): ?string
{
    $resId = $_SESSION['res_id'] ?? null;
    if (!$resId) {
        return 'Es ist keine Reservierung geöffnet.';
    }
    if (is_closed()) {
        return msg_closed();
    }
    $res = db_get_reservation($pdo, $resId);
    db_delete($pdo, $resId);
    unset($_SESSION['res_id']);
    $_SESSION['flash'] = 'Eure Reservierung wurde storniert. Die Plätze sind wieder frei.';
    if ($res) {
        send_reservation_mail($res, 'canceled');
    }
    return null;
}
