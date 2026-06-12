<?php
// ========================================
// CAUSERIES 1/4h SÉCURITÉ - Routes API
// ========================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// Autoload Composer pour QR code et DomPDF
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;

// ========================================
// HELPERS
// ========================================

function generateUUID(): string {
    $data = bin2hex(random_bytes(16));
    return substr($data, 0, 8) . '-' .
           substr($data, 8, 4) . '-' .
           substr($data, 12, 4) . '-' .
           substr($data, 16, 4) . '-' .
           substr($data, 20, 12);
}

function isPreventionEmail(string $email): bool {
    global $PREVENTION_EMAILS;
    return in_array($email, $PREVENTION_EMAILS);
}

function causerieRowToApi(array $r): array {
    $participants = json_decode($r['participants'] ?? '[]', true) ?: [];
    if (!empty($participants) && is_string($participants[0] ?? null)) {
        $participants = array_map(function($p) {
            return ['name' => $p, 'signature' => null];
        }, $participants);
    }
    return [
        'id'                  => $r['id'],
        'email'               => $r['email'],
        'chantier'            => $r['chantier'],
        'themes'              => json_decode($r['themes'] ?? '[]', true) ?: [],
        'notes'               => $r['notes'] ?? '',
        'participants'        => $participants,
        'animateur_signature' => $r['animateur_signature'] ?? null,
        'animateur'           => explode('@', $r['email'])[0],
        'date_iso'            => $r['date_iso'],
        'date_label'          => $r['date_label'],
        'created_at'          => $r['created_at'] ?? null,
        'guide_data'          => $r['guide_data'] ? json_decode($r['guide_data'], true) : null,
        'status'              => $r['status'] ?? 'pending',
        'validated_by'        => $r['validated_by'] ?? null,
        'validated_at'        => $r['validated_at'] ?? null,
        'validated_notes'     => $r['validated_notes'] ?? '',
    ];
}

function parseDateLabel(string $dateIso): string {
    $ts = strtotime($dateIso);
    if ($ts === false) return $dateIso;
    $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $j = $jours[(int)date('w', $ts)];
    $m = $mois[(int)date('n', $ts) - 1];
    return $j . ' ' . date('j', $ts) . ' ' . $m . ' ' . date('Y', $ts);
}

// ========================================
// ROUTING
// ========================================

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/') ?: '/';
// Supprimer BASE_PATH si présent (sous-dossier)
if (defined('BASE_PATH') && BASE_PATH && strpos($uri, BASE_PATH) === 0) {
    $uri = substr($uri, strlen(BASE_PATH));
    $uri = rtrim($uri, '/') ?: '/';
}
$query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? '';
parse_str($query, $params);

// Vérifier si un administrateur existe
function hasAdmin(): bool {
    $db = getDB();
    $stmt = $db->query("SELECT COUNT(*) FROM profiles WHERE role = 'admin'");
    return (int)$stmt->fetchColumn() > 0;
}

