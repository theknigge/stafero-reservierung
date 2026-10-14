<?php
// SQLite-Speicher. Pro Reservierung werden Tische mit Platzanzahl gespeichert (reservation_seats);
// dass an einem Tisch nie mehr als 4 Plätze vergeben sind, prüft db_save() in einer exklusiven Transaktion.

final class SeatConflict extends RuntimeException
{
    /** @param array<string,int> $free Tisch => tatsächlich noch freie Plätze */
    public function __construct(public readonly array $free)
    {
        parent::__construct('seats taken: ' . implode(',', array_keys($free)));
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $path = cfg('db_path');
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS reservations (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            code       TEXT    NOT NULL UNIQUE,   -- Änderungscode, z. B. "K7M2QX"
            first_name TEXT    NOT NULL,
            last_name  TEXT    NOT NULL,
            phone      TEXT    NOT NULL,
            phone_norm TEXT    NOT NULL,
            email      TEXT    NOT NULL DEFAULT \'\',
            seats      INTEGER NOT NULL,
            created_at TEXT    NOT NULL,
            updated_at TEXT    NOT NULL
        );
        CREATE TABLE IF NOT EXISTS reservation_seats (
            reservation_id INTEGER NOT NULL REFERENCES reservations(id) ON DELETE CASCADE,
            unit           TEXT    NOT NULL,      -- z. B. "A3"
            count          INTEGER NOT NULL CHECK (count BETWEEN 1 AND 4),
            PRIMARY KEY (reservation_id, unit)
        );
        CREATE INDEX IF NOT EXISTS reservation_seats_unit ON reservation_seats(unit);
        CREATE TABLE IF NOT EXISTS blocked_units (
            unit       TEXT PRIMARY KEY,          -- vom Admin gesperrter Tisch, z. B. "A1"
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS failed_attempts (
            ip TEXT NOT NULL,
            at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS failed_attempts_ip ON failed_attempts(ip, at);
    ');
    return $pdo;
}

function db_in(array $values): string
{
    return implode(',', array_fill(0, count($values), '?'));
}

function db_get_reservation(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
    $st->execute([$id]);
    $res = $st->fetch();
    return $res ? db_attach($pdo, [$res])[0] : null;
}

function db_find_by_code(PDO $pdo, string $code): ?array
{
    $st = $pdo->prepare('SELECT * FROM reservations WHERE code = ?');
    $st->execute([$code]);
    $res = $st->fetch();
    return $res ? db_attach($pdo, [$res])[0] : null;
}

/** Alle Reservierungen inkl. Tischen, neueste zuerst. */
function db_all(PDO $pdo): array
{
    return db_attach($pdo, $pdo->query('SELECT * FROM reservations ORDER BY id DESC')->fetchAll());
}

/** Hängt jeder Reservierung 'alloc' => ['A1' => 4, 'A2' => 2] an. */
function db_attach(PDO $pdo, array $rows): array
{
    if (!$rows) {
        return [];
    }
    $byId = [];
    foreach ($rows as $r) {
        $r['id'] = (int) $r['id'];
        $r['seats'] = (int) $r['seats'];
        $r['alloc'] = [];
        $byId[$r['id']] = $r;
    }
    $ids = array_keys($byId);
    $st = $pdo->prepare('SELECT reservation_id, unit, count FROM reservation_seats WHERE reservation_id IN (' . db_in($ids) . ')');
    $st->execute($ids);
    foreach ($st as $row) {
        $byId[(int) $row['reservation_id']]['alloc'][$row['unit']] = (int) $row['count'];
    }
    foreach ($byId as &$r) {
        $r['alloc'] = sort_alloc($r['alloc']);
    }
    return array_values($byId);
}

/** Belegte Plätze je Tisch ohne die Reservierung $exceptId: ['A1' => 4, 'B2' => 2, ...]. Gesperrte Tische zählen als voll. */
function db_taken_seats(PDO $pdo, ?int $exceptId = null): array
{
    $st = $pdo->prepare('SELECT unit, SUM(count) FROM reservation_seats WHERE reservation_id != ? GROUP BY unit');
    $st->execute([$exceptId ?? 0]);
    $taken = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    foreach (db_blocked_units($pdo) as $unit) {
        $taken[$unit] = SEATS_PER_UNIT;
    }
    return $taken;
}

/** Vom Admin gesperrte Tische in Plan-Reihenfolge: ['A1', 'C3', ...] */
function db_blocked_units(PDO $pdo): array
{
    return sort_units($pdo->query('SELECT unit FROM blocked_units')->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Sperrt einen Tisch bzw. gibt ihn wieder frei. Gesperrt werden kann nur ein Tisch ohne Reservierungen.
 * @return string|null Fehlermeldung oder null
 */
function db_toggle_block(PDO $pdo, string $unit): ?string
{
    if (!isset(plan_units()[$unit])) {
        return 'Unbekannter Tisch.';
    }
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $st = $pdo->prepare('DELETE FROM blocked_units WHERE unit = ?');
        $st->execute([$unit]);
        if ($st->rowCount() === 0) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM reservation_seats WHERE unit = ?');
            $st->execute([$unit]);
            if ((int) $st->fetchColumn() > 0) {
                $pdo->exec('ROLLBACK');
                return "Tisch $unit ist schon (teilweise) reserviert und kann nicht gesperrt werden.";
            }
            $pdo->prepare('INSERT INTO blocked_units (unit, created_at) VALUES (?, ?)')->execute([$unit, date('Y-m-d H:i:s')]);
        }
        $pdo->exec('COMMIT');
        return null;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/** Freie Plätze je Tisch (aus Sicht der Reservierung $exceptId). */
function db_free_seats(PDO $pdo, ?int $exceptId = null): array
{
    $taken = db_taken_seats($pdo, $exceptId);
    $free = [];
    foreach (plan_units() as $u => $_) {
        $free[$u] = SEATS_PER_UNIT - ($taken[$u] ?? 0);
    }
    return $free;
}

function db_new_code(PDO $pdo): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // ohne 0/O und 1/I
    $st = $pdo->prepare('SELECT 1 FROM reservations WHERE code = ?');
    do {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $st->execute([$code]);
    } while ($st->fetchColumn());
    return $code;
}

/**
 * Legt eine Reservierung an ($id = null) oder ändert sie. Läuft in einer exklusiven Transaktion,
 * damit zwei gleichzeitige Buchungen einen Tisch nicht überbelegen.
 * @param array<string,int> $alloc Tisch => Plätze
 */
function db_save(PDO $pdo, ?int $id, array $data, array $alloc): int
{
    $now = date('Y-m-d H:i:s');
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $free = db_free_seats($pdo, $id);
        $conflicts = [];
        foreach ($alloc as $unit => $n) {
            if ($n > $free[$unit]) {
                $conflicts[$unit] = max(0, $free[$unit]);
            }
        }
        if ($conflicts) {
            throw new SeatConflict(sort_alloc($conflicts));
        }

        $values = [$data['first_name'], $data['last_name'], $data['phone'], $data['phone_norm'], $data['email'], array_sum($alloc), $now];
        if ($id === null) {
            $pdo->prepare('INSERT INTO reservations (code, first_name, last_name, phone, phone_norm, email, seats, updated_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([db_new_code($pdo), ...$values, $now]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $st = $pdo->prepare('UPDATE reservations SET first_name = ?, last_name = ?, phone = ?, phone_norm = ?, email = ?, seats = ?, updated_at = ? WHERE id = ?');
            $st->execute([...$values, $id]);
            if ($st->rowCount() === 0) {
                throw new RuntimeException('Reservierung existiert nicht mehr.');
            }
            $pdo->prepare('DELETE FROM reservation_seats WHERE reservation_id = ?')->execute([$id]);
        }

        $ins = $pdo->prepare('INSERT INTO reservation_seats (reservation_id, unit, count) VALUES (?, ?, ?)');
        foreach ($alloc as $unit => $n) {
            $ins->execute([$id, $unit, $n]);
        }
        $pdo->exec('COMMIT');
        return $id;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function db_delete(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM reservations WHERE id = ?')->execute([$id]);
}

// --- einfache Bremse gegen Durchprobieren von Änderungscodes ---

const FAIL_WINDOW = 900;  // 15 Minuten
const FAIL_LIMIT = 20;

function db_fail_count(PDO $pdo, string $ip): int
{
    $pdo->prepare('DELETE FROM failed_attempts WHERE at < ?')->execute([time() - FAIL_WINDOW]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM failed_attempts WHERE ip = ?');
    $st->execute([$ip]);
    return (int) $st->fetchColumn();
}

function db_register_fail(PDO $pdo, string $ip): void
{
    $pdo->prepare('INSERT INTO failed_attempts (ip, at) VALUES (?, ?)')->execute([$ip, time()]);
    usleep(700000);
}
