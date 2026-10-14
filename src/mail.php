<?php
// Bestätigungsmails per SMTP (PHPMailer). Ohne SMTP_HOST ist der Versand abgeschaltet.
// Fehler beim Versand brechen die Reservierung nie ab, sie landen nur im Log.

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../vendor/autoload.php';

function mail_enabled(): bool
{
    return cfg('smtp_host') !== '' && cfg('mail_from') !== '';
}

function app_url(): string
{
    if (cfg('app_url') !== '') {
        return rtrim(cfg('app_url'), '/') . '/';
    }
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (is_https() ? 'https' : 'http') . '://' . $host . '/';
}

/** Schickt eine Mail; gibt true zurück, wenn sie angenommen wurde. */
function send_mail(string $to, string $subject, string $text, string $html): bool
{
    if (!mail_enabled() || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $m = new PHPMailer(true);
    try {
        $m->isSMTP();
        $m->Host = cfg('smtp_host');
        $m->Port = cfg('smtp_port');
        $m->SMTPSecure = match (cfg('smtp_secure')) {
            'ssl'   => PHPMailer::ENCRYPTION_SMTPS,
            'none'  => '',
            default => PHPMailer::ENCRYPTION_STARTTLS,
        };
        $m->SMTPAutoTLS = cfg('smtp_secure') !== 'none';
        if (cfg('smtp_user') !== '') {
            $m->SMTPAuth = true;
            $m->Username = cfg('smtp_user');
            $m->Password = cfg('smtp_pass');
        }
        $m->Timeout = 15;
        $m->CharSet = PHPMailer::CHARSET_UTF8;
        $m->setFrom(cfg('mail_from'), cfg('mail_from_name'));
        $m->addReplyTo(cfg('contact_email'));
        $m->addAddress($to);
        if (cfg('mail_bcc') !== '') {
            $m->addBCC(cfg('mail_bcc'));
        }
        $m->Subject = $subject;
        $m->isHTML(true);
        $m->Body = $html;
        $m->AltBody = $text;
        $m->send();
        return true;
    } catch (Throwable $e) {
        error_log('mail: Versand an ' . $to . ' fehlgeschlagen: ' . $m->ErrorInfo);
        return false;
    }
}

/** Bestätigung nach Anlegen ($type = created), Ändern (changed) oder Stornieren (canceled). */
function send_reservation_mail(array $res, string $type): bool
{
    [$subject, $text, $html] = reservation_mail($res, $type);
    return send_mail($res['email'] ?? '', $subject, $text, $html);
}

/** @return array{0:string, 1:string, 2:string} Betreff, Text- und HTML-Fassung */
function reservation_mail(array $res, string $type): array
{
    $event = cfg('event_name');
    $url = $type === 'canceled' ? app_url() : change_url($res['code']);
    $deadline = deadline_label();
    $contact = cfg('contact_email');
    $first = $res['first_name'];

    [$subject, $intro] = match ($type) {
        'created'  => ["Eure Tischreservierung für den $event", 'vielen Dank, eure Tischreservierung ist eingegangen:'],
        'changed'  => ["Eure Tischreservierung wurde geändert – $event", 'eure Tischreservierung wurde geändert. So sieht sie jetzt aus:'],
        'canceled' => ["Eure Tischreservierung wurde storniert – $event", 'eure Tischreservierung wurde storniert, die Plätze sind wieder frei. Eure Tickets bleiben selbstverständlich gültig.'],
    };

    $rows = $type === 'canceled'
        ? [['Name', display_name($res)], ['Plätze', (string) $res['seats']]]
        : [['Name', display_name($res)], ['Plätze', (string) $res['seats']], ['Tisch', alloc_label($res['alloc'])], ['Änderungscode', $res['code']]];

    $notice = $type === 'canceled'
        ? ''
        : 'Bitte beachtet: Solltet ihr bis 19 Uhr nicht erscheinen, wird eure Reservierung aufgehoben und die Plätze werden anderweitig vergeben.';

    $footer = $type === 'canceled'
        ? "Ihr könnt bis zum $deadline jederzeit neu reservieren:"
        : "Ändern oder stornieren könnt ihr die Reservierung bis zum $deadline mit eurem Änderungscode oder direkt über diesen Link:";

    $text = "Hallo {$first},\n\n$intro\n\n";
    foreach ($rows as [$k, $v]) {
        $text .= mb_str_pad($k . ':', 16) . $v . "\n";
    }
    if ($notice !== '') {
        $text .= "\n$notice\n";
    }
    $text .= "\n$footer\n$url\n\nBei Fragen erreicht ihr uns unter $contact.\n\nWir freuen uns auf euch!\nEuer STAFERO e.V.\n";

    $font = 'font-family:Arial,sans-serif;font-size:15px;line-height:22px;color:#000;';
    $trs = '';
    foreach ($rows as [$k, $v]) {
        $trs .= '<tr><td style="' . $font . 'padding:6px 16px 6px 0;color:#616161;vertical-align:top;white-space:nowrap">' . h($k) . '</td>'
            . '<td style="' . $font . 'padding:6px 0;font-weight:bold">' . h($v) . '</td></tr>';
    }
    $content = '<p style="' . $font . 'margin:0 0 16px">Hallo ' . h($first) . ',</p>'
        . '<p style="' . $font . 'margin:0 0 16px">' . h($intro) . '</p>'
        . '<table border="0" cellpadding="0" cellspacing="0" style="margin:0 0 20px;border-left:6px solid #ffed00;padding-left:14px">' . $trs . '</table>'
        . ($notice !== '' ? '<p style="' . $font . 'margin:0 0 20px;padding:12px 14px;background-color:#fff7a6;font-weight:bold">' . h($notice) . '</p>' : '')
        . '<p style="' . $font . 'margin:0 0 16px">' . h($footer) . '</p>'
        . '<table border="0" cellpadding="0" cellspacing="0" style="margin:0 0 20px"><tr><td style="background-color:#000;padding:12px 22px">'
        . '<a href="' . h($url) . '" style="font-family:Arial,sans-serif;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none">'
        . ($type === 'canceled' ? 'Zur Tischreservierung' : 'Reservierung ansehen') . '</a></td></tr></table>'
        . '<p style="' . $font . 'margin:0 0 16px">Bei Fragen erreicht ihr uns unter <a href="mailto:' . h($contact) . '" style="color:#000">' . h($contact) . '</a>.</p>'
        . '<p style="' . $font . 'margin:0">Wir freuen uns auf euch!<br>Euer STAFERO e.V.</p>';

    $note = 'Ihr erhaltet diese E-Mail, weil mit dieser Adresse Plätze für den ' . $event . ' reserviert wurden.';
    $html = mail_layout($subject, $content, $note);

    return [$subject, $text, $html];
}

/**
 * Rahmen wie die üblichen STAFERO-Mails (Listmonk-Template): Logo, weißer Inhalt, schwarzer Footer.
 * Die Newsletter-Teile (Abmelden, Tracking) entfallen, weil es eine Transaktionsmail ist.
 */
function mail_layout(string $title, string $content, string $note): string
{
    $link = 'color:#fff;text-decoration:none';
    return '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . h($title) . '</title></head>'
        . '<body style="Margin:0;padding:0;background-color:#f4f4f4;">'
        . '<table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f4f4f4;padding:20px 0;"><tr><td align="center">'
        . '<table border="0" cellpadding="0" cellspacing="0" width="600" style="background-color:#ffffff;">'
        . '<tr><td align="center" style="background-color:#fff;padding:20px 0;">'
        . '<img src="https://static.stafero.de/StaferoNewsletterLogo.png" alt="STAFERO e.V." width="200" style="border:0;display:block;"/></td></tr>'
        . '<tr><td align="left" style="padding:30px;">' . $content . '</td></tr>'
        . '<tr><td style="background-color:#000;color:#ffffff;padding:20px 30px;font-family:Arial,sans-serif;font-size:12px;line-height:18px;text-align:center;">'
        . '<p style="margin:0;"><b>Stafero e.V.</b> | Postfach 30 01 16, 30944 Ronnenberg | '
        . '<a href="mailto:mitglieder@stafero.de" style="color:#ffffff;text-decoration:underline;">mitglieder@stafero.de</a></p>'
        . '<p style="margin:8px 0 0;">'
        . '<a href="https://www.stadtfest-ronnenberg.de/impressum/" style="' . $link . '">Impressum</a>&nbsp;&nbsp; '
        . '<a href="https://www.stadtfest-ronnenberg.de/datenschutz/" style="' . $link . '">Datenschutz</a>&nbsp;&nbsp; '
        . '<a href="https://www.stadtfest-ronnenberg.de/stafero-e-v/feedback/" style="' . $link . '">Feedback</a></p>'
        . '</td></tr></table></td></tr></table>'
        . '<div style="text-align:center;font-family:Arial,sans-serif;font-size:12px;color:#888;padding:0 20px 30px;"><p>' . h($note) . '</p></div>'
        . '</body></html>';
}
