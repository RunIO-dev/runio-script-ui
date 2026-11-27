<?php

declare(strict_types=1);

/**
 * The built-in texts in German. The ids and their meaning are in en.php.
 */
return [
    // The page around a tool
    'index.title' => 'Werkzeuge',
    'page.all_tools' => 'Alle Werkzeuge',
    'page.reset' => 'Zurücksetzen',
    'page.reset_hint' => 'Die gespeicherten Eingaben dieses Werkzeugs vergessen',
    'page.update' => 'Aktualisieren',
    'page.offline' => 'Der Server hat nicht geantwortet. Prüfen Sie, ob er läuft, und versuchen Sie es erneut. Falls Sie abgemeldet wurden, laden Sie die Seite neu.',
    'index.empty' => 'Noch keine Werkzeuge. Legen Sie eine PHP-Datei in {folder} ab, dann erscheint sie hier.',

    // The question a button asks first
    'confirm.title' => 'Bitte bestätigen',
    'confirm.yes' => 'Ja',
    'confirm.no' => 'Abbrechen',

    // Tables
    'table.empty' => 'Keine Zeilen.',
    'table.select' => 'Auswählen',
    'table.select_row' => 'Zeile {row} auswählen',
    'table.rows_one' => '1 Zeile',
    'table.rows' => '{count} Zeilen',
    'table.range' => 'Zeilen {from} bis {to} von {count}',
    'table.selected' => '{count} ausgewählt',
    'table.selected_elsewhere' => '{count} ausgewählt ({hidden} auf anderen Seiten)',
    'table.selected_max' => 'Es können höchstens {count} Zeilen ausgewählt werden.',
    'table.pages' => 'Seiten',
    'table.page' => 'Seite {page} von {pages}',
    'table.previous' => 'Zurück',
    'table.next' => 'Weiter',
    'cell.yes' => 'Ja',
    'cell.no' => 'Nein',
    'number.thousands' => '.',

    // Charts
    'chart.empty' => 'Keine Daten zum Zeichnen.',
    'chart.data' => 'Daten',
    'chart.rows_of' => '{shown} von {count} Zeilen',
    'chart.bar' => 'Säulendiagramm: {series} nach {axis}',
    'chart.line' => 'Liniendiagramm: {series} nach {axis}',

    // A tool that failed
    'failure.text' => 'Beim Ausführen dieses Werkzeugs ist ein Fehler aufgetreten. Die Details stehen im Fehlerprotokoll des Servers.',
    'failure.trace' => 'Stack-Trace',

    // Pages that only say something
    'error.not_found' => 'Nicht gefunden',
    'error.no_address' => 'Unter dieser Adresse gibt es nichts.',
    'error.no_tool' => 'Unter dieser Adresse gibt es kein Werkzeug.',
    'error.local_only' => 'Nur auf diesem Computer verfügbar',
    'error.local_only_text' => "Es ist keine Anmeldeprüfung eingerichtet. Deshalb antworten diese Werkzeuge nur einem Browser auf dem Computer, auf dem sie laufen (http://localhost), und nicht über einen Proxy oder einen Tunnel.\n\n"
        . 'Für den Zugriff von anderswo braucht es eine Anmeldeprüfung: Übergeben Sie auth: an Runio\ScriptUi\serve() oder an Runio\ScriptUi\configure(). '
        . 'Sie erhält die Anfrage und gibt den Namen des Benutzers zurück, oder null, um abzulehnen.',
    'error.login_failed' => 'Die Anmeldeprüfung ist fehlgeschlagen',
    'error.login_failed_text' => 'Bis sie wieder funktioniert, wird niemand eingelassen. Die Details stehen im Fehlerprotokoll des Servers.',
    'error.denied' => 'Nicht erlaubt',
    'error.denied_text' => 'Sie haben keinen Zugriff auf diese Seite.',
    'error.method' => 'Methode nicht erlaubt',
    'error.method_text' => 'Diese Seite beantwortet GET- und POST-Anfragen.',
    'error.expired' => 'Diese Seite ist abgelaufen',
    'error.expired_text' => 'Ihre Sitzung ist beendet, oder das Formular wurde nicht von dieser Seite gesendet. Öffnen Sie die Seite erneut und wiederholen Sie den Vorgang.',
    'error.expired_link' => 'Seite erneut öffnen',
];
