<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/layout.php';

use NixVm\Auth;

Auth::requireLogin();

$version = defined('APP_VERSION') ? APP_VERSION : 'dev';
$path = APP_ROOT . '/CHANGELOG.md';
$raw = is_file($path) ? (string) file_get_contents($path) : "# Changelog\n\nFichier CHANGELOG.md introuvable.\n";

/**
 * Rendu Markdown minimal (titres, listes, gras, code) pour le CHANGELOG.
 */
function changelog_render(string $md): string
{
    $lines = preg_split("/\r\n|\n|\r/", $md) ?: [];
    $html = '';
    $inUl = false;
    $flushUl = static function () use (&$html, &$inUl): void {
        if ($inUl) {
            $html .= "</ul>\n";
            $inUl = false;
        }
    };
    $inline = static function (string $s): string {
        $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s) ?? $s;
        $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s) ?? $s;
        return $s;
    };

    $openArticle = false;
    foreach ($lines as $line) {
        if (preg_match('/^##\s+(.+)$/', $line, $m)) {
            $flushUl();
            if ($openArticle) {
                $html .= "</article>\n";
            }
            $html .= '<article class="cl-release card"><h2>' . $inline($m[1]) . "</h2>\n";
            $openArticle = true;
            continue;
        }
        if (preg_match('/^###\s+(.+)$/', $line, $m)) {
            $flushUl();
            $html .= '<h3>' . $inline($m[1]) . "</h3>\n";
            continue;
        }
        if (preg_match('/^#\s+(.+)$/', $line, $m)) {
            $flushUl();
            continue;
        }
        if (preg_match('/^-\s+(.+)$/', $line, $m)) {
            if (!$inUl) {
                $html .= "<ul>\n";
                $inUl = true;
            }
            $html .= '<li>' . $inline($m[1]) . "</li>\n";
            continue;
        }
        $trim = trim($line);
        if ($trim === '') {
            $flushUl();
            continue;
        }
        $flushUl();
        $html .= '<p>' . $inline($trim) . "</p>\n";
    }
    $flushUl();
    if ($openArticle) {
        $html .= "</article>\n";
    }
    return $html;
}

layout_header('Changelog', 'changelog');
?>
<section class="hero">
  <div>
    <h1>Changelog</h1>
    <p>Historique des versions de <strong>Linux MDM</strong> — version courante <strong>v<?= e($version) ?></strong>.</p>
  </div>
</section>

<div class="cl-body">
  <?= changelog_render($raw) ?>
</div>
<?php
layout_footer();
