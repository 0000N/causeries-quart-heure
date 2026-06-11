<?php
// Initialisation de la base de données
require_once __DIR__ . '/config.php';

function getDB() {
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');
    }
    return $db;
}

function initDB() {
    $db = getDB();
    $db->exec("
        CREATE TABLE IF NOT EXISTS profiles (
            email TEXT PRIMARY KEY,
            created_at TEXT NOT NULL,
            role TEXT DEFAULT 'user'
        );

        CREATE TABLE IF NOT EXISTS chantiers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            name TEXT NOT NULL,
            UNIQUE(email, name)
        );

        CREATE TABLE IF NOT EXISTS participants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            name TEXT NOT NULL,
            UNIQUE(email, name)
        );

        CREATE TABLE IF NOT EXISTS causeries (
            id TEXT PRIMARY KEY,
            email TEXT NOT NULL,
            chantier TEXT NOT NULL,
            themes TEXT NOT NULL DEFAULT '[]',
            notes TEXT DEFAULT '',
            participants TEXT NOT NULL DEFAULT '[]',
            date_iso TEXT NOT NULL,
            date_label TEXT NOT NULL,
            created_at TEXT NOT NULL,
            animateur_signature TEXT,
            guide_data TEXT,
            status TEXT DEFAULT 'pending',
            validated_by TEXT,
            validated_at TEXT,
            validated_notes TEXT DEFAULT '',
            done_at TEXT
        );

        CREATE TABLE IF NOT EXISTS shares (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_email TEXT NOT NULL,
            shared_with_email TEXT NOT NULL,
            chantier TEXT NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(owner_email, shared_with_email, chantier)
        );

        CREATE TABLE IF NOT EXISTS photos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            causerie_id TEXT NOT NULL,
            data TEXT NOT NULL,
            caption TEXT DEFAULT '',
            created_at TEXT NOT NULL,
            FOREIGN KEY (causerie_id) REFERENCES causeries(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS action_plans (
            id TEXT PRIMARY KEY,
            causerie_id TEXT NOT NULL,
            title TEXT NOT NULL,
            description TEXT DEFAULT '',
            assigned_to TEXT,
            due_date TEXT,
            status TEXT DEFAULT 'open',
            priority TEXT DEFAULT 'normal',
            created_by TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS action_plan_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            plan_id TEXT NOT NULL,
            author_email TEXT NOT NULL,
            comment TEXT NOT NULL,
            created_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS improvements (
            id TEXT PRIMARY KEY,
            email TEXT NOT NULL,
            title TEXT NOT NULL,
            text TEXT NOT NULL,
            status TEXT DEFAULT 'pending',
            review_comment TEXT DEFAULT '',
            validated_by TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
    ");
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonInput() {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?? [];
}
