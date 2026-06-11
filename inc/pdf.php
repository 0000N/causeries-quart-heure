<?php
/**
 * CAUSERIES 1/4h SÉCURITÉ - Génération PDF
 * 
 * Gère la génération de PDF pour les causeries et les documents de certification.
 * Utilise Dompdf pour le rendu HTML → PDF et chillerlan/php-qrcode pour les QR codes.
 * 
 * Fonctions exportées :
 *   - qrCode($data, $size = 200) : génère une chaîne base64 d'un QR code PNG
 *   - generateCauseriePDF($causerie) : PDF professionnel d'une causerie
 *   - generateCertificationPDF($email, $stats) : certificat / rapport de certification
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// === CHARGEMENT AUTOLOAD COMPOSER ===
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    throw new RuntimeException('Composer autoload introuvable. Exécutez "composer install" dans la racine du projet.');
}
require_once $autoload;

use Dompdf\Dompdf;
use Dompdf\Options as DompdfOptions;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions as QROptions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;

// ============================================================================
// QR CODE
// ============================================================================

/**
 * Génère un QR code PNG et le retourne en base64 (data URI).
 *
 * @param string $data  URL ou texte à encoder
 * @param int    $size  Taille en pixels (par défaut 200)
 * @return string       data:image/png;base64,...
 */
function qrCode(string $data, int $size = 200): string
{
    $options = new QROptions();
    $options->outputInterface = QRGdImagePNG::class;
    $options->eccLevel = EccLevel::L;   // 7% de correction, suffisant
    $options->scale = max(1, (int)ceil($size / 33)); // 33 modules pour v2-L
    $options->outputBase64 = false;
    $options->addQuietzone = true;
    $options->quietzoneSize = 2;

    $qrcode = new QRCode($options);
    $pngData = $qrcode->render($data);

    return 'data:image/png;base64,' . base64_encode($pngData);
}

// ============================================================================
// CAUSERIE PDF
// ============================================================================

/**
 * Génère un PDF professionnel pour une causerie.
 *
 * @param array $causerie Tableau associatif contenant :
 *   - id            : string   Identifiant unique
 *   - chantier      : string   Nom du chantier
 *   - animateur     : string   Nom de l'animateur
 *   - date_label    : string   Date formatée (ex: "15/06/2025")
 *   - date_iso      : string   Date ISO optionnelle
 *   - heure         : string   Heure optionnelle
 *   - lieu          : string   Lieu optionnel
 *   - duree         : string   Durée en minutes (défaut "15")
 *   - participants  : array    Liste des participants (objets avec 'name' et optionnellement 'signature')
 *   - themes        : array    Liste des thèmes (chaînes)
 *   - notes         : string   Notes / sujets abordés
 *   - signatures    : array    Liste signée (tableau de noms signés)
 *   - animateur_signature : string (optionnel) Signature animateur en base64
 *   - guide_data    : array    (optionnel) Données du guide
 * @return Dompdf   L'objet Dompdf généré (appeler ->output() ou ->stream() pour le PDF)
 */
