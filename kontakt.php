<?php
/**
 * LoggX – Kontaktformular-Versand
 * Läuft auf jedem Hoster mit PHP (IONOS, Strato, all-inkl, Hetzner …).
 * Nur die zwei Zeilen unter EINSTELLUNGEN anpassen.
 */

// ---------- EINSTELLUNGEN ----------
$empfaenger = 'constantinloggen@icloud.com';          // Hier kommen die Anfragen an
$absender   = 'noreply@loggx.de';          // Muss zur eigenen Domain gehören, sonst landet es im Spam
// -----------------------------------

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Nur POST erlaubt']);
    exit;
}

// Spam-Falle: unsichtbares Feld darf nicht ausgefüllt sein
if (!empty($_POST['_honey'])) {
    echo json_encode(['ok' => true]);
    exit;
}

$clean = function ($key, $max = 2000) {
    $v = isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
    $v = str_replace(["\r", "\n"], ' ', $v);           // Header-Injection verhindern
    return mb_substr(strip_tags($v), 0, $max);
};

$name    = $clean('name', 120);
$email   = $clean('email', 200);
$company = $clean('company', 200);
$plan    = $clean('plan', 100);
$message = isset($_POST['message']) ? trim(strip_tags((string) $_POST['message'])) : '';
$message = mb_substr($message, 0, 5000);

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bitte alle Pflichtfelder ausfüllen.']);
    exit;
}

$betreff = 'Anfrage über loggx.de' . ($plan !== '' ? ' – ' . $plan : '');

$text  = "Neue Anfrage über das Kontaktformular\n";
$text .= "======================================\n\n";
$text .= "Name:         $name\n";
$text .= "E-Mail:       $email\n";
$text .= "Firma:        " . ($company !== '' ? $company : '–') . "\n";
$text .= "Interesse an: " . ($plan !== '' ? $plan : 'Noch unentschieden') . "\n";
$text .= "Datum:        " . date('d.m.Y H:i') . "\n\n";
$text .= "Nachricht:\n----------\n$message\n";

$headers  = "From: LoggX Webseite <$absender>\r\n";
$headers .= "Reply-To: $name <$email>\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

$betreffEncoded = '=?UTF-8?B?' . base64_encode($betreff) . '?=';

if (mail($empfaenger, $betreffEncoded, $text, $headers)) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'E-Mail konnte nicht versendet werden.']);
}
