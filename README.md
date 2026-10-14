# STAFERO-Jubiläumsball – Tischreservierung

Gäste reservieren ohne Anmeldung Plätze für den STAFERO-Jubiläumsball. PHP 8.3 + Apache, Daten in SQLite.
Design wie [static.stafero.de/film](https://static.stafero.de/film/).

## Regeln und Ablauf

- 1 Tisch = 4 Plätze. Reserviert wird **pro Platz**: mindestens 2, höchstens `MAX_SEATS` (Standard: 20) Plätze.
  Ein Tisch kann zwischen mehreren Gruppen geteilt werden, z. B. 2 + 2.
- Es gibt keine Prüfung von Bestell- oder Ticketnummern.
- Ablauf: Gäste geben die Anzahl Plätze ein und klicken auf „Plätze vorschlagen“. Die App verteilt die Plätze
  automatisch auf möglichst wenige, nebeneinanderliegende Tische (bevorzugt an derselben Tafel, angebrochene Tische
  werden zuerst aufgefüllt). Ein Klick auf einen Tisch setzt die ganze Gruppe dorthin (bei mehr als 4 Personen
  inkl. Nachbartischen), eine geänderte Anzahl wird sofort neu verteilt – alles ohne Neuladen. Danach geben sie Vorname, Nachname, Telefonnummer und E-Mail-Adresse ein (alles Pflichtfelder).
- Nach dem Speichern bekommt jede Reservierung einen **Änderungscode** (6 Zeichen) und einen persönlichen Link
  (`/?code=…`). Er wird auf der Seite, in der Bestätigung und in der Mail angezeigt. Damit lässt sich die Reservierung
  bis `DEADLINE` (Standard: 08.11.2026, 23:59 Uhr) ändern oder stornieren, danach nur noch ansehen.
- Gleichzeitige Buchungen: Der Vorschlag reserviert noch nichts. Beim Speichern prüft `db_save()` die freien Plätze in
  einer exklusiven SQLite-Transaktion (`BEGIN IMMEDIATE`), eine zweite Buchung wartet so lange. War jemand schneller,
  bekommt der Zweite sofort einen neuen Vorschlag angezeigt und muss nur noch einmal speichern.

## Saalplan und Nummerierung

51 Tische, 204 Plätze (nach `Stafero-Ball Tische.pdf`, die 24er-Tafeln sind durch 20er ersetzt).
Buchstabe = Tafel, Ziffer = Tisch, gezählt ab der Wand (1 = Wandseite):

| Tafeln | Lage          | Plätze je Tafel | Tische                   |
|--------|---------------|-----------------|--------------------------|
| A – E  | oben, längs   | 20              | 1 – 5 (1 = hintere Wand) |
| F – K  | links, schräg | 12              | 1 – 3 (1 = linke Wand)   |
| L – O  | rechts        | 8               | 1 – 2 (1 = rechte Wand)  |

Den Übersichtsplan zum Ausdrucken findest du unter [docs/saalplan-tischnummern.pdf](docs/saalplan-tischnummern.pdf).
Die Aufteilung steht in `src/layout.php`.

## Admin-Bereich (`/admin.php`)

- Kennzahlen, Saalplan mit Namen (Tooltip), Belegung nach Tisch („2× Müller, Vanessa“) und Liste aller
  Reservierungen inkl. Änderungscode; Reservierungen löschen
- **CSV-Export** (Excel-tauglich, Semikolon)
- **Schilder drucken**: ein A4-Blatt (quer) pro belegtem Tisch mit Tischnummer und allen Gruppen an diesem Tisch,
  z. B. „2× Müller, Vanessa“ / „2× Mustermann, Max“. Im Druckdialog die Ränder auf „Keine“ stellen und
  Hintergrundgrafiken einschalten.
- **Saalplan mit Tischnummern** als Druckansicht (A4 hoch)

## Bestätigungsmails

Die E-Mail-Adresse ist Pflicht. Beim Anlegen, Ändern und Stornieren geht eine Bestätigung mit Änderungscode und Link an diese Adresse,
optional mit Kopie an `MAIL_BCC`. Antworten gehen an `CONTACT_EMAIL`. Kann eine Mail nicht verschickt werden, bleibt die
Reservierung trotzdem gespeichert; der Fehler steht im Container-Log (`mail: Versand an … fehlgeschlagen`).
Ohne `SMTP_HOST` werden keine Mails verschickt.

Die Mails haben dasselbe Layout wie die übrigen STAFERO-Mails (Logo von static.stafero.de, schwarzer Footer mit
Postfach, Impressum, Datenschutz und Feedback), siehe `mail_layout()` in `src/mail.php`.

## Deployment in Easypanel

1. Neuen Service vom Typ **App** anlegen, Quelle: GitHub `theknigge/stafero-reservierung`, Branch `main`,
   Build: **Dockerfile**.
2. Unter **Mounts** ein Volume anlegen: Mount-Pfad `/data`. Dort liegt die SQLite-Datenbank. Ohne Volume sind alle
   Reservierungen nach dem nächsten Deploy weg!
3. Unter **Environment** die Variablen aus [.env.example](.env.example) setzen, mindestens `ADMIN_PASSWORD`
   und `APP_URL` (wird für den persönlichen Änderungslink gebraucht), für Mails außerdem die `SMTP_*`- und `MAIL_*`-Variablen.
4. Unter **Domains** die Domain eintragen, Port **80**.

Backup: `/data/reservierungen.sqlite` sichern (zusätzlich `-wal`/`-shm`, falls vorhanden), oder regelmäßig den
CSV-Export ziehen.

## Lokal entwickeln

```sh
docker compose up --build
```

Die Seite läuft dann unter http://localhost:8088, der Admin-Bereich unter `/admin.php` (Passwort `test`).
Die Daten liegen in `./data`. Lokal werden keine Mails verschickt (`SMTP_HOST` ist leer).