function generateCauseriePDF(array $causerie): Dompdf
{
    // --- Normalisation des données ---
    $id           = $causerie['id'] ?? 'unknown';
    $chantier     = $causerie['chantier'] ?? '';
    $animateur    = $causerie['animateur'] ?? '';
    $dateLabel    = $causerie['date_label'] ?? date('d/m/Y');
    $heure        = $causerie['heure'] ?? '';
    $lieu         = $causerie['lieu'] ?? '';
    $duree        = $causerie['duree'] ?? '15';
    $notes        = $causerie['notes'] ?? '';
    $participants = $causerie['participants'] ?? [];
    $themes       = $causerie['themes'] ?? [];
    $signatures   = $causerie['signatures'] ?? [];
    $guideData    = $causerie['guide_data'] ?? null;

    // Liste des participants signés
    $signedNames = [];
    foreach ($signatures as $sig) {
        if (is_string($sig)) {
            $signedNames[] = $sig;
        } elseif (is_array($sig) && isset($sig['name'])) {
            $signedNames[] = $sig['name'];
        }
    }

    // Normaliser les participants
    $participantsList = [];
    foreach ($participants as $p) {
        if (is_string($p)) {
            $participantsList[] = ['name' => $p, 'signature' => null];
        } elseif (is_array($p)) {
            $participantsList[] = [
                'name'      => $p['name'] ?? 'Participant',
                'signature' => $p['signature'] ?? null,
            ];
        }
    }

    // --- QR Code ---
    $qrUrl       = rtrim(APP_URL, '/') . '/?c=' . urlencode($id);
    $qrDataUri   = qrCode($qrUrl, 180);

    // --- Couleurs des thèmes ---
    $themeColors = [
        'Sécurité'      => '#dc2626',
        'Securite'      => '#dc2626',
        'Environnement' => '#16a34a',
        'Santé'         => '#2563eb',
        'Sante'         => '#2563eb',
        'Sûreté'        => '#9333ea',
        'Surete'        => '#9333ea',
        'Qualité'       => '#d97706',
        'Qualite'       => '#d97706',
        'Autre'         => '#4f6f8f',
    ];

    // Tags des thèmes
    $themesHtml = '';
    foreach ($themes as $t) {
        $color = $themeColors[$t] ?? '#4f6f8f';
        $themesHtml .= sprintf(
            '<span style="display:inline-block;padding:2px 9px;font-size:8px;font-weight:700;border-radius:3px;color:#fff;background:%s;margin:0 2px 3px 0">%s</span>',
            $color,
            htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8')
        );
    }

    // --- Tableau des participants ---
    $participantsRows = '';
    foreach ($participantsList as $p) {
        $name       = htmlspecialchars($p['name'] ?? '', ENT_QUOTES, 'UTF-8');
        $isSigned   = in_array($p['name'], $signedNames, true);
        $sigDisplay = $isSigned
            ? '<span style="color:#16a34a;font-size:14px;font-weight:700">✓</span>'
            : '<span style="color:#94a3b8;font-size:12px">—</span>';

        $participantsRows .= sprintf(
            '<tr><td style="padding:3px 8px;font-size:8px;border-bottom:1px solid #e2e8f0">%s</td>'
            . '<td style="padding:3px 8px;font-size:8px;border-bottom:1px solid #e2e8f0;text-align:center;width:65px">%s</td></tr>',
            $name,
            $sigDisplay
        );
    }

    // --- Notes ---
    $notesSection = '';
    if (trim($notes) !== '') {
        $notesEscaped = htmlspecialchars($notes, ENT_QUOTES, 'UTF-8');
        $notesSection = sprintf(
            '<div class="section-title">Sujets abordés</div>'
            . '<div class="notes-box">%s</div>',
            nl2br($notesEscaped)
        );
    }

    // --- Guide data section (optionnelle) ---
    $guideHtml = '';
    if (is_array($guideData)) {
        $checks     = $guideData['checks'] ?? [];
        $hasIssues  = !empty($guideData['has_issues']);
        $nc         = $guideData['non_conformity'] ?? [];
        $ncTitle    = $nc['title'] ?? ($hasIssues ? 'Non-conformités détectées' : 'Tout est conforme');
        $ncMessage  = $nc['message'] ?? '';
        $ncActions  = $nc['actions'] ?? [];

        $checksHtml = '';
        foreach ($checks as $chk) {
            $done       = !empty($chk['done']);
            $chkLabel   = htmlspecialchars($chk['label'] ?? '', ENT_QUOTES, 'UTF-8');
            $badge      = $done
                ? '<span class="status-badge ok">✓</span>'
                : '<span class="status-badge no">✗</span>';
            $rowClass   = $done ? 'check-ok' : 'check-no';
            $checksHtml .= sprintf(
                '<tr class="%s"><td style="text-align:center;width:28px">%s</td>'
                . '<td style="font-size:7.5px;padding:2.5px 7px">%s</td></tr>',
                $rowClass, $badge, $chkLabel
            );
        }

        $actionsHtml = '';
        if ($hasIssues && !empty($ncActions)) {
            $actionItems = '';
            $i = 1;
            foreach ($ncActions as $a) {
                $actionItems .= sprintf(
                    '<div class="action-item"><span class="action-num">%d</span><span>%s</span></div>',
                    $i, htmlspecialchars((string)$a, ENT_QUOTES, 'UTF-8')
                );
                $i++;
            }
            $actionsHtml = sprintf(
                '<div style="margin-top:4px"><div class="section-title">Actions à mener</div>'
                . '<div class="section-body"><div class="actions-list">%s</div></div></div>',
                $actionItems
            );
        }

        $topicLabel = htmlspecialchars($guideData['topic_label'] ?? '', ENT_QUOTES, 'UTF-8');
        $confClass  = $hasIssues ? 'issues' : 'ok';
        $confIcon   = $hasIssues ? '⊕' : '✓';

        if ($checksHtml !== '') {
            $guideHtml = sprintf(
                '<div class="section-title">Checklist terrain<span style="font-weight:400;font-size:7px"> — %s</span></div>'
                . '<div class="section-body" style="padding:0;border-top:1.5px solid #1e3a5f">'
                . '<table class="checklist-table"><thead><tr>'
                . '<th style="width:28px;text-align:center">Statut</th><th>Action</th>'
                . '</tr></thead><tbody>%s</tbody></table></div>'
                . '<div class="section-title">Conformité</div>'
                . '<div class="conformity-card %s">'
                . '<div class="c-title">%s %s</div>'
                . '<div class="c-msg">%s</div></div>'
                . '%s',
                $topicLabel,
                $checksHtml,
                $confClass,
                $confIcon,
                htmlspecialchars($ncTitle, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($ncMessage, ENT_QUOTES, 'UTF-8'),
                $actionsHtml
            );
        }
    }

    // --- Animateur signature ---
    $animSigHtml = '';
    $animSigRaw  = $causerie['animateur_signature'] ?? '';
    if ($animSigRaw !== '') {
        // Si c'est une data URI, l'utiliser directement
        $src = (strpos($animSigRaw, 'data:') === 0) ? $animSigRaw : 'data:image/png;base64,' . $animSigRaw;
        $animSigHtml = sprintf(
            '<div class="anim-sig"><strong>Signature de l\'animateur :</strong><br><img src="%s" style="max-height:24px;margin-top:2px"></div>',
            $src
        );
    }

    // --- Nombre de participants ---
    $numParticipants = count($participantsList);

    // --- Date d'émission ---
    $emissionDate = date('d/m/Y');

    // --- Construction du HTML ---
    $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
    @page {
        size: A4;
        margin: 10mm 11mm 8mm 11mm;
        @bottom-left {
            content: "quart-heure.fr";
            font-size: 6px;
            color: #94a3b8;
            font-family: 'DejaVu Sans', sans-serif;
        }
        @bottom-right {
            content: "Page " counter(page);
            font-size: 6px;
            color: #94a3b8;
            font-family: 'DejaVu Sans', sans-serif;
        }
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif;
        color: #1e293b;
        font-size: 8px;
        line-height: 1.25;
    }
    /* ===== HEADER GRADIENT BANNER ===== */
    .header-banner {
        display: flex;
        margin-bottom: 5px;
        background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 40%, #312e81 100%);
        border-radius: 4px;
        overflow: hidden;
    }
    .header-logo {
        background: rgba(0,0,0,0.3);
        padding: 6px 12px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-width: 52px;
    }
    .header-logo .qhs-text {
        font-size: 20px;
        font-weight: 900;
        color: #ffffff;
        letter-spacing: 1.5px;
        line-height: 1;
    }
    .header-logo .qhs-sub {
        font-size: 5px;
        color: #99bbdd;
        letter-spacing: 2.5px;
        text-transform: uppercase;
        margin-top: 1.5px;
    }
    .header-main {
        flex: 1;
        padding: 6px 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .header-main .title-block .title {
        font-size: 11px;
        font-weight: 700;
        color: #ffffff;
    }
    .header-main .title-block .subtitle {
        font-size: 6.5px;
        color: #b8d0e8;
        margin-top: 1px;
    }
    .header-main .header-meta {
        text-align: right;
        font-size: 6.5px;
        color: #c8ddf0;
        line-height: 1.35;
    }
    /* ===== SECTION HEADERS ===== */
    .section-title {
        font-size: 7.5px;
        font-weight: 700;
        color: #ffffff;
        background: #1e3a5f;
        display: inline-block;
        padding: 1.5px 10px;
        border-radius: 3px 3px 0 0;
        margin-top: 4.5px;
        margin-bottom: 0;
        letter-spacing: 0.5px;
    }
    .section-body {
        border: 1.5px solid #1e3a5f;
        border-top: none;
        border-radius: 0 3px 3px 3px;
        padding: 4px 7px;
        background: #ffffff;
    }
    /* ===== INFO GRID ===== */
    .info-grid {
        display: flex;
        flex-wrap: wrap;
    }
    .info-grid .info-item {
        width: 50%;
        display: flex;
        padding: 1.5px 0;
    }
    .info-grid .info-item-full {
        width: 100%;
        display: flex;
        padding: 1.5px 0;
    }
    .info-grid .info-label {
        width: 58px;
        font-size: 6.5px;
        color: #64748b;
        font-weight: 600;
        flex-shrink: 0;
    }
    .info-grid .info-value {
        flex: 1;
        font-size: 8px;
        font-weight: 600;
        color: #0f172a;
    }
    .themes-tags {
        display: inline-flex;
        flex-wrap: wrap;
        gap: 2px;
    }
    /* ===== NOTES ===== */
    .notes-box {
        background: #fffbeb;
        border: 1px solid #fde68a;
        border-left: 3px solid #f59e0b;
        padding: 4px 7px;
        margin-top: 2px;
        font-size: 7.5px;
        line-height: 1.3;
        white-space: pre-wrap;
        color: #451a03;
        border-radius: 0 3px 3px 3px;
    }
    /* ===== PARTICIPANTS TABLE ===== */
    .participants-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 2px;
    }
    .participants-table thead th {
        background: #1e3a5f;
        color: #ffffff;
        padding: 2.5px 8px;
        font-size: 6.5px;
        font-weight: 600;
        text-align: left;
    }
    .participants-table thead th:first-child {
        border-radius: 3px 0 0 0;
    }
    .participants-table thead th:last-child {
        border-radius: 0 3px 0 0;
    }
    .participants-table tbody tr:nth-child(even) {
        background: #f0f4ff;
    }
    .participants-table tbody tr:nth-child(odd) {
        background: #ffffff;
    }
    /* ===== CHECKLIST ===== */
    .checklist-table {
        width: 100%;
        border-collapse: collapse;
    }
    .checklist-table thead th {
        background: #334155;
        color: #ffffff;
        padding: 2px 7px;
        font-size: 6.5px;
        font-weight: 600;
        text-align: left;
    }
    .checklist-table tbody td {
        vertical-align: middle;
    }
    .checklist-table tbody tr.check-ok td {
        background: #f0fdf4;
        border-bottom: 1px solid #bbf7d0;
    }
    .checklist-table tbody tr.check-no td {
        background: #fef2f2;
        border-bottom: 1px solid #fecaca;
    }
    .status-badge {
        display: inline-block;
        font-size: 10px;
        width: 20px;
        height: 20px;
        line-height: 20px;
        text-align: center;
        border-radius: 50%;
        font-weight: 700;
    }
    .status-badge.ok {
        background: #16a34a;
        color: #ffffff;
    }
    .status-badge.no {
        background: #dc2626;
        color: #ffffff;
    }
    /* ===== CONFORMITY CARD ===== */
    .conformity-card {
        margin-top: 3px;
        border-radius: 4px;
        padding: 4px 8px;
        border-left: 4px solid;
    }
    .conformity-card.ok {
        background: #f0fdf4;
        border-color: #16a34a;
    }
    .conformity-card.issues {
        background: #fef2f2;
        border-color: #dc2626;
    }
    .conformity-card .c-title {
        font-size: 8px;
        font-weight: 700;
    }
    .conformity-card.ok .c-title {
        color: #166534;
    }
    .conformity-card.issues .c-title {
        color: #991b1b;
    }
    .conformity-card .c-msg {
        font-size: 7px;
        color: #475569;
        margin-top: 1px;
        line-height: 1.2;
    }
    .actions-list {
        margin-top: 3px;
    }
    .actions-list .action-item {
        display: flex;
        align-items: flex-start;
        gap: 4px;
        padding: 1.5px 0;
        font-size: 7px;
    }
    .actions-list .action-num {
        width: 14px;
        height: 14px;
        background: #dc2626;
        color: #ffffff;
        font-size: 7px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        border-radius: 3px;
    }
    /* ===== BOTTOM SECTION ===== */
    .bottom-section {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        margin-top: 4px;
        border-top: 1.5px solid #e2e8f0;
        padding-top: 3px;
    }
    .qr-block {
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .qr-block img {
        width: 38px;
        height: 38px;
    }
    .qr-block .qr-text {
        font-size: 6px;
        color: #64748b;
        line-height: 1.25;
    }
    .qr-block .qr-text strong {
        color: #1e3a5f;
        font-size: 6.5px;
    }
    .footer-text {
        font-size: 5.5px;
        color: #94a3b8;
        text-align: right;
        line-height: 1.25;
    }
    /* ===== ANIMATEUR SIGNATURE ===== */
    .anim-sig {
        margin-top: 3px;
        padding: 3px 8px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 3px;
        font-size: 7px;
        color: #475569;
    }
    .anim-sig strong {
        color: #1e293b;
        font-size: 7.5px;
    }
    .anim-sig img {
        max-height: 22px;
        margin-top: 1px;
    }
    .num-participants {
        font-size: 6.5px;
        color: #b8d0e8;
        font-weight: 400;
    }
</style>
</head>
<body>

    <!-- ===== EN-TÊTE GRADIENT ===== -->
    <div class="header-banner">
        <div class="header-logo">
            <div class="qhs-text">¼h</div>
            <div class="qhs-sub">Sécurité</div>
        </div>
        <div class="header-main">
            <div class="title-block">
                <div class="title">1/4h Sécurité — Causerie</div>
                <div class="subtitle">Sous-section 3 — Document d'enregistrement</div>
            </div>
            <div class="header-meta">
                <div>N° {$id}</div>
                <div>Émis le {$emissionDate}</div>
            </div>
        </div>
    </div>

    <!-- ===== INFORMATIONS ===== -->
    <div class="section-title">Informations</div>
    <div class="section-body">
    <div class="info-grid">
        <div class="info-item"><span class="info-label">Chantier</span><span class="info-value">{$chantier}</span></div>
        <div class="info-item"><span class="info-label">Animateur</span><span class="info-value">{$animateur}</span></div>
        <div class="info-item"><span class="info-label">Date</span><span class="info-value">{$dateLabel}</span></div>
        <div class="info-item"><span class="info-label">Participants</span><span class="info-value">{$numParticipants}</span></div>
HTML;

    // Ligne duree
    if ($duree !== '') {
        $html .= sprintf(
            '<div class="info-item"><span class="info-label">Durée</span><span class="info-value">%s min</span></div>',
            htmlspecialchars($duree, ENT_QUOTES, 'UTF-8')
        );
    }

    // Ligne lieu
    if ($lieu !== '') {
        $html .= sprintf(
            '<div class="info-item"><span class="info-label">Lieu</span><span class="info-value">%s</span></div>',
            htmlspecialchars($lieu, ENT_QUOTES, 'UTF-8')
        );
    }

    $html .= <<<HTML
        <div class="info-item-full"><span class="info-label">Thèmes</span><span class="info-value"><div class="themes-tags">{$themesHtml}</div></span></div>
    </div>
    </div>

    <!-- ===== SUJETS ABORDÉS ===== -->
    {$notesSection}

    <!-- ===== PARTICIPANTS ===== -->
    <div class="section-title">Participants <span class="num-participants">({$numParticipants})</span></div>
    <table class="participants-table">
        <thead><tr><th>Nom & Prénom</th><th style="width:65px">Signé</th></tr></thead>
        <tbody>{$participantsRows}</tbody>
    </table>

    {$animSigHtml}

    {$guideHtml}

    <!-- ===== QR CODE + PIED DE PAGE ===== -->
    <div class="bottom-section">
        <div class="qr-block">
            <img src="{$qrDataUri}" alt="QR Code">
            <div class="qr-text">
                <strong>Accès en ligne</strong><br>
                Scannez pour consulter<br>
                la causerie sur 1/4h Sécurité
            </div>
        </div>
        <div class="footer-text">
            1/4h Sécurité — Sous-section 3<br>
            1/4h Sécurité · Document officiel<br>
            quart-heure.fr
        </div>
    </div>

</body>
</html>
HTML;

    // --- Génération du PDF ---
    $dompdfOptions = new DompdfOptions();
    $dompdfOptions->set('isRemoteEnabled', true);
    $dompdfOptions->set('isHtml5ParserEnabled', true);
    $dompdfOptions->set('defaultFont', 'DejaVu Sans');
    $dompdfOptions->set('tempDir', sys_get_temp_dir());

    $dompdf = new Dompdf($dompdfOptions);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return $dompdf;
}

// ============================================================================
// CERTIFICATION PDF
// ============================================================================

/**
 * Génère un rapport de certification / certificat de formation.
 *
 * @param string $email Email de l'utilisateur
 * @param array  $stats Statistiques de certification :
 *   - total               : int    Nombre total de causeries
 *   - last_causerie       : array  Dernière causerie (chantier, date_label, themes)
 *   - streak              : int    Jours consécutifs / série
 *   - certification_level : string Niveau de certification (Bronze, Argent, Or, Platinum)
 *   - conformite          : array  [niveau => string, label => string]
 *   - chantiers           : array  [chantier => count]
 *   - themes              : array  [theme => count]
 *   - participants_uniques: int
 *   - total_chantiers     : int
 *   - par_mois            : array  [{mois => string, count => int}]
 * @return Dompdf L'objet Dompdf généré
 */
function generateCertificationPDF(string $email, array $stats): Dompdf
{
    // --- Extraction des données ---
    $total              = $stats['total'] ?? 0;
    $lastCauserie       = $stats['last_causerie'] ?? null;
    $streak             = $stats['streak'] ?? 0;
    $certLevel          = $stats['certification_level'] ?? 'Débutant';
    $conformite         = $stats['conformite'] ?? ['niveau' => 'rouge', 'label' => 'Données insuffisantes'];
    $chantiersCount     = $stats['chantiers'] ?? [];
    $themesCount        = $stats['themes'] ?? [];
    $participantsUniq   = $stats['participants_uniques'] ?? 0;
    $totalChantiers     = $stats['total_chantiers'] ?? count($chantiersCount);
    $parMois            = $stats['par_mois'] ?? [];

    $conformiteNiveau = $conformite['niveau'] ?? 'rouge';
    $conformiteLabel  = $conformite['label'] ?? '';
    $conformiteColor  = match ($conformiteNiveau) {
        'vert'   => '#16a34a',
        'orange' => '#d97706',
        default  => '#ef4444',
    };

    // --- QR Code ---
    $qrUrl     = rtrim(APP_URL, '/') . '/certification?email=' . urlencode($email);
    $qrDataUri = qrCode($qrUrl, 180);

    // --- Date ---
    $dateGeneration = date('d/m/Y');

    // --- Graphique barres par mois ---
    $barsHtml = '';
    if (!empty($parMois)) {
        $maxCount = max(array_column($parMois, 'count'));
        if ($maxCount < 1) $maxCount = 1;

        foreach ($parMois as $mois) {
            $moisKey   = htmlspecialchars($mois['mois'] ?? '', ENT_QUOTES, 'UTF-8');
            $cnt       = (int)($mois['count'] ?? 0);
            $pct       = ($cnt / $maxCount) * 100;
            $barsHtml .= sprintf(
                '<div style="display:flex;align-items:center;margin-bottom:4px">'
                . '<div style="width:60px;font-size:11px;color:#64748b">%s</div>'
                . '<div style="flex:1;background:#e2e8f0;border-radius:4px;height:20px;overflow:hidden">'
                . '<div style="height:100%%;width:%.1f%%;background:#1e3a5f;border-radius:4px;min-width:16px"></div></div>'
                . '<div style="width:30px;text-align:right;font-size:12px;font-weight:600;color:#1e3a5f">%d</div></div>',
                $moisKey, $pct, $cnt
            );
        }
    }

    // --- Répartition chantiers ---
    $chHtml = '';
    if (!empty($chantiersCount)) {
        arsort($chantiersCount);
        foreach ($chantiersCount as $ch => $cnt) {
            $pct = $total > 0 ? round(($cnt / $total) * 100) : 0;
            $chHtml .= sprintf(
                '<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #f1f5f9;font-size:13px">'
                . '<span>%s</span><span style="font-weight:600;color:#1e3a5f">%d (%d%%)</span></div>',
                htmlspecialchars((string)$ch, ENT_QUOTES, 'UTF-8'),
                $cnt, $pct
            );
        }
    }

    // --- Répartition thèmes ---
    $thHtml = '';
    if (!empty($themesCount)) {
        arsort($themesCount);
        foreach ($themesCount as $th => $cnt) {
            $pct = $total > 0 ? round(($cnt / $total) * 100) : 0;
            $thHtml .= sprintf(
                '<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #f1f5f9;font-size:13px">'
                . '<span>%s</span><span style="font-weight:600;color:#1e3a5f">%d (%d%%)</span></div>',
                htmlspecialchars((string)$th, ENT_QUOTES, 'UTF-8'),
                $cnt, $pct
            );
        }
    }

    // --- Dernière causerie ---
    $derniereHtml = '';
    if ($lastCauserie) {
        $lastChantier  = htmlspecialchars($lastCauserie['chantier'] ?? '', ENT_QUOTES, 'UTF-8');
        $lastDate      = htmlspecialchars($lastCauserie['date_label'] ?? '', ENT_QUOTES, 'UTF-8');
        $lastThemes    = $lastCauserie['themes'] ?? [];
        $lastThemesHtml = '';
        foreach ($lastThemes as $t) {
            $lastThemesHtml .= sprintf(
                '<span style="display:inline-block;padding:3px 8px;border-radius:8px;background:#e8f0fe;font-size:11px;margin:0 2px 2px 0">%s</span>',
                htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8')
            );
        }
        $derniereHtml = <<<HTML
        <div class="section-title">Dernière causerie</div>
        <div class="info-row"><span class="lbl">Date</span><span class="val">{$lastDate}</span></div>
        <div class="info-row"><span class="lbl">Chantier</span><span class="val">{$lastChantier}</span></div>
        <div class="info-row"><span class="lbl">Thèmes</span><span class="val">{$lastThemesHtml}</span></div>
HTML;
    }

    // --- Niveau certification ---
    $levelStars = match ($certLevel) {
        'Bronze'   => '★☆☆☆',
        'Argent'   => '★★☆☆',
        'Or'       => '★★★☆',
        'Platinum' => '★★★★',
        default    => '☆☆☆☆',
    };

    $levelColor = match ($certLevel) {
        'Bronze'   => '#cd7f32',
        'Argent'   => '#9ca3af',
        'Or'       => '#f59e0b',
        'Platinum' => '#6366f1',
        default    => '#94a3b8',
    };

    // --- Construction HTML ---
    $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
    @page {
        size: A4;
        margin: 20mm 15mm 20mm 15mm;
        @bottom-center {
            content: "Document généré par l'application 1/4h Sécurité — Sous-section 3 — Rapport de certification";
            font-size: 9px;
            color: #888;
            font-family: 'DejaVu Sans', sans-serif;
        }
    }
    body {
        font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif;
        color: #1e293b;
        font-size: 13px;
        line-height: 1.5;
    }
    .header {
        display: flex;
        align-items: center;
        gap: 14px;
        border-bottom: 3px solid #1e3a5f;
        padding-bottom: 12px;
        margin-bottom: 18px;
    }
    .header .logo { font-size: 36px; }
    .header .title { font-size: 20px; font-weight: 700; color: #1e3a5f; }
    .header .subtitle { font-size: 13px; color: #64748b; }
    .section-title {
        font-size: 12px;
        font-weight: 700;
        color: #1e3a5f;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: 18px;
        margin-bottom: 8px;
        border-bottom: 2px solid #1e3a5f;
        padding-bottom: 4px;
    }
    .stats-grid {
        display: flex;
        gap: 12px;
        margin-bottom: 14px;
    }
    .stat-box {
        flex: 1;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 12px 8px;
        text-align: center;
    }
    .stat-box .num { font-size: 26px; font-weight: 700; color: #1e3a5f; }
    .stat-box .lbl { font-size: 10px; color: #64748b; margin-top: 2px; }
    .conformite-box {
        padding: 12px 16px;
        border-radius: 8px;
        color: white;
        font-weight: 600;
        font-size: 14px;
        text-align: center;
    }
    .level-box {
        padding: 10px 14px;
        border-radius: 8px;
        color: white;
        font-weight: 700;
        font-size: 18px;
        text-align: center;
        margin-top: 10px;
    }
    .level-box .stars { font-size: 22px; letter-spacing: 3px; }
    .level-box .label { font-size: 14px; font-weight: 600; margin-top: 2px; }
    .info-row { display: flex; padding: 4px 0; font-size: 13px; }
    .info-row .lbl { width: 100px; color: #64748b; font-weight: 600; flex-shrink: 0; }
    .info-row .val { flex: 1; font-weight: 500; }
    .qr-section {
        margin-top: 24px;
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 12px;
        padding: 14px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
    }
    .qr-section img { width: 70px; height: 70px; }
    .qr-section .qr-text { font-size: 11px; color: #64748b; }
    .qr-section .qr-text strong { display: block; color: #1e3a5f; font-size: 13px; }
    .email-display {
        font-size: 14px;
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 4px;
    }
</style>
</head>
<body>

    <div class="header">
        <div class="logo">🛡️</div>
        <div>
            <div class="title">Rapport de Certification</div>
            <div class="subtitle">Sous-section 3 — 1/4h Sécurité</div>
        </div>
        <div style="margin-left:auto;text-align:right;font-size:11px;color:#64748b">
            Généré le {$dateGeneration}
        </div>
    </div>

    <div class="email-display">{$email}</div>

    <div class="section-title">Niveau de Certification</div>
    <div class="level-box" style="background:{$levelColor}">
        <div class="stars">{$levelStars}</div>
        <div class="label">{$certLevel}</div>
    </div>

    <div class="section-title">Indicateur de Conformité</div>
    <div class="conformite-box" style="background:{$conformiteColor}">
        {$conformiteLabel}
    </div>

    <div class="section-title">Vue d'ensemble</div>
    <div class="stats-grid">
        <div class="stat-box"><div class="num">{$total}</div><div class="lbl">Causeries</div></div>
        <div class="stat-box"><div class="num">{$totalChantiers}</div><div class="lbl">Chantiers</div></div>
        <div class="stat-box"><div class="num">{$participantsUniq}</div><div class="lbl">Participants</div></div>
    </div>

HTML;

    if ($barsHtml !== '') {
        $html .= <<<HTML
    <div class="section-title">Fréquence par mois</div>
    {$barsHtml}
HTML;
    }

    if ($chHtml !== '') {
        $html .= <<<HTML
    <div class="section-title">Répartition par chantier</div>
    {$chHtml}
HTML;
    }

    if ($thHtml !== '') {
        $html .= <<<HTML
    <div class="section-title">Répartition par thème</div>
    {$thHtml}
HTML;
    }

    $html .= $derniereHtml;

    $html .= <<<HTML

    <div class="qr-section">
        <img src="{$qrDataUri}" alt="QR Code">
        <div class="qr-text">
            <strong>Consultez votre certification en ligne</strong>
            Scannez ce QR code pour accéder à votre tableau de bord
        </div>
    </div>

</body>
</html>
HTML;

    // --- Génération du PDF ---
    $dompdfOptions = new DompdfOptions();
    $dompdfOptions->set('isRemoteEnabled', true);
    $dompdfOptions->set('isHtml5ParserEnabled', true);
    $dompdfOptions->set('defaultFont', 'DejaVu Sans');
    $dompdfOptions->set('tempDir', sys_get_temp_dir());

    $dompdf = new Dompdf($dompdfOptions);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return $dompdf;
}
