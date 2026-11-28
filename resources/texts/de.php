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
    'index.none_allowed' => 'Hier gibt es kein Werkzeug, das Sie öffnen dürfen.',
    'index.in_web_root' => 'Der Ordner mit den Werkzeugen, {folder}, liegt im Web-Root. Der Webserver kann eine Werkzeugdatei über ihren Namen direkt ausführen, und dann werden die Anmeldeprüfung und die Zugriffsprüfung von serve() nicht ausgeführt. Legen Sie den Ordner außerhalb des Web-Roots ab oder sperren Sie ihn im Webserver. Sie sehen diesen Hinweis, weil hier Fehlerdetails angezeigt werden.',

    // The question a button asks first
    'confirm.title' => 'Bitte bestätigen',
    'confirm.yes' => 'Ja',
    'confirm.no' => 'Abbrechen',

    // A page that runs again by itself
    'refresh.updated' => 'Aktualisiert um {time}',
    'refresh.paused' => 'Angehalten',
    'refresh.pause' => 'Anhalten',
    'refresh.resume' => 'Fortsetzen',

    // Tables
    'table.empty' => 'Keine Zeilen.',
    'table.select_page' => 'Alle Zeilen dieser Seite auswählen',
    'table.clear' => 'Auswahl aufheben',
    'table.select_row' => 'Zeile {row} auswählen',
    'table.search' => 'Suchen',
    'table.search_label' => 'Diese Tabelle durchsuchen',
    'table.no_match' => 'Keine Zeile passt zur Suche.',
    'table.rows_one' => '1 Zeile',
    'table.rows' => '{count} Zeilen',
    'table.range' => 'Zeilen {from} bis {to} von {count}',
    'table.range_open' => 'Zeilen {from} bis {to}',
    'table.filtered' => '{rows} (gefiltert aus {count})',
    'table.selected' => '{count} ausgewählt',
    'table.selected_elsewhere' => '{count} ausgewählt ({hidden} auf anderen Seiten)',
    'table.selected_hidden' => '{count} weitere von der Suche ausgeblendet',
    'table.selected_max' => 'Es können höchstens {count} Zeilen ausgewählt werden.',
    'table.selected_page' => '{count} auf dieser Seite ausgewählt',
    'table.unselected_one' => '1 ausgewählte Zeile steht nicht auf dieser Seite und ist nicht mehr ausgewählt.',
    'table.unselected' => '{count} ausgewählte Zeilen stehen nicht auf dieser Seite und sind nicht mehr ausgewählt.',
    'table.pages' => 'Seiten',
    'table.page' => 'Seite {page} von {pages}',
    'table.page_open' => 'Seite {page}',
    'table.previous' => 'Zurück',
    'table.next' => 'Weiter',
    'cell.yes' => 'Ja',
    'cell.no' => 'Nein',
    'number.thousands' => '.',
    'number.decimal' => ',',

    // Choices
    'choices.none' => 'Nichts zur Auswahl.',

    // What is wrong with a field. Read out in front of the message, and not shown.
    'field.error' => 'Fehler:',

    // Dateien
    'file.remove' => 'Entfernen',
    'file.remove_label' => '{name} entfernen',
    'file.types' => 'Erlaubt: {types}.',
    'file.limit' => 'Bis {size}.',
    'file.too_large' => '„{name}“ ist größer als {size}.',
    'file.wrong_type' => '„{name}“ ist hier nicht erlaubt. Erlaubt: {types}.',
    'file.too_many' => 'Hier passen höchstens {count} Dateien hinein.',
    'file.failed' => '„{name}“ ist nicht angekommen. Bitte noch einmal versuchen.',
    'file.gone' => 'Eine Datei, die hier war, ist nicht mehr da. Bitte noch einmal auswählen.',
    'size.b' => '{n} B',
    'size.kb' => '{n} KB',
    'size.mb' => '{n} MB',
    'size.gb' => '{n} GB',

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
    'error.tool_denied_text' => 'Sie dürfen dieses Werkzeug nicht öffnen.',
    'error.access_failed' => 'Die Zugriffsprüfung ist fehlgeschlagen',
    'error.access_failed_text' => 'Bis sie wieder funktioniert, wird niemand eingelassen. Die Details stehen im Fehlerprotokoll des Servers.',
    'error.method' => 'Methode nicht erlaubt',
    'error.method_text' => 'Diese Seite beantwortet GET- und POST-Anfragen.',
    'error.expired' => 'Diese Seite ist abgelaufen',
    'error.expired_text' => 'Ihre Sitzung ist beendet, oder das Formular wurde nicht von dieser Seite gesendet. Öffnen Sie die Seite erneut und wiederholen Sie den Vorgang.',
    'error.expired_link' => 'Seite erneut öffnen',
    'error.too_large' => 'Zu groß',
    'error.too_large_text' => 'Das ist mehr, als der Server auf einmal annimmt. Wählen Sie eine kleinere Datei oder weniger Dateien.',
    'error.too_large_link' => 'Zurück zur Seite',
];