try {

// ========================================
// AUTHENTIFICATION
// ========================================

// POST /api/register — Création du premier compte (admin)
if ($uri === '/api/register' && $method === 'POST') {
    $data = jsonInput();
    $email = trim(strtolower($data['email'] ?? ''));
    $password = $data['password'] ?? '';

    if (!$email || !str_contains($email, '@')) {
        jsonResponse(['ok' => false, 'error' => 'Email invalide'], 400);
    }
    if (strlen($password) < 6) {
        jsonResponse(['ok' => false, 'error' => 'Mot de passe : minimum 6 caractères'], 400);
    }
    if (hasAdmin()) {
        jsonResponse(['ok' => false, 'error' => 'Un administrateur existe déjà. Connectez-vous.'], 403);
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM profiles WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonResponse(['ok' => false, 'error' => 'Cet email est déjà utilisé'], 409);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $db->prepare("INSERT INTO profiles (email, password_hash, created_at, role, account_status) VALUES (?, ?, ?, 'admin', 'active')")
       ->execute([$email, $hash, date('c')]);

    $_SESSION['email'] = $email;
    $_SESSION['role'] = 'admin';
    $_SESSION['name'] = explode('@', $email)[0];

    jsonResponse(['ok' => true, 'email' => $email, 'role' => 'admin', 'isNew' => true]);
}

// POST /api/login — Connexion avec mot de passe
if ($uri === '/api/login' && $method === 'POST') {
    $data = jsonInput();
    $email = trim(strtolower($data['email'] ?? ''));
    $password = $data['password'] ?? '';

    if (!$email || !str_contains($email, '@')) {
        jsonResponse(['ok' => false, 'error' => 'Email invalide'], 400);
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM profiles WHERE email = ?");
    $stmt->execute([$email]);
    $profile = $stmt->fetch();

    if (!$profile) {
        jsonResponse(['ok' => false, 'error' => 'Email ou mot de passe incorrect'], 401);
    }

    if ($profile['account_status'] !== 'active') {
        jsonResponse(['ok' => false, 'error' => 'Compte désactivé. Contactez votre administrateur.'], 403);
    }

    if (empty($profile['password_hash'])) {
        jsonResponse(['ok' => false, 'error' => 'Compte sans mot de passe. Contactez votre administrateur.'], 403);
    }

    if (!password_verify($password, $profile['password_hash'])) {
        jsonResponse(['ok' => false, 'error' => 'Email ou mot de passe incorrect'], 401);
    }

    $_SESSION['email'] = $email;
    $_SESSION['role'] = $profile['role'];
    $_SESSION['name'] = explode('@', $email)[0];

    jsonResponse([
        'ok'    => true,
        'email' => $email,
        'role'  => $profile['role'],
        'isNew' => false,
    ]);
}

// POST /api/logout — Déconnexion
if ($uri === '/api/logout' && $method === 'POST') {
    $_SESSION = [];
    session_destroy();
    jsonResponse(['ok' => true]);
}

// GET /api/session — Vérifier la session en cours
if ($uri === '/api/session' && $method === 'GET') {
    $hasAdmin = hasAdmin();
    if (!isset($_SESSION['email'])) {
        jsonResponse(['ok' => false, 'authenticated' => false, 'hasAdmin' => $hasAdmin], 200);
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM profiles WHERE email = ?");
    $stmt->execute([$_SESSION['email']]);
    $profile = $stmt->fetch();

    if (!$profile || $profile['account_status'] !== 'active') {
        $_SESSION = [];
        session_destroy();
        jsonResponse(['ok' => false, 'authenticated' => false], 200);
    }

    $_SESSION['role'] = $profile['role'];

    jsonResponse([
        'ok' => true,
        'authenticated' => true,
        'email' => $profile['email'],
        'role' => $profile['role'],
        'isAdmin' => $profile['role'] === 'admin',
    ]);
}

// POST /api/invite — Admin crée un compte (admin only)
if ($uri === '/api/invite' && $method === 'POST') {
    if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'admin') {
        jsonResponse(['ok' => false, 'error' => 'Accès réservé aux administrateurs'], 403);
    }

    $data = jsonInput();
    $email = trim(strtolower($data['email'] ?? ''));
    $role = $data['role'] ?? 'user';

    if (!$email || !str_contains($email, '@')) {
        jsonResponse(['ok' => false, 'error' => 'Email invalide'], 400);
    }
    if (!in_array($role, ['user', 'prevention'])) {
        jsonResponse(['ok' => false, 'error' => 'Rôle invalide'], 400);
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM profiles WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonResponse(['ok' => false, 'error' => 'Cet email a déjà un compte'], 409);
    }

    $tempPassword = bin2hex(random_bytes(4)); // 8 caractères
    $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

    $db->prepare("INSERT INTO profiles (email, password_hash, created_at, role, account_status, created_by) VALUES (?, ?, ?, ?, 'active', ?)")
       ->execute([$email, $hash, date('c'), $role, $_SESSION['email']]);

    jsonResponse([
        'ok' => true,
        'email' => $email,
        'role' => $role,
        'temporaryPassword' => $tempPassword,
    ]);
}

// POST /api/change-password — Changer son mot de passe
if ($uri === '/api/change-password' && $method === 'POST') {
    if (!isset($_SESSION['email'])) {
        jsonResponse(['ok' => false, 'error' => 'Authentification requise'], 401);
    }

    $data = jsonInput();
    $currentPassword = $data['currentPassword'] ?? '';
    $newPassword = $data['newPassword'] ?? '';

    if (strlen($newPassword) < 6) {
        jsonResponse(['ok' => false, 'error' => 'Mot de passe : minimum 6 caractères'], 400);
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM profiles WHERE email = ?");
    $stmt->execute([$_SESSION['email']]);
    $profile = $stmt->fetch();

    if (!$profile || !password_verify($currentPassword, $profile['password_hash'])) {
        jsonResponse(['ok' => false, 'error' => 'Mot de passe actuel incorrect'], 403);
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $db->prepare("UPDATE profiles SET password_hash = ? WHERE email = ?")
       ->execute([$hash, $_SESSION['email']]);

    jsonResponse(['ok' => true]);
}

// GET /api/users — Liste des utilisateurs (admin only)
if ($uri === '/api/users' && $method === 'GET') {
    if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'admin') {
        jsonResponse(['ok' => false, 'error' => 'Accès réservé aux administrateurs'], 403);
    }

    $db = getDB();
    $stmt = $db->query("SELECT email, role, account_status, created_at, created_by FROM profiles ORDER BY created_at");
    $users = $stmt->fetchAll();

    jsonResponse(['ok' => true, 'users' => $users]);
}

// PUT /api/users/{email}/status — Activer/désactiver un compte (admin only)
if (preg_match('#^/api/users/([^/]+)/status$#', $uri, $m) && $method === 'PUT') {
    if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'admin') {
        jsonResponse(['ok' => false, 'error' => 'Accès réservé aux administrateurs'], 403);
    }

    $targetEmail = strtolower(urldecode($m[1]));
    $data = jsonInput();
    $status = $data['status'] ?? 'active';

    if (!in_array($status, ['active', 'disabled'])) {
        jsonResponse(['ok' => false, 'error' => 'Statut invalide'], 400);
    }

    $db = getDB();
    $db->prepare("UPDATE profiles SET account_status = ? WHERE email = ?")
       ->execute([$status, $targetEmail]);

    jsonResponse(['ok' => true]);
}

// GET /api/profil/{email} — Profil utilisateur (session requise)
if (preg_match('#^/api/profil/([^/]+)$#', $uri, $m) && $method === 'GET') {
    requireAuth();
    $email = strtolower(urldecode($m[1]));

    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM profiles WHERE email = ?");
    $stmt->execute([$email]);
    $profile = $stmt->fetch();

    if (!$profile) {
        jsonResponse(['ok' => false, 'error' => 'Profil introuvable'], 404);
    }

    $chantiers = $db->prepare("SELECT name FROM chantiers WHERE email = ? ORDER BY name");
    $chantiers->execute([$email]);
    $chantierNames = array_column($chantiers->fetchAll(), 'name');

    $participants = $db->prepare("SELECT name FROM participants WHERE email = ? ORDER BY name");
    $participants->execute([$email]);
    $participantNames = array_column($participants->fetchAll(), 'name');

    jsonResponse([
        'ok'           => true,
        'email'        => $profile['email'],
        'role'         => $profile['role'] ?? 'user',
        'created_at'   => $profile['created_at'],
        'chantiers'    => $chantierNames,
        'participants' => $participantNames,
    ]);
}

// PUT /api/profil/{email}/role — Changer le rôle (admin only)
if (preg_match('#^/api/profil/([^/]+)/role$#', $uri, $m) && $method === 'PUT') {
    if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'admin') {
        jsonResponse(['ok' => false, 'error' => 'Accès réservé aux administrateurs'], 403);
    }
    $email = strtolower(urldecode($m[1]));
    $data = jsonInput();
    $role = $data['role'] ?? '';

    if (!in_array($role, ['user', 'prevention'])) {
        jsonResponse(['ok' => false, 'error' => 'Rôle invalide'], 400);
    }

    $db = getDB();
    $db->prepare("UPDATE profiles SET role = ? WHERE email = ?")
       ->execute([$role, $email]);

    jsonResponse(['ok' => true]);
}

// Protection des routes métier — session requise
requireAuth();

// ========================================
// CAUSERIES
// ========================================

// GET /api/causeries/{email}
if (preg_match('#^/api/causeries/([^/]+)$#', $uri, $m) && $method === 'GET') {
    $email = strtolower(urldecode($m[1]));
    $db = getDB();

    $stmt = $db->prepare("SELECT role FROM profiles WHERE email = ?");
    $stmt->execute([$email]);
    $profile = $stmt->fetch();
    $isPrevention = ($profile && $profile['role'] === 'prevention') || isPreventionEmail($email);

    if ($isPrevention) {
        $rows = $db->query("SELECT * FROM causeries ORDER BY date_iso DESC")->fetchAll();
    } else {
        $stmt = $db->prepare(
            "SELECT c.* FROM causeries c
             LEFT JOIN shares s ON (s.shared_with_email = ? AND c.chantier = s.chantier AND c.email = s.owner_email)
             WHERE c.email = ? OR s.id IS NOT NULL
             ORDER BY c.date_iso DESC"
        );
        $stmt->execute([$email, $email]);
        $rows = $stmt->fetchAll();
    }

    $causeries = array_map('causerieRowToApi', $rows);
    jsonResponse(['ok' => true, 'causeries' => $causeries]);
}

// POST /api/causeries
if ($uri === '/api/causeries' && $method === 'POST') {
    $data = jsonInput();
    $email = trim(strtolower($data['email'] ?? ''));
    $chantier = trim($data['chantier'] ?? '');
    $themes = $data['themes'] ?? [];
    $notes = $data['notes'] ?? '';
    $participants = $data['participants'] ?? [];
    $dateIso = $data['date_iso'] ?? date('Y-m-d');
    $dateLabel = $data['date_label'] ?? parseDateLabel($dateIso);
    $animateurSignature = $data['animateur_signature'] ?? null;
    $guideData = $data['guide_data'] ?? null;
    $id = $data['id'] ?? generateUUID();

    if (!$email || !$chantier) {
        jsonResponse(['ok' => false, 'error' => 'Champs requis manquants'], 400);
    }

    $db = getDB();
    $now = date('c');

    $participantsJson = json_encode($participants, JSON_UNESCAPED_UNICODE);
    $themesJson = json_encode($themes, JSON_UNESCAPED_UNICODE);
    $guideDataJson = $guideData ? (is_string($guideData) ? $guideData : json_encode($guideData, JSON_UNESCAPED_UNICODE)) : null;

    // Ajouter le chantier si nouveau
    $chStmt = $db->prepare("SELECT COUNT(*) FROM chantiers WHERE email = ? AND name = ?");
    $chStmt->execute([$email, $chantier]);
    if ($chStmt->fetchColumn() == 0) {
        $db->prepare("INSERT INTO chantiers (email, name) VALUES (?, ?)")
           ->execute([$email, $chantier]);
    }

    // Ajouter les nouveaux participants
    foreach ($participants as $p) {
        $name = is_string($p) ? $p : ($p['name'] ?? '');
        if ($name) {
            $pStmt = $db->prepare("SELECT COUNT(*) FROM participants WHERE email = ? AND name = ?");
            $pStmt->execute([$email, $name]);
            if ($pStmt->fetchColumn() == 0) {
                $db->prepare("INSERT INTO participants (email, name) VALUES (?, ?)")
                   ->execute([$email, $name]);
            }
        }
    }

    $db->prepare(
        "INSERT INTO causeries (id, email, chantier, themes, notes, participants, date_iso, date_label, created_at, animateur_signature, guide_data, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
    )->execute([$id, $email, $chantier, $themesJson, $notes, $participantsJson, $dateIso, $dateLabel, $now, $animateurSignature, $guideDataJson]);

    jsonResponse(['ok' => true, 'id' => $id, 'date_label' => $dateLabel]);
}

// DELETE /api/causeries/{cid}
if (preg_match('#^/api/causeries/([^/]+)$#', $uri, $m) && $method === 'DELETE') {
    $cid = $m[1];
    $email = strtolower(trim($params['email'] ?? ''));

    $db = getDB();
    $causerie = $db->prepare("SELECT * FROM causeries WHERE id = ?");
    $causerie->execute([$cid]);
    $row = $causerie->fetch();

    if (!$row) {
        jsonResponse(['ok' => false, 'error' => 'Causerie introuvable'], 404);
    }

    $stmt = $db->prepare("SELECT role FROM profiles WHERE email = ?");
    $stmt->execute([$email]);
    $profile = $stmt->fetch();
    $isPrevention = ($profile && $profile['role'] === 'prevention') || isPreventionEmail($email);

    if ($row['email'] !== $email && !$isPrevention) {
        jsonResponse(['ok' => false, 'error' => 'Non autorisé'], 403);
    }

    $db->prepare("DELETE FROM causeries WHERE id = ?")->execute([$cid]);
    jsonResponse(['ok' => true]);
}

// PUT /api/causeries/{cid}/resubmit
if (preg_match('#^/api/causeries/([^/]+)/resubmit$#', $uri, $m) && $method === 'PUT') {
    $cid = $m[1];
    $data = jsonInput();

    $db = getDB();
    $db->prepare(
        "UPDATE causeries SET status = 'pending', validated_by = NULL, validated_at = NULL, validated_notes = '' WHERE id = ?"
    )->execute([$cid]);

    jsonResponse(['ok' => true]);
}

// GET /api/admin/causeries
if (($uri === '/api/admin/causeries' || $uri === '/api/all-causeries') && $method === 'GET') {
    if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'admin') {
        jsonResponse(['ok' => false, 'error' => 'Accès réservé aux administrateurs'], 403);
    }

    $email = strtolower(trim($params['email'] ?? ''));
    $statusFilter = $params['status'] ?? '';
    $animateurFilter = $params['animateur'] ?? '';
    $chantierFilter = $params['chantier'] ?? '';

    $db = getDB();
    $sql = "SELECT * FROM causeries WHERE 1=1";
    $bindings = [];

    if ($statusFilter && $statusFilter !== 'all') {
        if ($statusFilter === 'pending') {
            $sql .= " AND (status IS NULL OR status = 'pending')";
        } else {
            $sql .= " AND status = ?";
            $bindings[] = $statusFilter;
        }
    }
    if ($animateurFilter) {
        $sql .= " AND email = ?";
        $bindings[] = $animateurFilter;
    }
    if ($chantierFilter) {
        $sql .= " AND chantier = ?";
        $bindings[] = $chantierFilter;
    }
    $sql .= " ORDER BY date_iso DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($bindings);
    $rows = $stmt->fetchAll();

    $causeries = array_map('causerieRowToApi', $rows);
    jsonResponse(['ok' => true, 'causeries' => $causeries, 'total' => count($causeries)]);
}

// PUT /api/causeries/{cid}/validate
if (preg_match('#^/api/causeries/([^/]+)/validate$#', $uri, $m) && $method === 'PUT') {
    $cid = $m[1];
    $data = jsonInput();
    $email = strtolower(trim($data['email'] ?? ''));
    $action = $data['action'] ?? '';
    $notes = $data['notes'] ?? '';

    if (!in_array($action, ['validate', 'reject'])) {
        jsonResponse(['ok' => false, 'error' => 'Action invalide'], 400);
    }

    $db = getDB();
    $causerie = $db->prepare("SELECT id FROM causeries WHERE id = ?");
    $causerie->execute([$cid]);
    if (!$causerie->fetch()) {
        jsonResponse(['ok' => false, 'error' => 'Introuvable'], 404);
    }

    $now = date('c');
    $newStatus = $action === 'validate' ? 'validated' : 'rejected';

    $db->prepare(
        "UPDATE causeries SET status = ?, validated_by = ?, validated_at = ?, validated_notes = ? WHERE id = ?"
    )->execute([$newStatus, $email, $now, $notes, $cid]);

    jsonResponse(['ok' => true, 'status' => $newStatus]);
}

// ========================================
// ACTION PLANS
// ========================================

// GET /api/action-plans
if ($uri === '/api/action-plans' && $method === 'GET') {
    $email = strtolower(trim($params['email'] ?? ''));
    $causerieId = $params['causerie_id'] ?? '';

    $db = getDB();
    $sql = "SELECT ap.*, c.chantier, c.email as owner_email
            FROM action_plans ap
            LEFT JOIN causeries c ON ap.causerie_id = c.id
            WHERE 1=1";
    $bindings = [];

    if ($causerieId) {
        $sql .= " AND ap.causerie_id = ?";
        $bindings[] = $causerieId;
    }
    $sql .= " ORDER BY ap.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($bindings);
    $plans = $stmt->fetchAll();

    jsonResponse(['ok' => true, 'plans' => $plans]);
}

// POST /api/action-plans
if ($uri === '/api/action-plans' && $method === 'POST') {
    $data = jsonInput();
    $email = strtolower(trim($data['email'] ?? ''));
    $causerieId = $data['causerie_id'] ?? '';
    $title = trim($data['title'] ?? '');
    $description = $data['description'] ?? '';
    $assignedTo = $data['assigned_to'] ?? '';
    $dueDate = $data['due_date'] ?? '';
    $priority = $data['priority'] ?? 'normal';

    if (!$title) {
        jsonResponse(['ok' => false, 'error' => 'Titre requis'], 400);
    }

    $id = generateUUID();
    $now = date('c');

    $db = getDB();
    $db->prepare(
        "INSERT INTO action_plans (id, causerie_id, title, description, assigned_to, due_date, status, priority, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, 'open', ?, ?, ?, ?)"
    )->execute([$id, $causerieId, $title, $description, $assignedTo, $dueDate, $priority, $email, $now, $now]);

    jsonResponse(['ok' => true, 'id' => $id]);
}

// PUT /api/action-plans/{plan_id}
if (preg_match('#^/api/action-plans/([^/]+)$#', $uri, $m) && $method === 'PUT') {
    $planId = $m[1];
    $data = jsonInput();

    $db = getDB();
    $updates = [];
    $bindings = [];

    foreach (['title', 'description', 'assigned_to', 'due_date', 'status', 'priority'] as $field) {
        if (array_key_exists($field, $data)) {
            $updates[] = "$field = ?";
            $bindings[] = $data[$field];
        }
    }

    if (empty($updates)) {
        jsonResponse(['ok' => false, 'error' => 'Rien à mettre à jour'], 400);
    }

    $updates[] = "updated_at = ?";
    $bindings[] = date('c');
    $bindings[] = $planId;

    $db->prepare("UPDATE action_plans SET " . implode(', ', $updates) . " WHERE id = ?")
       ->execute($bindings);

    jsonResponse(['ok' => true]);
}

// ========================================
// ACTION PLAN COMMENTS
// ========================================

// GET /api/action-plans/{plan_id}/comments
if (preg_match('#^/api/action-plans/([^/]+)/comments$#', $uri, $m) && $method === 'GET') {
    $planId = $m[1];

    $db = getDB();
    $stmt = $db->prepare(
        "SELECT * FROM action_plan_comments WHERE plan_id = ? ORDER BY created_at ASC"
    );
    $stmt->execute([$planId]);
    $comments = $stmt->fetchAll();

    jsonResponse(['ok' => true, 'comments' => $comments]);
}

// POST /api/action-plans/{plan_id}/comments
if (preg_match('#^/api/action-plans/([^/]+)/comments$#', $uri, $m) && $method === 'POST') {
    $planId = $m[1];
    $data = jsonInput();
    $author = strtolower(trim($data['email'] ?? ''));
    $comment = trim($data['comment'] ?? '');

    if (!$comment) {
        jsonResponse(['ok' => false, 'error' => 'Commentaire requis'], 400);
    }

    $db = getDB();
    $db->prepare(
        "INSERT INTO action_plan_comments (plan_id, author_email, comment, created_at) VALUES (?, ?, ?, ?)"
    )->execute([$planId, $author, $comment, date('c')]);

    jsonResponse(['ok' => true]);
}

// ========================================
// ADMIN STATS
// ========================================

// GET /api/admin/stats
if ($uri === '/api/admin/stats' && $method === 'GET') {
    if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'admin') {
        jsonResponse(['ok' => false, 'error' => 'Accès réservé aux administrateurs'], 403);
    }

    $email = strtolower(trim($params['email'] ?? ''));

    $db = getDB();

    $total = $db->query("SELECT COUNT(*) FROM causeries")->fetchColumn();
    $enAttente = $db->query("SELECT COUNT(*) FROM causeries WHERE status IS NULL OR status = 'pending'")->fetchColumn();
    $validees = $db->query("SELECT COUNT(*) FROM causeries WHERE status = 'validated'")->fetchColumn();
    $rejetees = $db->query("SELECT COUNT(*) FROM causeries WHERE status = 'rejected'")->fetchColumn();

    $participantsCount = $db->query("SELECT COUNT(DISTINCT email) FROM causeries")->fetchColumn();
    $plansOuverts = $db->query("SELECT COUNT(*) FROM action_plans WHERE status = 'open'")->fetchColumn();
    $plansRealises = $db->query("SELECT COUNT(*) FROM action_plans WHERE status = 'done'")->fetchColumn();

    $first = $db->query("SELECT MIN(date_iso) FROM causeries")->fetchColumn();
    $hoursSinceFirst = 0;
    if ($first) {
        $hoursSinceFirst = (int)((time() - strtotime($first)) / 3600);
    }

    jsonResponse([
        'ok'               => true,
        'total'            => (int)$total,
        'en_attente'       => (int)$enAttente,
        'validees'         => (int)$validees,
        'rejetees'         => (int)$rejetees,
        'participants_count' => (int)$participantsCount,
        'plans_ouverts'    => (int)$plansOuverts,
        'plans_realises'   => (int)$plansRealises,
        'hours_since_first'=> $hoursSinceFirst,
    ]);
}

