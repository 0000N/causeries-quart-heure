<?php
// ========================================
// Webhook de déploiement automatique
// Appelé par GitHub Actions ou cron
// ========================================
// Usage: https://hls-apps.fr/causeries/deploy.php?token=VOTRE_SECRET
// Protégé par un token secret défini ci-dessous

// === CONFIGURATION ===
// Changez ce token après déploiement !
$DEPLOY_TOKEN = 'causeries-deploy-2026';

// === SÉCURITÉ ===
header('Content-Type: application/json; charset=utf-8');

$token = $_GET['token'] ?? '';
if ($token !== $DEPLOY_TOKEN) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token invalide']);
    exit;
}

// === DÉPLOIEMENT ===
$output = [];
$exitCode = 0;

// Aller dans le dossier de l'application
$deployPath = __DIR__;
chdir($deployPath);

// git pull
exec('git pull origin main 2>&1', $output, $exitCode);
if ($exitCode !== 0) {
    echo json_encode(['ok' => false, 'error' => 'git pull échoué', 'output' => implode("\n", $output)]);
    exit;
}

// Installer les dépendances Composer
exec('/usr/local/bin/php8.2 /usr/local/bin/composer install --no-dev --prefer-dist 2>&1', $output, $exitCode);

echo json_encode([
    'ok' => true,
    'message' => 'Déploiement terminé',
    'output' => implode("\n", $output),
]);
