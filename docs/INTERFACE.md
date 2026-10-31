# Guide de l'interface — NIX Builder 0.1

NIX Builder génère des images NixOS pour VMware (OVA) et des projets Docker prêts à lancer. Le principe :
**on choisit une image de base, on ajoute des paquets et des services, l'application construit l'image**.

Sommaire :

1. [Page « Mes projets »](#1-page--mes-projets-)
2. [Créer ou modifier une VM](#2-créer-ou-modifier-une-vm)
3. [Panneau de droite : enregistrer, construire, aperçu](#3-panneau-de-droite--enregistrer-construire-aperçu)
4. [Suivre un build et télécharger l'image](#4-suivre-un-build-et-télécharger-limage)
5. [Importer dans VMware](#5-importer-dans-vmware)
6. [Premier démarrage de la VM](#6-premier-démarrage-de-la-vm)
7. [Messages et problèmes courants](#7-messages-et-problèmes-courants)

---

## 1. Page « Mes projets »

C'est la page d'accueil (lien **Mes projets** en haut à gauche).

### Partir d'une image de base

Deux cartes proposent un point de départ. Cliquer sur une carte ouvre l'éditeur pré-rempli.

| Image | Contenu | Ressources par défaut |
| --- | --- | --- |
| Poste développeur | Bureau GNOME, VS Code, Firefox, Python, Node.js, Go, gcc, Docker | 4 vCPU, 8 Go |
| Bureau léger | Bureau XFCE, Firefox, LibreOffice, VLC, KeePassXC | 2 vCPU, 4 Go |

Tout reste modifiable ensuite.

### Liste des VMs enregistrées

Chaque ligne affiche le nom, le nom d'hôte, la version NixOS, les ressources, le nombre de paquets,
les services et l'état du dernier build :

| État | Signification |
| --- | --- |
| Jamais construite | La configuration est enregistrée mais aucune image n'a été produite |
| En file / En cours | Un build attend ou tourne (un seul à la fois) |
| Prête | L'image est disponible, sa taille est affichée dessous |
| Échec / Annulé | Voir le journal dans l'éditeur |

Boutons :

- **Télécharger .ova** (bleu, seulement si l'état est « Prête ») : télécharge l'image du dernier build.
- **Modifier** : ouvre l'éditeur.
- **Dupliquer** : crée une copie « (copie) » de la configuration, sans les builds.
- **Supprimer** : supprime la VM **et toutes ses images construites** (confirmation demandée).

---

## 2. Créer ou modifier une VM

L'éditeur est divisé en sept sections numérotées à gauche, et un panneau fixe à droite.
Le nom de la VM se modifie en cliquant sur le grand titre en haut.

Chaque modification met à jour l'aperçu de droite en moins d'une seconde. Les champs invalides
sont entourés de rouge et listés dans un encadré au-dessus de l'aperçu.

### 1 · Image de base

- **Choix de l'image** : changer d'image propose de remplacer les paquets, services, bureau,
  vCPU et mémoire par ceux de la nouvelle image (une confirmation est demandée ; refuser garde tout).
- **Version NixOS** : *26.05 (stable)* recommandée ; *unstable* donne les versions les plus récentes.
- **Environnement de bureau** : aucun (serveur), GNOME, KDE Plasma 6 ou XFCE.
- **Nom d'hôte** : nom réseau de la VM (minuscules, chiffres et tirets). Il donne aussi le nom des fichiers
  (`<nom>.ova`).

### 2 · Paquets

- Les paquets sélectionnés apparaissent en pastilles en haut ; **×** pour en retirer un.
- **Barre de recherche** : taper au moins 2 lettres interroge tout nixpkgs (plus de 120 000 paquets).
  Cliquer sur un résultat l'ajoute (ou le retire s'il est déjà présent).
  **Entrée** ajoute le nom saisi tel quel, utile pour un nom exact comme `python3Packages.requests`.
- **Suggestions par catégorie** (Essentiels, Réseau, Développement…) : un clic ajoute ou retire.
- Badge **unfree** : logiciel non libre (VS Code, Terraform…). L'option « paquets non libres » est
  alors activée automatiquement, sinon le build échouerait.
- Pastille **orange** : paquet introuvable dans la version NixOS choisie. Vérifier l'orthographe avec
  la recherche, sinon le build échouera.

### 3 · Services

Cocher une carte active le service au démarrage de la VM. Les ports nécessaires sont ouverts dans
le pare-feu automatiquement (indiqués sous le nom du service).

Docker et Podman ajoutent aussi l'utilisateur au groupe requis.

### 4 · Accès

- **Utilisateur** : compte créé dans la VM, avec droits `sudo`.
- **Mot de passe** : obligatoire si aucune clé SSH n'est fournie (8 caractères minimum).
  Il est stocké uniquement sous forme hachée. Sur une VM déjà enregistrée, laisser le champ vide
  conserve l'ancien mot de passe.
- **Clés SSH publiques** : optionnelles, une par ligne (`ssh-ed25519 AAAA… vous@poste`).
- **Activer le serveur SSH** : coché par défaut.
- **Connexion SSH par mot de passe** : cochée par défaut. Sans clé SSH, elle reste activée de toute façon
  (c'est alors le seul moyen de se connecter). Avec une clé, la décocher réserve SSH aux clés.
- **sudo sans mot de passe** : pratique pour l'automatisation, moins sûr.
- **Fuseau horaire, langue, clavier** : par défaut Europe/Paris, français, AZERTY.

### 5 · Réseau

- **DHCP** (par défaut) : l'adresse est fournie par le réseau VMware.
- **IP statique** : renseigner l'adresse avec son masque (`192.168.1.50/24`), la passerelle et les DNS.
- **Pare-feu** : activé par défaut. **Ports TCP supplémentaires** : liste séparée par des espaces (`8080 3000`).
  SSH (22) et les ports des services cochés sont ouverts automatiquement.

### 6 · Machine virtuelle VMware

- **vCPU, mémoire, disque** : ressources de la VM. Disque à `0` = taille minimale calculée automatiquement.
  Dans tous les cas, la partition s'agrandit seule si on augmente le disque dans VMware plus tard.
- **Format du disque** :
  - *streamOptimized* (recommandé) : produit un fichier **.ova** unique, importable dans ESXi, vSphere et Workstation.
  - *monolithicSparse* : produit un **.vmdk** + **.vmx** pour Workstation / Fusion / Player.
  - *twoGbMaxExtentSparse* : disque découpé en fichiers de 2 Go (anciens systèmes de fichiers).
- **Version matérielle virtuelle** : choisir une version supportée par l'hôte VMware le plus ancien
  (vmx-19 = ESXi 7.0 U2 / Workstation 16.2).

### 7 · Avancé (section repliée)

- **Paquets non libres** : activé automatiquement si nécessaire.
- **Mises à jour automatiques** : la VM se met à jour chaque nuit à 4 h.
- **VMDK version 6** : uniquement si un outil VMware ancien l'exige.
- **Configuration NixOS personnalisée** : options NixOS libres, écrites dans `custom.nix`. Exemple :

  ```nix
  services.openssh.ports = [ 2222 ];
  programs.zsh.enable = true;
  ```

  Une erreur de syntaxe ici fera échouer le build (le journal indique la ligne).

---

## 3. Panneau de droite : enregistrer, construire, aperçu

- **Enregistrer et construire l'image** (bleu) : enregistre puis lance la construction.
- **Enregistrer** : enregistre sans construire.
L'enregistrement est refusé tant que l'encadré rouge « À corriger » contient des erreurs.

**Aperçu** : onglets montrant les fichiers qui seront utilisés :

| Fichier | Rôle |
| --- | --- |
| `configuration.nix` | La configuration NixOS complète de la VM |
| `flake.nix` | Version de NixOS utilisée et point d'entrée du build |
| `custom.nix` | Présent si la configuration personnalisée est remplie |
| `<nom>.vmx` | Description matérielle pour VMware Workstation |
| `build.sh` | Script pour construire l'image ailleurs |
| `README.md` | Notice d'import et de connexion |

Le bouton **Copier** copie l'onglet affiché.

---

## 4. Suivre un build et télécharger l'image

Après « Enregistrer et construire », le panneau **Builds** apparaît sous les boutons. Il se met à jour
tout seul toutes les 3 secondes.

Durée indicative sur ce serveur (sans accélération KVM) :

| Image | Durée | Taille de l'OVA |
| --- | --- | --- |
| Poste développeur (GNOME) | ~9 min | ~3,3 Go |

Le premier build d'un type d'image est plus long (téléchargement des paquets) ; les suivants
réutilisent le cache.

Pour chaque build :

- **Journal** : affiche le déroulé en direct (défile automatiquement), avec un bouton **Télécharger**.
- **Annuler** : arrête un build en cours ou en attente.
- **Supprimer** : efface un build terminé et ses fichiers (libère de la place).
- Une fois **Prête**, les fichiers apparaissent :
  - **OVA** (bleu) : l'image à importer dans VMware ;
  - **OVF**, **VMDK** : le contenu de l'OVA en fichiers séparés (import « OVF + disque ») ;
  - **Manifeste** : empreintes SHA-256 de l'OVF et du VMDK, pour vérifier un téléchargement.
    Il n'est pas inclus dans l'OVA, car VMware Workstation le refuse à tort ;
  - **VMX** : uniquement pour les formats autres que streamOptimized.

Le téléchargement est aussi accessible depuis la page **Mes projets** (bouton **Télécharger .ova**).

Modifier une VM ne change pas les images déjà construites : relancer **Enregistrer et construire**
pour produire une nouvelle version. L'historique des builds est conservé.

---

## 5. Importer dans VMware

**ESXi / vSphere**

1. Clic droit sur l'hôte ou le cluster → **Déployer un modèle OVF**.
2. Choisir **Fichier local** → sélectionner le `.ova`.
3. Choisir le datastore et le réseau (le réseau du fichier s'appelle « VM Network »).
4. Terminer, puis démarrer la VM.

**VMware Workstation / Fusion**

- **Fichier > Ouvrir** → sélectionner le `.ova` → **Importer**.
- Format monolithicSparse : placer le `.vmx` et le `.vmdk` dans le même dossier, puis ouvrir le `.vmx`.

La VM démarre en UEFI, avec un contrôleur PVSCSI, une carte VMXNET3 et les open-vm-tools
(synchronisation de l'heure, arrêt propre, presse-papiers sur les bureaux).

---

## 6. Premier démarrage de la VM

- Se connecter avec l'utilisateur et le mot de passe définis dans la section **Accès**
  (en console VMware, ou `ssh <utilisateur>@<ip>`). L'IP est visible dans le résumé de la VM côté VMware.
- Changer le mot de passe : `passwd`.
- La configuration est copiée dans `/etc/nixos`. Pour modifier la VM depuis l'intérieur :

  ```bash
  sudo nano /etc/nixos/configuration.nix
  sudo nixos-rebuild switch --flake /etc/nixos#<nom-d-hôte>
  ```

---

## 7. Messages et problèmes courants

| Message | Signification / solution |
| --- | --- |
| Note grise « les images se construisent normalement, sans accélération matérielle » | Simple information, rien à faire. Avec le docker-compose fourni et un hôte qui a KVM, les builds sont plus rapides et la note disparaît. |
| « Nix n'est pas installé sur ce serveur » | Le bouton devient « Enregistrer et télécharger le projet ». Redémarrer le conteneur (installation automatique) ou lancer `sudo bin/install-nix.sh`. |
| « Espace disque insuffisant » | Il faut 30 Go libres pour les fichiers temporaires et 10 Go pour `/nix`. Supprimer d'anciens builds ou VMs, ou agrandir le disque. |
| « Le processus de build s'est arrêté de façon inattendue » | Le serveur a redémarré pendant le build. Relancer le build. |
| Pastille de paquet orange | Nom introuvable dans la version NixOS choisie : corriger via la recherche. |
| Build en échec avec `unfree` dans le journal | Cocher « Autoriser les paquets non libres » (section Avancé). |
| Build en échec avec `error: undefined variable` | Faute de frappe dans un nom de paquet ou dans la configuration personnalisée. |
| « search.nixos.org indisponible » | La recherche en ligne ne répond pas : saisir le nom exact du paquet puis Entrée. |

L'application n'a pas d'authentification : elle doit rester sur un réseau interne.