// ========================================
// CHANTIERS / PARTICIPANTS
// ========================================

// DELETE /api/chantiers/{email}
if (preg_match('#^/api/chantiers/([^/]+)$#', $uri, $m) && $method === 'DELETE') {
    $email = strtolower(urldecode($m[1]));
    $name = trim($params['name'] ?? '');

    if (!$name) {
        jsonResponse(['ok' => false, 'error' => 'Nom requis'], 400);
    }

    $db = getDB();
    $db->prepare("DELETE FROM chantiers WHERE email = ? AND name = ?")
       ->execute([$email, $name]);

    jsonResponse(['ok' => true]);
}

// DELETE /api/participants/{email}
if (preg_match('#^/api/participants/([^/]+)$#', $uri, $m) && $method === 'DELETE') {
    $email = strtolower(urldecode($m[1]));
    $name = trim($params['name'] ?? '');

    if (!$name) {
        jsonResponse(['ok' => false, 'error' => 'Nom requis'], 400);
    }

    $db = getDB();
    $db->prepare("DELETE FROM participants WHERE email = ? AND name = ?")
       ->execute([$email, $name]);

    jsonResponse(['ok' => true]);
}

// ========================================
// SHARES
// ========================================

// GET /api/shares/{email}
if (preg_match('#^/api/shares/([^/]+)$#', $uri, $m) && $method === 'GET') {
    $email = strtolower(urldecode($m[1]));

    $db = getDB();
    $stmt = $db->prepare(
        "SELECT * FROM shares WHERE owner_email = ? OR shared_with_email = ? ORDER BY created_at DESC"
    );
    $stmt->execute([$email, $email]);
    $shares = $stmt->fetchAll();

    jsonResponse(['ok' => true, 'shares' => $shares]);
}

