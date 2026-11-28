<?php
/* Gouyerian Tech : contenu du site modifiable depuis la gestion (/gestion/site.html).
   - GET  ?c=1        : contenu publié (public, lu par le site à chaque visite ; aussi intégré aux pages à chaque publication)
   - GET  ?m=fichier  : photo envoyée depuis la gestion (publique, jamais modifiée : nom unique)
   - POST (atelier connecté, en-tête « X-GT: 1 ») : enregistrer prix, boutique, actualités, articles, textes et photos.
   Les fichiers sont gardés dans /prive (hors du dossier publié, que chaque publication remplace). */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

const SITE_CLES = ['textes', 'images', 'sv', 'shop', 'prix', 'news', 'arts'];
const MEDIA_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

function medias_dir(): string { return prive_dir() . '/medias'; }

function site_contenu(): array {
  $c = ['t' => (int)meta('site_t', '0')];
  foreach (db()->query('SELECT cle, valeur FROM gt_site') as $r) {
    if (in_array($r['cle'], SITE_CLES, true)) $c[$r['cle']] = json_decode((string)$r['valeur'], true);
  }
  return $c;
}

function site_poser(string $cle, $valeur): void {
  $pdo = db();
  $pdo->prepare('DELETE FROM gt_site WHERE cle = ?')->execute([$cle]);
  if ($valeur !== null) {
    $j = json_encode($valeur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (strlen($j) > 4000000) fail('Contenu trop long.', 413);
    $pdo->prepare('INSERT INTO gt_site (cle, valeur, maj_le) VALUES (?, ?, ?)')->execute([$cle, $j, now()]);
  }
  meta_set('site_t', (string)max(time(), (int)meta('site_t', '0') + 1));
}

/* ---------- Nettoyage : texte simple, HTML limité (gras, liens…) ou riche (titres, listes) ---------- */
function plain($v, int $max): string { return txt(str_replace('"', '”', strip_tags(is_scalar($v) ? (string)$v : '')), $max); }  /* pas de guillemet droit : le texte peut finir dans un attribut */

function lien_ok(string $u): bool { return (bool)preg_match('~^(https?://|/|#|mailto:|tel:)~i', $u) && !preg_match('/[\s"\'<>]/', $u); }

function propre_html($v, int $max, bool $riche = false): string {
  $h = is_scalar($v) ? (string)$v : '';
  if (strlen($h) > $max * 2) fail('Texte trop long.', 413);
  $ok = ['b', 'strong', 'i', 'em', 'u', 'a', 'br', 'small', 'span', 'sup', 'sub', 'mark'];
  if ($riche) $ok = array_merge($ok, ['p', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote']);
  if (!class_exists('DOMDocument')) {
    $h = strip_tags($h, '<' . implode('><', $ok) . '>');
    $h = preg_replace('/<(\w+)\b[^>]*>/', '<$1>', $h) ?? '';  /* sans DOM : on retire tous les attributs (les liens deviennent du texte) */
    return mb_substr(trim($h), 0, $max);
  }
  $doc = new DOMDocument();
  libxml_use_internal_errors(true);
  $doc->loadHTML('<?xml encoding="utf-8"?><div id="gtr">' . $h . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
  libxml_clear_errors();
  $root = $doc->getElementById('gtr');
  if (!$root) return '';
  $nettoyer = function (DOMNode $n) use (&$nettoyer, $ok) {
    foreach (iterator_to_array($n->childNodes) as $c) {
      if ($c instanceof DOMElement) {
        $tag = strtolower($c->tagName);
        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'form'], true)) { $n->removeChild($c); continue; }
        $nettoyer($c);
        if (!in_array($tag, $ok, true)) {  /* balise non autorisée : on garde seulement son contenu */
          if ($tag === 'div' || $tag === 'p') { $n->insertBefore($n->ownerDocument->createElement('br'), $c); }
          while ($c->firstChild) $n->insertBefore($c->firstChild, $c);
          $n->removeChild($c);
          continue;
        }
        $href = $tag === 'a' ? trim($c->getAttribute('href')) : '';
        foreach (iterator_to_array($c->attributes) as $at) $c->removeAttribute($at->nodeName);
        if ($tag === 'a') {
          if ($href !== '' && lien_ok($href)) {
            $c->setAttribute('href', $href);
            if (preg_match('~^https?://~i', $href) && !preg_match('~^https?://(www\.)?gouyerian-tech\.fr~i', $href)) { $c->setAttribute('target', '_blank'); $c->setAttribute('rel', 'noopener'); }
          }
        }
      } elseif (!($c instanceof DOMText)) {
        $n->removeChild($c);
      }
    }
  };
  $nettoyer($root);
  $out = '';
  foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
  $out = preg_replace('/^(<br>)+|(<br>)+$/', '', trim($out)) ?? '';
  return mb_substr($out, 0, $max);
}

function sources($v): array {
  $o = [];
  foreach (is_array($v) ? array_slice($v, 0, 12) : [] as $s) {
    if (!is_array($s) || count($s) < 2) continue;
    $l = plain($s[0], 200); $u = txt($s[1], 500);
    if ($l !== '' && preg_match('~^https?://~i', $u) && lien_ok($u)) $o[] = [$l, $u];
  }
  return $o;
}

function media_url($v): string {
  $u = txt($v, 200);
  return preg_match('~^/api/site\.php\?m=[a-f0-9]{24}\.(jpg|png|webp|gif)$~', $u) ? $u : '';
}

function date_ok($v): string { $d = txt($v, 10); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : date('Y-m-d'); }

/* ---------- Contrôle de chaque partie du contenu ---------- */
function valider(string $cle, $v) {
  if ($v === null) return null;
  if (!is_array($v)) fail('Format incorrect.');
  switch ($cle) {
    case 'textes':
      $o = [];
      foreach (array_slice($v, 0, 4000, true) as $k => $h) {
        $k = txt($k, 120);
        if ($k !== '' && is_scalar($h)) $o[$k] = propre_html($h, 6000);
      }
      return $o ?: null;
    case 'images':
      $o = [];
      foreach (array_slice($v, 0, 1000, true) as $k => $u) {
        $k = txt($k, 200); $u = media_url($u);
        if ($k !== '' && $u !== '') $o[$k] = $u;
      }
      return $o ?: null;
    case 'sv':
      $o = [];
      foreach (array_slice($v, 0, 300) as $s) {
        if (!is_array($s) || plain($s['n'] ?? '', 140) === '') continue;
        $x = ['c' => plain($s['c'] ?? '', 40), 'n' => plain($s['n'], 140), 'd' => plain($s['d'] ?? '', 600), 'p' => plain($s['p'] ?? '', 60)];
        if (plain($s['t'] ?? '', 60) !== '') $x['t'] = plain($s['t'], 60);
        if (plain($s['need'] ?? '', 60) !== '') $x['need'] = plain($s['need'], 60);
        foreach (['w', 'mac'] as $f) if (!empty($s[$f])) $x[$f] = 1;
        foreach (['bd' => 600, 'bn' => 140] as $f => $m) if (!empty($s[$f]) && is_array($s[$f])) {  /* texte selon la marque choisie */
          $y = [];
          foreach (array_slice($s[$f], 0, 30, true) as $k => $tx) { $k = plain($k, 60); $tx = plain($tx, $m); if ($k !== '' && $tx !== '') $y[$k] = $tx; }
          if ($y) $x[$f] = $y;
        }
        if (!empty($s['bo']) && is_array($s['bo'])) $x['bo'] = array_values(array_filter(array_map(fn($b) => plain($b, 60), array_slice($s['bo'], 0, 30))));
        if (!empty($s['b']) && is_array($s['b'])) $x['b'] = array_values(array_filter(array_map(fn($b) => plain($b, 40), array_slice($s['b'], 0, 40))));
        $o[] = $x;
      }
      return $o;
    case 'shop':
      $o = [];
      foreach (array_slice($v, 0, 300) as $s) {
        if (!is_array($s) || plain($s['n'] ?? '', 140) === '') continue;
        $x = ['c' => plain($s['c'] ?? '', 40), 'n' => plain($s['n'], 140), 'd' => plain($s['d'] ?? '', 600), 'ic' => preg_replace('/[^a-z0-9-]/', '', strtolower(txt($s['ic'] ?? 'bag', 20))) ?: 'bag'];
        foreach (['dev' => 40, 'need' => 60, 'p' => 60] as $f => $m) if (plain($s[$f] ?? '', $m) !== '') $x[$f] = plain($s[$f], $m);
        if (media_url($s['ph'] ?? '') !== '') $x['ph'] = media_url($s['ph']);
        $o[] = $x;
      }
      return $o;
    case 'prix':
      $nombres = function ($a, int $prof) use (&$nombres) {
        if ($prof > 5 || !is_array($a)) return null;
        $o = [];
        foreach (array_slice($a, 0, 80, true) as $k => $x) {
          $k = txt($k, 30);
          if (is_array($x)) { $y = $nombres($x, $prof + 1); if ($y !== null) $o[$k] = $y; }
          elseif (is_numeric($x)) $o[$k] = round(max(0, min(100000, (float)$x)), 2);
        }
        return $o;
      };
      return $nombres($v, 0);
    case 'news':
      $o = ['add' => [], 'edit' => [], 'hide' => []];
      foreach (array_slice($v['add'] ?? [], 0, 400) as $n) { $x = actu($n, true); if ($x) $o['add'][] = $x; }
      foreach (array_slice($v['edit'] ?? [], 0, 400, true) as $id => $n) { $x = actu($n, false); if ($x) $o['edit'][txt($id, 160)] = $x; }
      foreach (array_slice($v['hide'] ?? [], 0, 400) as $id) if (is_string($id) && $id !== '') $o['hide'][] = txt($id, 160);
      return $o;
    case 'arts':
      $o = ['add' => [], 'edit' => [], 'hide' => []];
      foreach (array_slice($v['add'] ?? [], 0, 300) as $a) { $x = article($a, true); if ($x) $o['add'][] = $x; }
      foreach (array_slice($v['edit'] ?? [], 0, 300, true) as $s => $a) { $x = article($a, false); if ($x) $o['edit'][txt($s, 120)] = $x; }
      foreach (array_slice($v['hide'] ?? [], 0, 300) as $s) if (is_string($s) && $s !== '') $o['hide'][] = txt($s, 120);
      return $o;
  }
  fail('Partie inconnue.');
}

function actu($n, bool $complet): ?array {
  if (!is_array($n)) return null;
  $x = [];
  foreach (['t' => 220, 's' => 1500, 'tip' => 600, 'tag' => 40, 'dl' => 40] as $f => $m) if (array_key_exists($f, $n)) $x[$f] = plain($n[$f], $m);
  if (isset($n['sc'])) $x['sc'] = in_array($n['sc'], ['France', 'Monde'], true) ? $n['sc'] : 'France';
  if (isset($n['th'])) $x['th'] = in_array($n['th'], ['Sécurité', 'Smartphones', 'Ordinateurs', 'IA', 'Internet', 'Jeux'], true) ? $n['th'] : 'Ordinateurs';
  if (isset($n['d'])) $x['d'] = date_ok($n['d']);
  if (isset($n['pub'])) $x['pub'] = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string)$n['pub']) ? (string)$n['pub'] : date('Y-m-d\TH:i');
  if (isset($n['src'])) $x['src'] = sources($n['src']);
  if (isset($n['urg'])) $x['urg'] = $n['urg'] ? 1 : 0;
  if (isset($n['ph'])) $x['ph'] = media_url($n['ph']);
  if ($complet) {
    if (($x['t'] ?? '') === '' || ($x['s'] ?? '') === '') return null;
    $x += ['sc' => 'France', 'th' => 'Ordinateurs', 'tag' => 'Actualité', 'd' => date('Y-m-d'), 'pub' => date('Y-m-d\TH:i'), 'src' => [], 'tip' => ''];
    if (($x['dl'] ?? '') === '') $x['dl'] = $x['d'];
  }
  return $x ?: null;
}

