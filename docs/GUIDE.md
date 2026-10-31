# Linux MDM 0.4.0 — guide rapide

## Qu’est-ce que c’est ?

**Linux MDM** gère un parc de postes **NixOS** : profils logiciels versionnés, inventaire machines (dossiers), magasin DSI et application client **Centre Logiciels**.

Interface : [https://mdmlinux.borninthe80s.fr/](https://mdmlinux.borninthe80s.fr/)

## Rôles

| Écran | Rôle |
| --- | --- |
| **Tableau de bord** | Compteurs parc, hors sync, alertes, dernières publications |
| **Profils** | Paquets + options système + fréquence, **publier**, versions / restauration, **dossiers imbriqués**, script d’install |
| **Machines** | Inventaire, statut sync (OK/KO, gen Nix, version, erreur), dossiers imbriqués |
| **Logiciels** | Voir / retirer les paquets d’un profil |
| **Magasin** | Catalogue DSI validé ; recherche nixpkgs |
| **Documentation** | Ce guide dans l’UI |
| **Configuration** | **URL publique du hub**, logo, identité (admin) |
| **Utilisateurs** | Comptes hub, rôles **admin** / **DSI** |
| **Changelog** | Historique des versions produit |

Connexion admin : `/login.php` (sessions PHP).

## Déployer un client NixOS

Sur le poste (root), avec le script du profil publié.
L’URL se règle dans **Configuration** (sinon détection auto de l’hôte) :

```bash
curl -fsSL https://VOTRE-SERVEUR/mdm/generated/install-on-client.sh | sudo bash
```

Cela :

1. Détecte la MAC et fixe le hostname `nixos-<mac>` (`/etc/nixos/mdm-hostname.nix`)
2. Enregistre l’appareil sur le hub
3. Écrit `/etc/nixos/mdm.auth`, importe `parc.nix` (+ hostname)
4. Active le timer de sync

Forcer une sync :

```bash
sudo systemctl start nix-builder-mdm-sync.service
```

## Profils et versions

- Chaque **publication** (ou retrait de paquet depuis Logiciels) crée une version
- Sur **Profils** : panneau **Versions** → Voir (paquets + diff) → **Restaurer**
- La restauration republie le contenu et crée une **nouvelle** version (l’historique reste)

## Machines et dossiers

- Créer des dossiers / **sous-dossiers** depuis **Machines** (et idem pour **Profils**)
- Glisser-déposer postes, profils et dossiers (poignée ⠿) entre tous les niveaux
- Le **dossier** classe l’inventaire ; le **profil** (liste) détermine les logiciels
- Appliquer un profil à tout un dossier (y compris sous-dossiers)
- Après chaque rebuild, le client envoie un **rapport** : OK/échec, génération Nix, version de profil, erreur
- En cas d’échec `nixos-rebuild`, le client tente un **rollback** vers la génération précédente et alerte le hub

## Options système (profil)

Sur **Profils**, section *Options système NixOS* : SSH, pare-feu, CUPS, fuseau, locale, proxy — écrites dans `parc.nix` à la publication.

## Application client « Centre Logiciels »

Après sync, menu **Applications → Centre Logiciels** (`linux-mdm-centre`).

- Fenêtre autonome (Chromium embarqué)
- Onglets **Installés** / **Magasin DSI** / **Journal**
- Bouton **Synchroniser** (spinner dans la barre, non bloquant)
- Installer / retirer : spinner sur la carte + sync immédiate
- Listes actualisées en direct (focus / polling)

Si le lanceur ne lit pas le token :

```bash
sudo chmod 644 /etc/nixos/mdm.auth
```

## Flux typique

1. Créer / éditer un **profil** (paquets + fréquence) → **Publier**
2. Ajouter des apps au **Magasin** (recherche nixpkgs)
3. Installer le client avec le script du profil
4. Classer la machine dans un **dossier** si besoin
5. Sur le poste : ouvrir **Centre Logiciels**, choisir des apps DSI
6. Suivre la sync (barre / Journal) ou forcer **Synchroniser**

## Fichiers utiles côté client

| Fichier / unité | Rôle |
| --- | --- |
| `/etc/nixos/mdm.auth` | `MDM_URL`, `MDM_DEVICE_TOKEN` |
| `/etc/nixos/parc.nix` | Module généré par le hub |
| `/etc/nixos/mdm-hostname.nix` | `networking.hostName = nixos-<mac>` |
| `nix-builder-mdm-sync.timer` | Sync périodique |
| `linux-mdm-local-agent` | Agent `127.0.0.1:17380` (sync + journal) |
| `linux-mdm-sync-now` | CLI / protocole `linux-mdm://sync-now` |
| `linux-mdm-centre` | Application Centre Logiciels |

## Notes

- Les paquets **unfree** (vscode, brave, onlyoffice…) nécessitent `allowUnfree` (déjà dans le module généré).
- Le premier sync qui installe **Centre Logiciels** peut être long (téléchargement de Chromium embarqué).
- Historique produit : [CHANGELOG.md](../CHANGELOG.md) ou `/mdm/changelog.php`.