// POST /api/shares
if ($uri === '/api/shares' && $method === 'POST') {
    $data = jsonInput();
    $owner = strtolower(trim($data['owner'] ?? ''));
    $sharedWith = strtolower(trim($data['shared_with'] ?? ''));
    $chantier = trim($data['chantier'] ?? '');

    if (!$owner || !$sharedWith || !$chantier) {
        jsonResponse(['ok' => false, 'error' => 'Champs requis manquants'], 400);
    }

    $db = getDB();
    $db->prepare(
        "INSERT OR IGNORE INTO shares (owner_email, shared_with_email, chantier, created_at) VALUES (?, ?, ?, ?)"
    )->execute([$owner, $sharedWith, $chantier, date('c')]);

    jsonResponse(['ok' => true]);
}

// DELETE /api/shares/{share_id}
if (preg_match('#^/api/shares/(\d+)$#', $uri, $m) && $method === 'DELETE') {
    $shareId = (int)$m[1];

    $db = getDB();
    $db->prepare("DELETE FROM shares WHERE id = ?")->execute([$shareId]);

    jsonResponse(['ok' => true]);
}

// ========================================
// PHOTOS
// ========================================

// POST /api/causeries/{cid}/photos
if (preg_match('#^/api/causeries/([^/]+)/photos$#', $uri, $m) && $method === 'POST') {
    $cid = $m[1];
    $data = jsonInput();
    $photoData = $data['data'] ?? '';
    $caption = $data['caption'] ?? '';

    if (!$photoData) {
        jsonResponse(['ok' => false, 'error' => 'Données photo requises'], 400);
    }

    $db = getDB();
    $db->prepare(
        "INSERT INTO photos (causerie_id, data, caption, created_at) VALUES (?, ?, ?, ?)"
    )->execute([$cid, $photoData, $caption, date('c')]);

    $photoId = $db->lastInsertId();
    jsonResponse(['ok' => true, 'id' => (int)$photoId]);
}

