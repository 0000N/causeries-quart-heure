# CHANGELOG

## 2.1.0 — 11 juin 2026

### Évolutions fonctionnelles
- Workflow d'amélioration continue : soumission et validation des suggestions directement depuis l'interface
- Dashboard Prévention enrichi avec suivi des demandes d'évolution
- Export PDF optimisé pour l'archivage et l'impression

### Performance & Stabilité
- Architecture monolithique PHP — déploiement simplifié, maintenance réduite
- Compatibilité renforcée avec les hébergements mutualisés (Apache, Nginx)
- Suppression des dépendances Python — 100 % PHP, 0 bibliothèque externe
- Base de données allégée et optimisée

### Documentation
- Guide d'installation complet — procédure ZIP prête pour o2switch/OVH
- Documentation des endpoints API REST
- Notes de version structurées

---

## 2.0.0 — 10 juin 2026

### Refonte majeure
- Migration complète de Python/Flask vers PHP — application autonome, zéro dépendance système
- Point d'entrée unique `index.php` compatible Apache, Nginx et `php -S`
- Générateur PDF intégré (Dompdf) avec QR code, badges thèmes et dégradé
- Application PWA — installation sur mobile, fonctionnement hors-ligne partiel
- Interface responsif — utilisation sur chantier, tablette et desktop
- Suppression du bot de messagerie — application 100 % web

### Nouvelles fonctionnalités
- Workflow validation/rejet des causeries avec suivi des statuts (couleurs)
- Reprise et re-soumission des causeries refusées
- Plans d'action avec commentaires et suivi

---

## 1.5.0 — 9 juin 2026

- Demandes d'amélioration logiciel (suggestions utilisateur → validation → développement)
- Notes de version automatiques
- Architecture 2 serveurs (animateur + prévention)

## 1.4.0 — 9 juin 2026

- Dashboard Prévention dédié avec accès restreint
- Workflow validation/rejet des causeries
- Plans d'action

## 1.3.0 — 9 juin 2026

- Multi-rôles (animateur / prévention)
- Dashboard consolidé

## 1.2.0 — 9 juin 2026

- Export PDF professionnel (gradient, badges, QR code)
- Tags thèmes colorés

## 1.1.0 — 9 juin 2026

- 12 thèmes sécurité guidés avec checklists terrain
- Mode Guide par défaut
- Export PDF (première version)
- Corrections : signatures noires, historique vide, suppression

## 1.0.0 — 9 juin 2026

- Première version de l'application
- Connexion email, causeries, participants, signatures
- Historique, photos, partage entre équipes
- Certification, PWA, suggestions
