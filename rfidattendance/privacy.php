<?php
$title = "Datenschutz Richtlinie";
$css_extra = "css/Users.css";
ob_start();

/* Database connection settings */
if (file_exists("./config.php")) {
    $config = include "./config.php";
} else {
    $config = include "./config.sample.php";
}
?>
<h1 class="">Bitte einloggen</h1>

<!-- Log In -->
<section>
    <div>
        <h1>Datenschutzerklärung</h1>
        <h2>1. Verantwortliche Stelle</h2>
        <p>Verantwortlich im Sinne der Datenschutzgesetze ist:
        <p>
            <?= $config["operator"]["name"] ?><br>
            <?= $config["operator"]["address"] ?><br>
            <?= $config["operator"]["email"] ?><br>
            <?= $config["operator"]["telephone"] ?><br>
        </p>
        </p>
        <h2>2. Erhebung und Verarbeitung personenbezogener Daten</h2>
        <p>
            Wir erheben und verarbeiten personenbezogene Daten nur, soweit dies zur Bereitstellung
            einer funktionsfähigen Website sowie unserer Inhalte und Leistungen erforderlich ist.
            Die Verarbeitung erfolgt nur auf Grundlage Ihrer Einwilligung oder einer gesetzlichen Erlaubnis.
        </p>
        <h2>3. Cookies</h2>
        <p>
            Diese Website verwendet Cookies für technische Zwecke. Cookies sind kleine Textdateien,
            die auf Ihrem Computer gespeichert werden und die eine Analyse der Benutzung der Website
            ermöglichen. Sie dienen dazu, unsere Website nutzerfreundlicher, effektiver und sicherer zu machen.
        </p>
        <p>
            Sie können die Verwendung von Cookies in den Einstellungen Ihres Browsers kontrollieren.
            Bitte beachten Sie, dass die Deaktivierung von Cookies die Funktionalität dieser Website
            beeinträchtigen kann.
        </p>
        <h2>4. Login, Google Calendar und OAuth API</h2>
        <p>
            Der Login-Bereich sowie die Weitergabe von Daten an Google Calendar und die Nutzung von OAuth API
            sind optional. Wenn Sie sich dazu entscheiden, diese Funktionen zu nutzen, erklären Sie sich
            damit einverstanden, dass Ihre Daten entsprechend verwendet werden.
        </p>
        <h2>5. Speicherung der Daten</h2>
        <p>
            Alle auf dieser Website erfassten Daten werden nur auf dem entsprechenden Web-Server gespeichert und
            verbleiben innerhalb der EU.
        </p>
        <h2>6. Ihre Rechte</h2>
        <p>
            Sie haben das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung,
            Datenübertragbarkeit und Widerspruch. Bitte kontaktieren Sie uns hierzu unter <?= $config["operator"]["email"] ?>.
        </p>
        <h2>7. Änderungen dieser Datenschutzerklärung</h2>
        <p>
            Diese Datenschutzerklärung kann gelegentlich aktualisiert werden, um gesetzlichen Anforderungen oder Änderungen unserer Dienste Rechnung zu tragen. Änderungen werden auf dieser Seite veröffentlicht. Bitte überprüfen Sie regelmäßig die Datenschutzerklärung.
        </p>
        <p>
            Datum der letzten Aktualisierung: 29.01.2024
        </p>
    </div>
</section>

<?php
$html = ob_get_clean();
include "template/index.phtml"; ?>