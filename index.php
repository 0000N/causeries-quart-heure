<?php
// ========================================
// CAUSERIES 1/4h SÉCURITÉ - Point d'entrée unique
// ========================================
session_set_cookie_params([
    'lifetime' => 86400 * 7, // 7 jours
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/database.php';

// Initialiser la DB au premier lancement
initDB();

// Détection du sous-dossier (ex: /causeries pour hébergement en sous-répertoire)
$scriptName = $_SERVER['SCRIPT_NAME'];
$basePath = rtrim(dirname($scriptName), '/');
define('BASE_PATH', $basePath);

// Router
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Supprimer le préfixe du sous-dossier si présent
if (BASE_PATH && strpos($uri, BASE_PATH) === 0) {
    $uri = substr($uri, strlen(BASE_PATH));
}
$uri = rtrim($uri, '/') ?: '/';

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
if ($method === 'OPTIONS') { http_response_code(204); exit; }

// === HEALTH CHECK ===
if ($uri === '/api/health' && $method === 'GET') {
    try {
        $db = getDB();
        $db->query('SELECT 1');
        $dbStatus = 'connected';
    } catch (Exception $e) {
        $dbStatus = 'error';
    }
    jsonResponse([
        'ok' => true,
        'php_version' => PHP_VERSION,
        'db' => $dbStatus,
    ]);
}

// === ROUTES API ===
if (strpos($uri, '/api/') === 0) {
    require __DIR__ . '/inc/routes_api.php';
    exit;
}

// === ROUTES FRONTEND ===
switch ($uri) {
    case '/':
        serveTemplate('animateur.html');
        break;
    case '/prevention':
        serveTemplate('prevention.html');
        break;
    default:
        // Servir les fichiers statiques (sous /static/)
        if (strpos($uri, '/static/') === 0) {
            $relativePath = substr($uri, 8);
            $staticPath = STATIC_DIR . $relativePath;
            if (file_exists($staticPath) && !is_dir($staticPath)) {
                $ext = pathinfo($staticPath, PATHINFO_EXTENSION);
                $mimeTypes = [
                    'css' => 'text/css',
                    'js' => 'application/javascript',
                    'png' => 'image/png',
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'gif' => 'image/gif',
                    'svg' => 'image/svg+xml',
                    'ico' => 'image/x-icon',
                    'json' => 'application/json',
                    'webmanifest' => 'application/manifest+json',
                ];
                $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
                header('Content-Type: ' . $mime);
                readfile($staticPath);
                exit;
            }
        }
        // Photo uploads (sous /uploads/photos/)
        if (strpos($uri, '/uploads/photos/') === 0) {
            $photoPath = substr($uri, 16);
            $photoFull = UPLOAD_DIR . $photoPath;
            if (file_exists($photoFull) && !is_dir($photoFull)) {
                header('Content-Type: image/jpeg');
                readfile($photoFull);
                exit;
            }
        }
        http_response_code(404);
        echo '404 - Page non trouvée';
}

function serveTemplate($name) {
    $path = TEMPLATE_DIR . $name;
    if (!file_exists($path)) {
        http_response_code(404);
        echo 'Template non trouvé';
        return;
    }
    if (DEBUG) {
        error_log('Serving template: ' . $name);
    }
    $html = file_get_contents($path);
    // Injecter BASE_PATH pour les appels JS en sous-dossier
    $basePathJs = json_encode(BASE_PATH);
    $inject = "<script>window.BASE_PATH=$basePathJs;</script>\n";
    $html = str_replace('<head>', "<head>\n$inject", $html);
    // Remplacer les chemins absolus /api/ et /static/ par des chemins relatifs au sous-dossier
    if (BASE_PATH) {
        $html = str_replace("'/api/", "window.BASE_PATH+'/api/", $html);
        $html = str_replace('href="/static/', 'href="' . BASE_PATH . '/static/', $html);
        $html = str_replace("'/static/", "window.BASE_PATH+'/static/", $html);
    }
    echo $html;
}