function article($a, bool $complet): ?array {
  if (!is_array($a)) return null;
  $x = [];
  foreach (['t' => 160, 'd' => 300, 'cat' => 40, 'dl' => 40] as $f => $m) if (array_key_exists($f, $a)) $x[$f] = plain($a[$f], $m);
  if (isset($a['b'])) $x['b'] = propre_html($a['b'], 80000, true);
  if (isset($a['s'])) $x['s'] = trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower(txt($a['s'], 90))) ?? '', '-');
  if (isset($a['ic'])) $x['ic'] = preg_replace('/[^a-z0-9-]/', '', strtolower(txt($a['ic'], 20))) ?: 'shield';
  if (isset($a['min'])) $x['min'] = max(1, min(60, (int)$a['min']));
  if (isset($a['date'])) $x['date'] = date_ok($a['date']);
  if (isset($a['src'])) $x['src'] = sources($a['src']);
  if (isset($a['svc']) && is_array($a['svc'])) $x['svc'] = [plain($a['svc'][0] ?? 'Demander un devis', 120), ($a['svc'][1] ?? '') === 'boutique' ? 'boutique' : 'services', plain($a['svc'][2] ?? 'Ordinateurs', 40)];
  if (isset($a['ph'])) $x['ph'] = media_url($a['ph']);
  if (isset($a['ill'])) $x['ill'] = preg_replace('/[^a-z0-9-]/', '', strtolower(txt($a['ill'], 80)));
  if ($complet) {
    if (($x['t'] ?? '') === '' || ($x['b'] ?? '') === '' || ($x['s'] ?? '') === '') return null;
    $x += ['d' => '', 'cat' => 'Conseils', 'ic' => 'shield', 'min' => 3, 'date' => date('Y-m-d'), 'src' => [], 'svc' => ['Demander un devis gratuit', 'services', 'Ordinateurs']];
    if (($x['dl'] ?? '') === '') $x['dl'] = $x['date'];
  }
  return $x ?: null;
}

