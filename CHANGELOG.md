# Changelog — Linux MDM

Historique des versions du hub et du Centre Logiciels.

## 0.4.0 — 2026-10-31

### Statut machines
- Rapport client post-rebuild : **OK / échec**, génération Nix, version de profil, message d’erreur
- Affichage sur les cartes Machines (pastille + détail)
- API `client-api.php?action=sync-report`

### Rollback client
- Si `nixos-rebuild` échoue : retour à la génération précédente puis alerte au hub (`rolled_back`)

### Options système NixOS (profil)
- SSH, pare-feu, imprimantes (CUPS), fuseau, locale, proxy HTTP
- Générés dans `parc.nix` à la publication

### Tableau de bord
- Page **Tableau de bord** : total machines, sync OK, hors sync, échecs, dernières publications et alertes

## 0.3.9 — 2026-10-30

### Dossiers
- **Dossiers imbriqués** (sous-dossiers) pour Machines et Profils
- Création à la racine ou dans un parent ; bouton **+** sur chaque dossier
- Suppression d’un dossier : sous-dossiers inclus ; contenus → « Sans dossier »
- **Drag & drop** machines/profils **et dossiers** entre tous les niveaux (poignée ⠿)
- Profil → dossier s’applique aussi aux machines des sous-dossiers

### Profils
- **Suppression** d’un profil depuis sa carte (sauf profil par défaut)

### Clients / Centre Logiciels
- Hostname MDM en `lib.mkForce` (plus de conflit avec `configuration.nix`)
- Correction fatal error PHP du Centre Logiciels (ternaire imbriqué)

## 0.3.8 — 2026-10-30

### Interface
- **Mode sombre** (bascule lune / soleil dans la barre du haut)
- Préférence mémorisée ; défaut = thème système
- Hub + Centre Logiciels

## 0.3.7 — 2026-10-30

### Utilisateurs
- Page **Utilisateurs** (`/mdm/utilisateurs.php`) : créer, rôles, mot de passe, suppression
- Rôles **admin** (outil + comptes) et **DSI** (profils / machines / logiciels)
- Configuration et Utilisateurs réservés aux administrateurs

## 0.3.6 — 2026-10-30

### Machines
- **Appliquer un profil à tout un dossier** (sélecteur en tête de dossier, y compris « Sans dossier »)
- Renommage des dossiers au **clic sur le nom** (plus de bouton Renommer)

### Configuration
- Alignement des boutons (URL hub, logo / icône)

## 0.3.5 — 2026-10-30

### Configuration
- **URL publique du hub** réglable dans Configuration (utilisée par `install-on-client.sh` / `parc.nix`)
- Enregistrement → régénération automatique des scripts de tous les profils
- Mode auto (détection HTTP) si le champ est laissé vide

## 0.3.4 — 2026-10-30

### Profils
- **Dossiers** pour organiser les profils (création / renommage / suppression)
- Classement des profils par **glisser-déposer** (comme pour les machines)

## 0.3.3 — 2026-10-30

### Configuration de l’outil
- Nouvelle page **Configuration** (`/mdm/configuration.php`)
- Personnalisation du **logo**, de l’**icône**, du nom d’organisation et du produit
- Extensible : d’autres réglages globaux seront ajoutés ici

## 0.3.2 — 2026-10-30

### Machines
- **Dossiers** créables, renommables et suppressibles dans l’UI
- Classement des postes par **glisser-déposer** (indépendant du profil MDM)
- Hostname client généré automatiquement : `nixos-<mac>` (ex. `nixos-a1b2c3d4e5f6`)

### Profils
- Historique de **versions** à chaque publication / création / retrait de paquet
- Consultation du contenu d’une version (paquets, réglages, diff) et **restauration**
- Champs avancés « Opération après sync » / « Délai aléatoire » masqués (valeurs par défaut conservées)

### Centre Logiciels & docs
- Rafraîchissement live, spinners non bloquants, onglet Journal
- Page **Changelog** dans l’UI (`/mdm/changelog.php`) + lien en footer
- Documentation et guide alignés sur 0.3.2

## 0.3.1 — 2026-10-30

### Profils versionnés
- Historique automatique à chaque **publication**, création ou retrait de paquet
- Liste des versions avec résumé des changements (+ / − paquets)
- Détail d’une version (paquets, réglages) et **restauration** (crée une nouvelle version)

## 0.3.0 — 2026-10-30

Version **Linux MDM 0.3**.

### Centre Logiciels
- Rafraîchissement live (focus, polling, anti-cache) sans fermer l’application
- Bouton **Synchroniser** avec spinner dans la barre (non bloquant)
- Spinner sur la carte de l’application en cours d’install / retrait
- Onglet **Journal** : événements UI + journal systemd de la sync locale
- Agent local : endpoints `/sync-status` et `/sync-log`

### Hub / déploiement
- Script d’install client plus robuste (`imports` multilignes)
- App client renommée **Centre Logiciels**
- Script de backup de release (`bin/backup-release.sh`)

## 0.2.0 — 2026-10-13

Version **Linux MDM 0.2**.

### Administration
- Produit **Linux MDM** (UI MaterialPro, logo Université de Tours)
- **Profils** multi-profils MySQL, publication `parc.nix` + `install-on-client.sh`
- **Machines** : inventaire et assignation de profil
- **Logiciels** : vue / retrait des paquets du profil
- **Magasin** : catalogue DSI + recherche nixpkgs
- Auth applicative (login / session)
- Page **Documentation** (`/mdm/aide.php`)

### Client NixOS
- Application **Logiciels** (`linux-mdm-centre`, Chromium embarqué)
- Sync : `parc-for-device.php`, timer systemd, open-vm-tools, SSH, `allowUnfree`
- Sync immédiate : agent `linux-mdm-local-agent` + `linux-mdm-sync-now` / Polkit

## 0.1.0 — 2026-10-07

Première base technique (**NIX Builder**) : éditeur de VM NixOS, builds OVA, pile Docker PHP + MariaDB + Nix.