// GET /api/causeries/{cid}/photos
if (preg_match('#^/api/causeries/([^/]+)/photos$#', $uri, $m) && $method === 'GET') {
    $cid = $m[1];

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM photos WHERE causerie_id = ? ORDER BY created_at ASC");
    $stmt->execute([$cid]);
    $photos = $stmt->fetchAll();

    jsonResponse(['ok' => true, 'photos' => $photos]);
}

// DELETE /api/photos/{photo_id}
if (preg_match('#^/api/photos/(\d+)$#', $uri, $m) && $method === 'DELETE') {
    $photoId = (int)$m[1];

    $db = getDB();
    $db->prepare("DELETE FROM photos WHERE id = ?")->execute([$photoId]);

    jsonResponse(['ok' => true]);
}

// ========================================
// IMPROVEMENTS
// ========================================

// POST /api/improvements
if ($uri === '/api/improvements' && $method === 'POST') {
    $data = jsonInput();
    $email = strtolower(trim($data['email'] ?? ''));
    $title = trim($data['title'] ?? '');
    $text = trim($data['text'] ?? '');

    if (!$title || !$text) {
        jsonResponse(['ok' => false, 'error' => 'Titre et description requis'], 400);
    }

    $id = generateUUID();
    $now = date('c');

    $db = getDB();
    $db->prepare(
        "INSERT INTO improvements (id, email, title, text, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'pending', ?, ?)"
    )->execute([$id, $email, $title, $text, $now, $now]);

    jsonResponse(['ok' => true, 'id' => $id]);
}

// GET /api/improvements/{email}
if (preg_match('#^/api/improvements/([^/]+)$#', $uri, $m) && $method === 'GET') {
    $email = strtolower(urldecode($m[1]));
    $statusFilter = $params['status'] ?? '';

    $db = getDB();
    $sql = "SELECT * FROM improvements WHERE email = ?";
    $bindings = [$email];

    if ($statusFilter && $statusFilter !== 'all') {
        $sql .= " AND status = ?";
        $bindings[] = $statusFilter;
    }
    $sql .= " ORDER BY created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($bindings);
    $improvements = $stmt->fetchAll();

    jsonResponse(['ok' => true, 'improvements' => $improvements]);
}

// GET /api/improvements/count/{email}
if (preg_match('#^/api/improvements/count/([^/]+)$#', $uri, $m) && $method === 'GET') {
    $email = strtolower(urldecode($m[1]));

    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM improvements WHERE email = ?");
    $stmt->execute([$email]);
    $count = (int)$stmt->fetchColumn();

    jsonResponse(['ok' => true, 'count' => $count]);
}

// PUT /api/improvements/{id}
if (preg_match('#^/api/improvements/([^/]+)$#', $uri, $m) && $method === 'PUT') {
    $id = $m[1];
    $data = jsonInput();
    $email = $data['email'] ?? '';
    $action = $data['action'] ?? '';
    $comment = $data['comment'] ?? '';

    if (!$email || !$action) {
        jsonResponse(['ok' => false, 'error' => 'email et action requis'], 400);
    }

    if (!in_array($action, ['approve', 'reject', 'done'])) {
        jsonResponse(['ok' => false, 'error' => 'Action invalide'], 400);
    }

    $status = $action === 'approve' ? 'approved' : ($action === 'reject' ? 'rejected' : 'done');

    $db = getDB();
    $db->prepare("UPDATE improvements SET status = ?, review_comment = ?, validated_by = ?, updated_at = ? WHERE id = ?")
       ->execute([$status, $comment, $email, date('c'), $id]);

    jsonResponse(['ok' => true]);
}

