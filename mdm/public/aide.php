<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/layout.php';
require_once dirname(__DIR__) . '/lib/AppSettings.php';

use NixVm\Auth;

Auth::requireLogin();

$version = defined('APP_VERSION') ? APP_VERSION : '0.3.9';
$installUrl = (new AppSettings())->installScriptUrl('default', true);

layout_header('Documentation', 'aide');
?>
<section class="hero">
  <div>
    <h1>Linux MDM <?= e($version) ?></h1>
    <p>Guide rapide — profils versionnés, machines / dossiers, magasin et Centre Logiciels.</p>
  </div>
</section>

<div class="card" style="margin-top:1rem">
  <h2>Qu’est-ce que c’est ?</h2>
  <p><strong>Linux MDM</strong> gère un parc de postes <strong>NixOS</strong> : profils logiciels versionnés, inventaire machines (dossiers), magasin DSI et application client <strong>Centre Logiciels</strong>.</p>
</div>

<div class="card" style="margin-top:1rem">
  <h2>Rôles</h2>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr><th>Écran</th><th>Rôle</th></tr>
      </thead>
      <tbody>
        <tr><td><strong>Profils</strong></td><td>Paquets + fréquence, publier, versions / restauration, <strong>dossiers</strong>, script d’install</td></tr>
        <tr><td><strong>Machines</strong></td><td>Inventaire, profil assigné, <strong>dossiers</strong> (glisser-déposer)</td></tr>
        <tr><td><strong>Logiciels</strong></td><td>Voir / retirer les paquets d’un profil</td></tr>
        <tr><td><strong>Magasin</strong></td><td>Catalogue DSI validé ; recherche nixpkgs pour ajouter des apps</td></tr>
        <tr><td><strong>Configuration</strong></td><td>URL publique du hub, logo, identité (admin)</td></tr>
        <tr><td><strong>Utilisateurs</strong></td><td>Comptes hub, rôles admin / DSI (admin)</td></tr>
        <tr><td><strong>Changelog</strong></td><td>Historique des versions produit</td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-top:1rem">
  <h2>Déployer un client NixOS</h2>
  <p>Sur le poste (root), avec le script du profil publié (URL réglable dans <strong>Configuration</strong>) :</p>
  <pre class="code-light"><code>curl -fsSL <?= e($installUrl) ?> | sudo bash</code></pre>
  <p>Enregistrement hub, hostname auto <code>nixos-&lt;mac&gt;</code>, <code>/etc/nixos/mdm.auth</code>, import <code>parc.nix</code> + <code>mdm-hostname.nix</code>, timer de sync.</p>
  <p>Forcer une sync :</p>
  <pre class="code-light"><code>sudo systemctl start nix-builder-mdm-sync.service</code></pre>
</div>

<div class="card" style="margin-top:1rem">
  <h2>Profils versionnés</h2>
  <ul>
    <li>Chaque publication (ou retrait depuis Logiciels) crée une version</li>
    <li>Sur <strong>Profils</strong> : panneau Versions → Voir → Restaurer</li>
    <li>La restauration crée une <strong>nouvelle</strong> version (l’historique n’est pas écrasé)</li>
  </ul>
</div>

<div class="card" style="margin-top:1rem">
  <h2>Machines et dossiers</h2>
  <ul>
    <li>Créer / renommer / supprimer des dossiers sur <strong>Machines</strong></li>
    <li>Glisser-déposer les postes entre dossiers</li>
    <li>Le dossier classe l’inventaire ; le <strong>profil</strong> (liste) détermine les logiciels</li>
  </ul>
</div>

<div class="card" style="margin-top:1rem">
  <h2>Application client « Centre Logiciels »</h2>
  <p>Après sync : menu <strong>Applications → Centre Logiciels</strong> (<code>linux-mdm-centre</code>).</p>
  <ul>
    <li>Fenêtre autonome (Chromium embarqué)</li>
    <li>Onglets <strong>Installés</strong> / <strong>Magasin DSI</strong> / <strong>Journal</strong></li>
    <li>Bouton <strong>Synchroniser</strong> (spinner dans la barre) pour forcer une sync</li>
    <li>Installer / retirer : spinner sur la carte concernée + sync automatique</li>
    <li>Listes actualisées en direct (pas besoin de fermer l’app)</li>
  </ul>
  <p>Si le lanceur ne lit pas le token :</p>
  <pre class="code-light"><code>sudo chmod 644 /etc/nixos/mdm.auth</code></pre>
</div>

<div class="card" style="margin-top:1rem">
  <h2>Flux typique</h2>
  <ol>
    <li>Créer / éditer un <strong>profil</strong> (paquets + fréquence) → <strong>Publier</strong></li>
    <li>Ajouter des apps au <strong>Magasin</strong> (recherche nixpkgs)</li>
    <li>Installer le client avec le script du profil</li>
    <li>Classer la machine dans un <strong>dossier</strong> si besoin</li>
    <li>Sur le poste : ouvrir <strong>Centre Logiciels</strong>, choisir des apps DSI → sync auto</li>
  </ol>
</div>

<div class="card" style="margin-top:1rem">
  <h2>Fichiers utiles côté client</h2>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr><th>Fichier / unité</th><th>Rôle</th></tr>
      </thead>
      <tbody>
        <tr><td><code>/etc/nixos/mdm.auth</code></td><td><code>MDM_URL</code>, <code>MDM_DEVICE_TOKEN</code></td></tr>
        <tr><td><code>/etc/nixos/parc.nix</code></td><td>Module généré par le hub</td></tr>
        <tr><td><code>/etc/nixos/mdm-hostname.nix</code></td><td>Hostname <code>nixos-&lt;mac&gt;</code></td></tr>
        <tr><td><code>nix-builder-mdm-sync.timer</code></td><td>Sync périodique</td></tr>
        <tr><td><code>linux-mdm-local-agent</code></td><td>Agent 127.0.0.1:17380 (sync + journal)</td></tr>
        <tr><td><code>linux-mdm-sync-now</code></td><td>CLI / protocole <code>linux-mdm://sync-now</code></td></tr>
        <tr><td><code>linux-mdm-centre</code></td><td>Application Centre Logiciels</td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-top:1rem">
  <h2>Notes</h2>
  <ul>
    <li>Les paquets <strong>unfree</strong> (vscode, brave, onlyoffice…) nécessitent <code>allowUnfree</code> (déjà dans le module généré).</li>
    <li>Le premier sync qui installe <strong>Centre Logiciels</strong> peut être long (téléchargement de Chromium embarqué).</li>
    <li>Historique détaillé : <a href="/mdm/changelog.php">Changelog</a>.</li>
  </ul>
</div>
<?php
layout_footer();
