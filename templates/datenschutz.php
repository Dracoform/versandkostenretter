<?php
/** @var string $pageTitle */
require __DIR__ . '/layout_header.php';
?>
<section class="static-page">
  <h1>Datenschutz</h1>

  <div class="notice notice-todo">
    <p><strong>TODO (Betreiber):</strong> Die folgenden Abschnitte enthalten noch
    Platzhalter. Verantwortliche Stelle, Kontaktmöglichkeit und ggf. Hostinger-/Plesk-Hostingdetails
    müssen vom Betreiber ergänzt werden.</p>
  </div>

  <h2>Verantwortliche Stelle</h2>
  <p class="placeholder">TODO: Name und Anschrift des Verantwortlichen</p>

  <h2>Kontakt</h2>
  <p class="placeholder">TODO: Kontaktmöglichkeit (z. B. E-Mail)</p>

  <h2>Umfang der Datenverarbeitung</h2>
  <p>
    Dieser Dienst verarbeitet personenbezogene Daten nur, soweit technisch
    notwendig. Es werden <strong>keine Cookies für Tracking oder Werbung</strong>
    gesetzt, <strong>keine Analyse-Tools</strong> eingesetzt und
    <strong>keine Daten an Dritte</strong> weitergegeben.
  </p>
  <p>
    Aus rein technischen Gründen wird eine einzige Session-Variable genutzt, die
    ein zufälliges Sicherheits-Token (CSRF-Schutz) enthält. Diese Session wird
    nicht dazu verwendet, Nutzer zu verfolgen oder zu profilieren und automatisch
    gelöscht.
  </p>

  <h2>Server-Logfiles</h2>
  <p class="placeholder">
    TODO: Vom Hosting-Anbieter (Netcup/Plesk) erhobene Server-Logdaten sowie
    Speicherdauer und Rechtsgrundlage hier beschreiben.
  </p>

  <h2>Ihre Rechte</h2>
  <p>
    Sie haben das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der
    Verarbeitung, Datenübertragbarkeit und Widerspruch. TODO: konkrete
    Kontaktdaten für Anfragen ergänzen.
  </p>
</section>
<?php require __DIR__ . '/layout_footer.php'; ?>
