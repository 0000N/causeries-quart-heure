<?php
// Configuration de l'application Causeries 1/4h Sécurité
define('ROOT_DIR', dirname(__DIR__));
define('DB_PATH', ROOT_DIR . '/data.db');
define('UPLOAD_DIR', ROOT_DIR . '/uploads/photos/');
define('TEMPLATE_DIR', ROOT_DIR . '/templates/');
define('STATIC_DIR', ROOT_DIR . '/static/');
define('APP_URL', 'https://hls-apps.fr/causeries');
define('DEBUG', false);

// Créer le dossier uploads s'il n'existe pas
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// Prévention : emails autorisés
$PREVENTION_EMAILS = ['0000@mailo.com', 'prevention@exemple.fr'];
