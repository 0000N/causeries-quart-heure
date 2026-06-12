<?php
// ========================================
// Webhook de déploiement automatique
// Appelé par GitHub Actions ou cron
// ========================================
// Usage: https://hls-apps.fr/causeries/deploy.php?token=VOTRE_SECRET
// Protégé par un token secret défini ci-dessous

// === CONFIGURATION ===
// Changez ce token après déploiement !
$DEPLOY_TOKEN = 'causeries-auto-ad2ad479d6d07c9ebb1da44a';

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

// Vérifier que git est disponible
$gitBin = trim(shell_exec('which git 2>/dev/null') ?: '');
if (!$gitBin) {
    echo json_encode(['ok' => false, 'error' => 'git non trouvé sur le serveur']);
    exit;
}

// git pull
exec("$gitBin pull origin main 2>&1", $output, $exitCode);
if ($exitCode !== 0) {
    echo json_encode(['ok' => false, 'error' => 'git pull échoué', 'output' => implode("\n", $output)]);
    exit;
}

// Détecter le chemin de PHP
$phpBin = 'php';
$whichPhp = trim(shell_exec('which php8.2 2>/dev/null') ?: shell_exec('which php 2>/dev/null') ?: '');
if ($whichPhp) $phpBin = $whichPhp;

// Détecter le chemin de Composer
$composerBin = 'composer';
$whichComposer = trim(shell_exec('which composer 2>/dev/null') ?: '');
if ($whichComposer) {
    $composerBin = $whichComposer;
} elseif (file_exists('/usr/local/bin/composer')) {
    $composerBin = '/usr/local/bin/composer';
} elseif (file_exists('/usr/bin/composer')) {
    $composerBin = '/usr/bin/composer';
}

// Installer les dépendances Composer
exec("$phpBin $composerBin install --no-dev --prefer-dist 2>&1", $output, $exitCode);

echo json_encode([
    'ok' => true,
    'message' => 'Déploiement terminé',
    'output' => implode("\n", $output),
]);