// ========================================
// QR CODE
// ========================================

// GET /api/qr/{causerie_id}
if (preg_match('#^/api/qr/([^/]+)$#', $uri, $m) && $method === 'GET') {
    $cid = $m[1];

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM causeries WHERE id = ?");
    $stmt->execute([$cid]);
    $causerie = $stmt->fetch();

    if (!$causerie) {
        jsonResponse(['ok' => false, 'error' => 'Causerie introuvable'], 404);
    }

    $content = APP_URL . '/?c=' . $cid;

    if (class_exists(QRCode::class)) {
        $options = new QROptions();
        $options->outputType = 'png';
        $options->eccLevel = 'M';
        $options->scale = 8;
        $qrCode = new QRCode($options);
        $qrData = $qrCode->render($content);
        // render() returns a data URL or raw PNG depending on version
        if (str_starts_with($qrData, 'data:')) {
            $qrBase64 = $qrData;
        } else {
            $qrBase64 = 'data:image/png;base64,' . base64_encode($qrData);
        }
        jsonResponse(['ok' => true, 'qr' => $qrBase64]);
    }

    // Fallback GD
    if (function_exists('imagecreate')) {
        $size = 8;
        $margin = 2;
        $moduleSize = $size;
        $imgSize = ($moduleSize * 21) + ($margin * 2 * $moduleSize);
        $img = imagecreate($imgSize, $imgSize);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $white);

        for ($y = 0; $y < 21; $y++) {
            for ($x = 0; $x < 21; $x++) {
                $px = $margin * $moduleSize + $x * $moduleSize;
                $py = $margin * $moduleSize + $y * $moduleSize;
                if (($x < 7 && $y < 7) || ($x > 13 && $y < 7) || ($x < 7 && $y > 13)) {
                    if ($x == 0 || $x == 6 || $y == 0 || $y == 6 ||
                        ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4)) {
                        imagefilledrectangle($img, $px, $py, $px + $moduleSize, $py + $moduleSize, $black);
                    }
                } else {
                    $hash = crc32($content . $x . $y);
                    if ($hash % 3 == 0) {
                        imagefilledrectangle($img, $px, $py, $px + $moduleSize, $py + $moduleSize, $black);
                    }
                }
            }
        }
        ob_start();
        imagepng($img);
        $qrData = ob_get_clean();
        imagedestroy($img);
        $qrBase64 = 'data:image/png;base64,' . base64_encode($qrData);
        jsonResponse(['ok' => true, 'qr' => $qrBase64]);
    }

    jsonResponse(['ok' => false, 'error' => 'QR code non disponible'], 500);
}

// ========================================
// EXPORT PDF
// ========================================