/* ---------- Lecture publique ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['m'])) {
  $f = (string)$_GET['m'];
  $p = medias_dir() . '/' . $f;
  if (!preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|gif)$/', $f) || !is_file($p)) { http_response_code(404); exit('Image introuvable.'); }
  header('Content-Type: ' . array_search(pathinfo($f, PATHINFO_EXTENSION), MEDIA_TYPES, true));
  header('Content-Length: ' . filesize($p));
  header('Cache-Control: public, max-age=31536000, immutable');
  header('X-Content-Type-Options: nosniff');
  readfile($p);
  exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['c'])) {
  try { $c = site_contenu(); } catch (Throwable $e) { $c = ['t' => 0]; }
  $j = json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $etag = '"' . substr(sha1($j), 0, 20) . '"';
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-cache');
  header('ETag: ' . $etag);
  header('X-Robots-Tag: noindex');
  header('X-Content-Type-Options: nosniff');
  header('Access-Control-Allow-Origin: *');
  if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
  echo $j;
  exit;
}

/* ---------- Modifications (atelier connecté) ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Méthode non autorisée.', 405);
if (($_SERVER['HTTP_X_GT'] ?? '') !== '1') fail('Demande refusée.', 403);
if (!https_ok()) fail('Ouvrez la page en https://', 403);
if (!connecte('gtadm')) fail('Connectez-vous d’abord à la gestion.', 401);

if (isset($_POST['data'])) { $d = json_decode((string)$_POST['data'], true) ?: []; }
else {
  $raw = file_get_contents('php://input') ?: '';
  if (strlen($raw) > 4000000) fail('Demande trop longue.', 413);
  $d = json_decode($raw, true) ?: [];
}
$action = txt($d['action'] ?? '', 20);

switch ($action) {
  case 'tout':
    out(['ok' => true, 'contenu' => site_contenu()]);

  case 'poser':  /* remplace une partie entière (null = revenir à la version d'origine du site) */
    $cle = txt($d['cle'] ?? '', 20);
    if (!in_array($cle, SITE_CLES, true)) fail('Partie inconnue.');
    site_poser($cle, valider($cle, $d['valeur'] ?? null));
    out(['ok' => true, 'contenu' => site_contenu()]);

  case 'textes':  /* modifie seulement les textes ou images envoyés (une valeur vide = texte d'origine) */
  case 'images':
    $c = site_contenu()[$action] ?? [];
    foreach ((array)($d['changes'] ?? []) as $k => $v) {
      $k = txt($k, $action === 'textes' ? 120 : 200);
      if ($k === '') continue;
      if ($v === null || $v === '') unset($c[$k]); else $c[$k] = $v;
    }
    site_poser($action, $c ? valider($action, $c) : null);
    out(['ok' => true, 'contenu' => site_contenu()]);

  case 'media':  /* une photo : enregistrée sous un nom unique, renvoie son adresse */
    $f = $_FILES['f'] ?? null;
    if (!$f || is_array($f['name']) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) fail('La photo n’a pas pu être envoyée (trop lourde ?).', 413);
    if ((int)$f['size'] > 15 * 1048576) fail('Photo trop lourde (15 Mo au maximum).', 413);
    $mime = class_exists('finfo') ? (string)(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : (string)$f['type'];
    if (!isset(MEDIA_TYPES[$mime])) fail('Format refusé : JPEG, PNG, WebP ou GIF seulement.', 415);
    $dir = medias_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) fail('Dossier des photos impossible à créer.', 500);
    $nom = bin2hex(random_bytes(12)) . '.' . MEDIA_TYPES[$mime];
    if (!move_uploaded_file($f['tmp_name'], "$dir/$nom") && !rename($f['tmp_name'], "$dir/$nom")) fail('Photo impossible à enregistrer.', 500);
    @chmod("$dir/$nom", 0644);
    out(['ok' => true, 'url' => '/api/site.php?m=' . $nom]);
}
fail('Action inconnue.');
