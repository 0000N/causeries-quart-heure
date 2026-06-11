# Causeries 1/4h Sécurité

**Application professionnelle de gestion des causeries sécurité — création, signature, archivage et pilotage.**

---

## ✨ Fonctionnalités

| | |
|---|---|
| **Création guidée** | 12 thèmes sécurité avec checklists terrain intégrées (sécurité, environnement, santé, qualité) |
| **Signature électronique** | Participants et animateur signent directement sur mobile ou tablette |
| **Export PDF professionnel** | Document A4 avec QR code, dégradé, badges thèmes et conformité réglementaire |
| **Dashboard Prévention** | Vue consolidée de toutes les causeries, toutes équipes confondues |
| **Workflow validation** | Circuit de validation avec suivi des statuts (en attente, validé, refusé) |
| **Plans d'action** | Création et suivi des actions correctives avec commentaires |
| **Application mobile** | PWA — installation sur l'écran d'accueil, utilisation hors-ligne partielle |
| **Partage entre équipes** | Collaboration transverse avec visibilité partagée |

---

## 🚀 Démarrage rapide

```bash
# 1. Télécharger la dernière version stable
wget https://github.com/0000N/causeries-quart-heure/archive/refs/tags/v2.1.0.zip
unzip v2.1.0.zip
cd causeries-quart-heure-2.1.0

# 2. Installer les dépendances
composer install --no-dev

# 3. Lancer l'application
php -S localhost:8080 index.php
```

Accéder à [http://localhost:8080](http://localhost:8080) — interface animateur.
Accéder à [http://localhost:8080/prevention](http://localhost:8080/prevention) — dashboard Prévention.

---

## 📋 Prérequis techniques

| Technologie | Version | Rôle |
|---|---|---|
| PHP | 8.1+ | Moteur applicatif |
| SQLite | 3.x | Base de données intégrée |
| Composer | 2.x | Gestion des dépendances |

**Extensions PHP requises :** `sqlite3`, `gd`, `mbstring`, `dom`, `json`

---

## 🏗️ Architecture

Application monolithique PHP à point d'entrée unique — prête pour Apache, Nginx et hébergements mutualisés.

```
├── index.php              Routeur applicatif
├── .htaccess              Réécriture Apache
├── inc/
│   ├── config.php         Configuration
│   ├── database.php       Base SQLite
│   ├── routes_api.php     API REST (30+ endpoints)
│   └── pdf.php            Génération PDF
├── templates/
│   ├── animateur.html     Interface animateur
│   └── prevention.html    Dashboard Prévention
└── static/                Manifest PWA, Service Worker, icônes
```

---

## 🔧 Installation par environnement

<details>
<summary><b>Hébergement mutualisé (o2switch, OVH)</b></summary>

```bash
# Télécharger le ZIP depuis GitHub
# Décompresser dans votre dossier www/
unzip causeries-quart-heure-2.1.0.zip -d /chemin/www/
cd /chemin/www/causeries-quart-heure-2.1.0
composer install --no-dev
```

Le fichier `.htaccess` est préconfiguré — aucune manipulation supplémentaire.

</details>

<details>
<summary><b>Apache / VirtualHost</b></summary>

```apache
<VirtualHost *:80>
    ServerName causeries.example.com
    DocumentRoot /var/www/causeries-quart-heure

    <Directory /var/www/causeries-quart-heure>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Activation du module rewrite :
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```
</details>

<details>
<summary><b>Nginx</b></summary>

```nginx
server {
    listen 80;
    server_name causeries.example.com;
    root /var/www/causeries-quart-heure;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ \.(db|sqlite|env|md)$ { deny all; }
    location ~ ^/inc/ { deny all; }
}
```
</details>

---

## 🔒 Sécurité

- Accès au dashboard Prévention restreint par liste blanche d'emails
- Protection des fichiers sensibles (`.htaccess` / Nginx)
- Dossier `inc/` inaccessible depuis le web
- Upload de fichiers limité à 50 Mo

---

## 📊 Aperçu des endpoints API

| Méthode | Endpoint | Description |
|---|---|---|
| `POST` | `/api/login` | Connexion par email |
| `GET` | `/api/profil/{email}` | Profil utilisateur |
| `GET` | `/api/causeries/{email}` | Liste des causeries |
| `POST` | `/api/causeries` | Création d'une causerie |
| `GET` | `/api/pdf/{id}` | Export PDF |
| `GET` | `/api/health` | Statut du serveur |

---

## 📦 Dépendances

| Bibliothèque | Utilisation |
|---|---|
| [Dompdf](https://github.com/dompdf/dompdf) | Génération des exports PDF |
| [chillerlan/php-qrcode](https://github.com/chillerlan/php-qrcode) | QR codes sur les fiches PDF |
| [masterminds/html5](https://github.com/Masterminds/html5-php) | Parsing HTML5 pour Dompdf |

---

## 📄 Licence

MIT — utilisation libre, modification et distribution autorisées.

---

*Causeries 1/4h Sécurité — Solution de gestion des causeries sécurité pour les professionnels du BTP, de l'industrie et des services.*