// GET /api/export/{causerie_id}
if (preg_match('#^/api/export/([^/]+)$#', $uri, $m) && $method === 'GET') {
    $cid = $m[1];

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM causeries WHERE id = ?");
    $stmt->execute([$cid]);
    $causerie = $stmt->fetch();

    if (!$causerie) {
        jsonResponse(['ok' => false, 'error' => 'Causerie introuvable'], 404);
    }

    $participants = json_decode($causerie['participants'] ?? '[]', true) ?: [];
    $themes = json_decode($causerie['themes'] ?? '[]', true) ?: [];
    $guideData = $causerie['guide_data'] ? json_decode($causerie['guide_data'], true) : null;

    $partsHtml = '';
    foreach ($participants as $p) {
        $name = is_string($p) ? $p : ($p['name'] ?? '');
        $signed = (is_array($p) && !empty($p['signature'])) ? '✅ Signé' : '☐ Non signé';
        $partsHtml .= "<tr><td style=\"padding:6px 10px;border-bottom:1px solid #ddd;\">" . htmlspecialchars($name) . "</td><td style=\"padding:6px 10px;border-bottom:1px solid #ddd;text-align:center;\">$signed</td></tr>";
    }

    $themesHtml = '';
    foreach ($themes as $t) {
        $themesHtml .= '<span style="display:inline-block;padding:3px 10px;background:#eef2ff;color:#1e3a5f;border-radius:12px;font-size:11px;margin:2px;font-weight:500;">' . htmlspecialchars($t) . '</span>';
    }

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
        body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#1e293b;margin:0;padding:20px;}
        h1{font-size:18px;color:#1e3a5f;margin-bottom:4px;}
        .sub{font-size:13px;color:#64748b;margin-bottom:16px;}
        .section{margin-bottom:14px;}
        .label{font-size:10px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;}
        .value{font-size:13px;margin-bottom:8px;}
        table{width:100%;border-collapse:collapse;margin-top:6px;}
        th{text-align:left;padding:6px 10px;font-size:10px;color:#64748b;text-transform:uppercase;border-bottom:2px solid #e2e8f0;}
        .footer{position:fixed;bottom:10px;left:20px;right:20px;text-align:center;font-size:9px;color:#94a3b8;border-top:1px solid #e2e8f0;padding-top:6px;}
        .signature-box{margin-top:20px;padding:12px;border:1px solid #e2e8f0;border-radius:6px;text-align:center;}
        .signature-box img{max-height:50px;}
    </style></head><body>
        <h1>🛡️ Causerie 1/4h Sécurité</h1>
        <div class="sub">' . htmlspecialchars($causerie['date_label']) . '</div>
        <div class="section"><div class="label">Chantier</div><div class="value">' . htmlspecialchars($causerie['chantier']) . '</div></div>
        <div class="section"><div class="label">Animateur</div><div class="value">' . htmlspecialchars($causerie['email']) . '</div></div>
        <div class="section"><div class="label">Thèmes</div><div class="value">' . $themesHtml . '</div></div>';

    if ($causerie['notes']) {
        $html .= '<div class="section"><div class="label">Notes</div><div class="value">' . nl2br(htmlspecialchars($causerie['notes'])) . '</div></div>';
    }

    if ($guideData) {
        $html .= '<div class="section"><div class="label">Guide — ' . htmlspecialchars($guideData['topic_label'] ?? '') . '</div>';
        if (!empty($guideData['checks'])) {
            $html .= '<table><tr><th>Action</th><th>Statut</th></tr>';
            foreach ($guideData['checks'] as $ch) {
                $done = !empty($ch['done']) ? '✅' : '☐';
                $html .= '<tr><td style="padding:4px 10px;border-bottom:1px solid #eee;">' . htmlspecialchars($ch['label'] ?? '') . '</td><td style="padding:4px 10px;border-bottom:1px solid #eee;text-align:center;">' . $done . '</td></tr>';
            }
            $html .= '</table>';
        }
        $html .= '</div>';
    }

    $html .= '<div class="section"><div class="label">Participants (' . count($participants) . ')</div>
        <table><tr><th>Nom</th><th>Signature</th></tr>' . $partsHtml . '</table></div>';

    if ($causerie['animateur_signature']) {
        $sig = htmlspecialchars($causerie['animateur_signature']);
        if (str_starts_with($sig, 'data:image')) {
            $html .= '<div class="signature-box"><div class="label" style="margin-bottom:6px;">Signature animateur</div><img src="' . $sig . '" alt="Signature"></div>';
        }
    }

    $statusLabels = ['pending' => 'En attente', 'validated' => 'Validée', 'rejected' => 'Rejetée'];
    $statusLabel = $statusLabels[$causerie['status']] ?? 'En attente';
    $html .= '<div class="section" style="margin-top:16px;padding-top:12px;border-top:2px solid #e2e8f0;">
        <div class="label">Statut</div><div class="value">' . $statusLabel . '</div></div>';

    $html .= '<div class="footer">Généré par Causeries 1/4h Sécurité — ' . date('d/m/Y') . '</div>
    </body></html>';

    if (class_exists(Dompdf::class)) {
        $dompdf = new Dompdf();
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html);
        $dompdf->render();
        $dompdf->stream('causerie_' . $cid . '.pdf', ['Attachment' => false]);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

// ========================================
// CERTIFICATION
// ========================================

// GET /api/certification/{email}
if (preg_match('#^/api/certification/([^/]+)$#', $uri, $m) && $method === 'GET') {
    $email = strtolower(urldecode($m[1]));

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM causeries WHERE email = ? ORDER BY date_iso ASC");
    $stmt->execute([$email]);
    $allCauseries = $stmt->fetchAll();

    $total = count($allCauseries);
    $totalChantiers = 0;
    $participantsUniques = 0;
    $chantierCounts = [];
    $themeCounts = [];
    $monthlyCounts = [];
    $lastCauserie = null;

    if ($total > 0) {
        foreach ($allCauseries as $c) {
            $ch = $c['chantier'];
            $chantierCounts[$ch] = ($chantierCounts[$ch] ?? 0) + 1;

            $themes = json_decode($c['themes'] ?? '[]', true) ?: [];
            foreach ($themes as $t) {
                $themeCounts[$t] = ($themeCounts[$t] ?? 0) + 1;
            }

            $parts = json_decode($c['participants'] ?? '[]', true) ?: [];
            foreach ($parts as $p) {
                $name = is_string($p) ? $p : ($p['name'] ?? '');
                if ($name) {
                    $participantsUniques++;
                }
            }

            $mois = substr($c['date_iso'], 0, 7);
            $monthlyCounts[$mois] = ($monthlyCounts[$mois] ?? 0) + 1;
        }

        $lastCauserie = $allCauseries[count($allCauseries) - 1];
        $totalChantiers = count($chantierCounts);
    }

    $validees = $db->prepare("SELECT COUNT(*) FROM causeries WHERE email = ? AND status = 'validated'");
    $validees->execute([$email]);
    $valideesCount = (int)$validees->fetchColumn();

    $taux = $total > 0 ? round(($valideesCount / $total) * 100) : 0;
    if ($taux >= 80) {
        $conformite = ['niveau' => 'vert', 'label' => 'Conforme — Taux de validation ' . $taux . '%'];
    } elseif ($taux >= 50) {
        $conformite = ['niveau' => 'orange', 'label' => 'Partiellement conforme — Taux ' . $taux . '%'];
    } else {
        $conformite = ['niveau' => 'rouge', 'label' => 'Non conforme — Taux ' . $taux . '%'];
    }

    $parMois = [];
    ksort($monthlyCounts);
    foreach ($monthlyCounts as $mois => $count) {
        $parMois[] = ['mois' => $mois, 'count' => $count];
    }

    $derniere = $lastCauserie ? [
        'date_label' => $lastCauserie['date_label'],
        'chantier'   => $lastCauserie['chantier'],
        'themes'     => json_decode($lastCauserie['themes'] ?? '[]', true) ?: [],
    ] : ['date_label' => '', 'chantier' => '', 'themes' => []];

    jsonResponse([
        'ok'                  => true,
        'total'               => $total,
        'total_chantiers'     => $totalChantiers,
        'participants_uniques'=> $participantsUniques,
        'par_mois'            => $parMois,
        'chantiers'           => $chantierCounts,
        'themes'              => $themeCounts,
        'derniere'            => $derniere,
        'conformite'          => $conformite,
    ]);
}

// ========================================
// EXPORT CERTIFICATION PDF
// ========================================

// GET /api/export-certification/{email}
if (preg_match('#^/api/export-certification/([^/]+)$#', $uri, $m) && $method === 'GET') {
    $email = strtolower(urldecode($m[1]));

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM causeries WHERE email = ? ORDER BY date_iso ASC");
    $stmt->execute([$email]);
    $allCauseries = $stmt->fetchAll();

    $total = count($allCauseries);
    $chantierCounts = [];
    $themeCounts = [];
    foreach ($allCauseries as $c) {
        $chantierCounts[$c['chantier']] = ($chantierCounts[$c['chantier']] ?? 0) + 1;
        $themes = json_decode($c['themes'] ?? '[]', true) ?: [];
        foreach ($themes as $t) {
            $themeCounts[$t] = ($themeCounts[$t] ?? 0) + 1;
        }
    }

    $lastCauserie = $total > 0 ? $allCauseries[count($allCauseries) - 1] : null;

    $chHtml = '';
    foreach ($chantierCounts as $name => $count) {
        $chHtml .= "<tr><td style=\"padding:4px 10px;border-bottom:1px solid #eee;\">" . htmlspecialchars($name) . "</td><td style=\"padding:4px 10px;border-bottom:1px solid #eee;text-align:center;\">$count</td></tr>";
    }
    $thHtml = '';
    foreach ($themeCounts as $name => $count) {
        $thHtml .= "<tr><td style=\"padding:4px 10px;border-bottom:1px solid #eee;\">" . htmlspecialchars($name) . "</td><td style=\"padding:4px 10px;border-bottom:1px solid #eee;text-align:center;\">$count</td></tr>";
    }

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
        body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#1e293b;margin:0;padding:30px;}
        h1{font-size:22px;color:#1e3a5f;margin-bottom:4px;text-align:center;}
        .sub{font-size:13px;color:#64748b;margin-bottom:20px;text-align:center;}
        .section{margin-bottom:16px;}
        .label{font-size:10px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;border-bottom:1px solid #e2e8f0;padding-bottom:3px;}
        table{width:100%;border-collapse:collapse;margin-top:6px;}
        th{text-align:left;padding:6px 10px;font-size:10px;color:#64748b;text-transform:uppercase;border-bottom:2px solid #e2e8f0;}
        .stats{display:flex;justify-content:space-around;margin:16px 0;padding:12px;background:#f8fafc;border-radius:8px;}
        .stat{text-align:center;}
        .stat .num{font-size:24px;font-weight:700;color:#1e3a5f;}
        .stat .lbl{font-size:10px;color:#64748b;}
        .footer{position:fixed;bottom:10px;left:30px;right:30px;text-align:center;font-size:9px;color:#94a3b8;border-top:1px solid #e2e8f0;padding-top:6px;}
        .cert-badge{text-align:center;padding:12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;margin:12px 0;font-size:14px;font-weight:600;color:#16a34a;}
    </style></head><body>
        <h1>🛡️ Rapport de Certification</h1>
        <div class="sub">Causeries 1/4h Sécurité — ' . htmlspecialchars($email) . '</div>
        <div class="stats">
            <div class="stat"><div class="num">' . $total . '</div><div class="lbl">Causeries</div></div>
            <div class="stat"><div class="num">' . count($chantierCounts) . '</div><div class="lbl">Chantiers</div></div>
            <div class="stat"><div class="num">' . count($themeCounts) . '</div><div class="lbl">Thèmes</div></div>
        </div>
        <div class="cert-badge">✅ Certification — Sous-section 3 — Suivi des causeries</div>
        <div class="section"><div class="label">Répartition par chantier</div>
        <table>' . ($chHtml ? "<tr><th>Chantier</th><th>Nombre</th></tr>$chHtml" : '<tr><td style="color:#94a3b8;">Aucune donnée</td></tr>') . '</table></div>
        <div class="section"><div class="label">Répartition par thème</div>
        <table>' . ($thHtml ? "<tr><th>Thème</th><th>Nombre</th></tr>$thHtml" : '<tr><td style="color:#94a3b8;">Aucune donnée</td></tr>') . '</table></div>';

    if ($lastCauserie) {
        $lastThemes = json_decode($lastCauserie['themes'] ?? '[]', true) ?: [];
        $html .= '<div class="section"><div class="label">Dernière causerie</div>
            <p><strong>' . htmlspecialchars($lastCauserie['date_label']) . '</strong> — ' . htmlspecialchars($lastCauserie['chantier']) . '</p>
            <p>Thèmes : ' . implode(', ', array_map('htmlspecialchars', $lastThemes)) . '</p></div>';
    }

    $html .= '<div class="footer">Généré par Causeries 1/4h Sécurité — ' . date('d/m/Y') . '</div>
    </body></html>';

    if (class_exists(Dompdf::class)) {
        $dompdf = new Dompdf();
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html);
        $dompdf->render();
        $dompdf->stream('certification_' . $email . '.pdf', ['Attachment' => false]);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

// ========================================
// SUGGESTIONS
// ========================================

// GET /api/suggestions/{email}
if (preg_match('#^/api/suggestions/([^/]+)$#', $uri, $m) && $method === 'GET') {
    $email = strtolower(urldecode($m[1]));

    $db = getDB();
    $stmt = $db->prepare("SELECT themes, chantier, participants FROM causeries WHERE email = ? ORDER BY created_at DESC");
    $stmt->execute([$email]);
    $rows = $stmt->fetchAll();

    $themeCounts = [];
    $chantierCounts = [];
    $participantNames = [];

    foreach ($rows as $r) {
        $themes = json_decode($r['themes'] ?? '[]', true) ?: [];
        foreach ($themes as $t) {
            $themeCounts[$t] = ($themeCounts[$t] ?? 0) + 1;
        }
        $ch = $r['chantier'];
        if ($ch) {
            $chantierCounts[$ch] = ($chantierCounts[$ch] ?? 0) + 1;
        }
        $parts = json_decode($r['participants'] ?? '[]', true) ?: [];
        foreach ($parts as $p) {
            $name = is_string($p) ? $p : ($p['name'] ?? '');
            if ($name) {
                $participantNames[$name] = ($participantNames[$name] ?? 0) + 1;
            }
        }
    }

    $suggestions = [];

    arsort($themeCounts);
    $topThemes = array_slice(array_keys($themeCounts), 0, 3);
    foreach ($topThemes as $theme) {
        $suggestions[] = [
            'type'   => 'theme',
            'texte'  => 'Thème suggéré : ' . $theme,
            'action' => 'fill_theme',
            'valeur' => $theme,
        ];
    }

    if (!empty($rows[0]['chantier'])) {
        $suggestions[] = [
            'type'   => 'chantier',
            'texte'  => 'Reprendre le chantier : ' . $rows[0]['chantier'],
            'action' => 'fill_chantier',
            'valeur' => $rows[0]['chantier'],
        ];
    }

    arsort($participantNames);
    $topParts = array_slice(array_keys($participantNames), 0, 2);
    foreach ($topParts as $pname) {
        $suggestions[] = [
            'type'   => 'participant',
            'texte'  => 'Ajouter ' . $pname,
            'action' => 'add_participant',
            'valeur' => $pname,
        ];
    }

    $suggestionDuJour = null;
    if (!empty($topThemes)) {
        $suggestionDuJour = [
            'texte' => '💡 Suggestion du jour : Causerie sur "' . $topThemes[0] . '"',
            'theme' => $topThemes[0],
        ];
    }

    if (empty($suggestions)) {
        $defaultThemes = ['Sécurité', 'Environnement', 'Santé'];
        foreach ($defaultThemes as $dt) {
            $suggestions[] = [
                'type'   => 'theme',
                'texte'  => 'Thème suggéré : ' . $dt,
                'action' => 'fill_theme',
                'valeur' => $dt,
            ];
        }
        $suggestionDuJour = [
            'texte' => '💡 Suggestion du jour : Causerie générale de sécurité',
            'theme' => 'Sécurité',
        ];
    }

    jsonResponse([
        'ok'                 => true,
        'suggestions'        => $suggestions,
        'suggestion_du_jour' => $suggestionDuJour,
        'themes'             => $topThemes,
    ]);
}

// ========================================
// 404 — Route non trouvée
// ========================================

jsonResponse(['ok' => false, 'error' => 'Route non trouvée'], 404);

} catch (PDOException $e) {
    error_log('DB Error in routes_api.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur interne de base de données'], 500);
} catch (Exception $e) {
    error_log('Error in routes_api.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur interne'], 500);
}
