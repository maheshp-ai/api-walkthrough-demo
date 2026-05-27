<?php
// ─── ATOM API Configuration ───────────────────────────────────────────────
// Token is stored in atom_token.txt (auto-created). Update via the dashboard UI.
define('ATOM_TOKEN_FILE', __DIR__ . '/atom_token.txt');
define('ATOM_API_URL',    'https://atom.netcorecloud.com/api/projects');

function getAtomToken(): string {
    if (file_exists(ATOM_TOKEN_FILE)) {
        $t = trim(file_get_contents(ATOM_TOKEN_FILE));
        if ($t !== '') return $t;
    }
    // Fallback: hardcoded token (update atom_token.txt via dashboard to override)
    return 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpZCI6Im1pMndpY3phMWZwbzl3IiwiZW1haWwiOiJmYXJ1cS5rYXppQG5ldGNvcmVjbG91ZC5jb20iLCJuYW1lIjoiRmFydXEiLCJyb2xlIjoiYWRtaW4iLCJpYXQiOjE3Nzc5NTI0NzEsImV4cCI6MTc3ODU1NzI3MX0.coS4dsZuOEt38mGBd6NGwvwFdGc5ZFrRu_-yav_kbWA';
}

// ─── Update ATOM Token ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_token') {
    header('Content-Type: application/json');
    $newToken = trim($_POST['token'] ?? '');
    if (!$newToken) {
        echo json_encode(['success'=>false,'error'=>'Token cannot be empty.']); exit;
    }
    // Basic JWT format check: 3 base64url segments separated by dots
    if (substr_count($newToken, '.') !== 2) {
        echo json_encode(['success'=>false,'error'=>'Invalid JWT format — expected 3 segments separated by dots.']); exit;
    }
    // Try to decode and check expiry
    $parts   = explode('.', $newToken);
    $payload = json_decode(base64_decode(str_replace(['-','_'], ['+','/'], $parts[1])), true);
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        echo json_encode(['success'=>false,'error'=>'This token is already expired (exp: '.date('d M Y H:i', $payload['exp']).'). Please get a fresh token from atom.netcorecloud.com.']); exit;
    }
    $ok = file_put_contents(ATOM_TOKEN_FILE, $newToken);
    if ($ok === false) {
        echo json_encode(['success'=>false,'error'=>'Could not write atom_token.txt — check file permissions on '.dirname(ATOM_TOKEN_FILE).'.']); exit;
    }
    $expiry = isset($payload['exp']) ? date('d M Y H:i', $payload['exp']) : 'unknown';
    $name   = $payload['name'] ?? ($payload['email'] ?? 'unknown');
    echo json_encode(['success'=>true,'expiry'=>$expiry,'name'=>$name]); exit;
}

// ─── ATOM API Proxy ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_to_atom') {
    header('Content-Type: application/json');
    $payload = json_decode($_POST['payload'] ?? '', true);

    if (!$payload) {
        echo json_encode(['success'=>false,'error'=>'Invalid payload JSON.']); exit;
    }
    if (!function_exists('curl_init')) {
        echo json_encode(['success'=>false,'error'=>'cURL not available on this server.']); exit;
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => ATOM_API_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . getAtomToken(),
            'Origin: https://atom.netcorecloud.com',
        ],
    ]);

    $response  = curl_exec($curl);
    $httpCode  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($curlError) {
        echo json_encode(['success'=>false,'error'=>'Network error: '.$curlError,'httpCode'=>0]); exit;
    }
    $parsed = json_decode($response, true);
    if ($parsed === null) {
        echo json_encode(['success'=>false,'error'=>'Non-JSON response from ATOM','raw'=>substr($response,0,500),'httpCode'=>$httpCode]); exit;
    }
    $parsed['_httpCode'] = $httpCode;
    echo json_encode($parsed);
    exit;
}

// ─── Save ATOM IDs back to BRD JSON ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_atom_ids') {
    header('Content-Type: application/json');
    $brdId         = trim($_POST['brdId']         ?? '');
    $atomMongoId   = trim($_POST['atomMongoId']   ?? '');  // _id
    $atomProjectId = trim($_POST['atomProjectId'] ?? '');  // id
    $exportedAt    = date('c');

    if (!$brdId) { echo json_encode(['success'=>false,'error'=>'brdId missing']); exit; }

    $brdDirLocal = __DIR__ . '/brd_drafts/';
    $files = glob($brdDirLocal . 'brd_*.json') ?: [];
    $saved = false;
    foreach ($files as $file) {
        $content = json_decode(file_get_contents($file), true);
        if (($content['id'] ?? '') === $brdId) {
            $content['atomId']          = $atomMongoId;
            $content['atomProjectId']   = $atomProjectId;
            $content['atomExportedAt']  = $exportedAt;
            $ok = file_put_contents($file, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $saved = $ok !== false;
            break;
        }
    }
    echo json_encode($saved
        ? ['success'=>true,'exportedAt'=>$exportedAt]
        : ['success'=>false,'error'=>'BRD file not found for id: '.$brdId]);
    exit;
}

$brdDir      = __DIR__ . '/brd_drafts/';
$brds        = [];
$totalFiles  = 0;
$parseErrors = [];

if (is_dir($brdDir)) {
    $files      = glob($brdDir . 'brd_*.json') ?: [];
    $totalFiles = count($files);
    foreach ($files as $file) {
        $raw = file_get_contents($file);
        $obj = json_decode($raw, true);
        if ($obj !== null) {
            $brds[] = $obj;
        } else {
            $parseErrors[] = basename($file);
        }
    }
}

usort($brds, fn($a, $b) => strcmp($b['updatedAt'] ?? '', $a['updatedAt'] ?? ''));

// Derive filter lists from actual data
$verticals  = array_unique(array_filter(array_column($brds, 'vertical')));
$ams        = array_unique(array_filter(array_map(fn($b) => $b['data']['am']  ?? '', $brds)));
$dms        = array_unique(array_filter(array_map(fn($b) => $b['data']['dm']  ?? '', $brds)));
$dss        = array_unique(array_filter(array_map(fn($b) => $b['data']['ds']  ?? '', $brds)));
$psss       = array_unique(array_filter(array_map(fn($b) => $b['data']['pss'] ?? '', $brds)));
$allChans   = [];
$allProds   = [];
foreach ($brds as $b) {
    foreach (($b['data']['channels'] ?? []) as $c) $allChans[] = $c;
    foreach (($b['data']['products'] ?? []) as $p) $allProds[] = $p;
}
$allChans = array_unique($allChans);
$allProds = array_unique($allProds);
sort($verticals); sort($ams); sort($dms); sort($dss); sort($psss); sort($allChans); sort($allProds);

$totalBilling = array_sum(array_map(fn($b) => (int)($b['data']['payFlat'] ?? 0), $brds));

$jsonBrds = json_encode($brds, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

// Token metadata for the frontend
$_tok     = getAtomToken();
$_tokParts = explode('.', $_tok);
$_tokPayload = (count($_tokParts) === 3)
    ? json_decode(base64_decode(str_replace(['-','_'],['+','/'], $_tokParts[1])), true)
    : [];
$_tokExpiry  = isset($_tokPayload['exp']) ? $_tokPayload['exp'] : 0;
$_tokName    = $_tokPayload['name'] ?? ($_tokPayload['email'] ?? '');
$_tokExpired = $_tokExpiry > 0 && $_tokExpiry < time();
$_tokExpiryFmt = $_tokExpiry > 0 ? date('d M Y H:i', $_tokExpiry) : 'unknown';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BRD Dashboard — Netcore</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  /* Netcore brand */
  --nc-orange:     #F04E23;
  --nc-orange-dk:  #C73D18;
  --nc-orange-lt:  #FEF0EB;
  --nc-orange-md:  #FDDDD4;
  --nc-navy:       #0D0C2B;
  --nc-navy-2:     #1A1840;
  --nc-navy-3:     #2D2B55;

  /* surfaces */
  --bg:        #F2F2F7;
  --surface:   #FFFFFF;
  --border:    rgba(13,12,43,0.10);
  --border-md: rgba(13,12,43,0.20);

  /* text */
  --text:      #0D0C2B;
  --muted:     #5E5C7A;
  --faint:     #9896B0;

  /* accent = orange */
  --accent:    #F04E23;
  --accent-dk: #C73D18;
  --accent-bg: #FEF0EB;

  /* semantic badge palette */
  --blue:      #1558D6;  --blue-bg:   #EBF1FD;
  --teal:      #0A7160;  --teal-bg:   #E0F5F1;
  --amber:     #925000;  --amber-bg:  #FEF3E0;
  --coral:     #C0360C;  --coral-bg:  #FDEEE8;
  --green:     #1F6B2A;  --green-bg:  #E8F8EC;
  --pink:      #8B1C6B;  --pink-bg:   #FBEBF7;
  --purple:    #5B21B6;  --purple-bg: #F0EBFD;
  --gray:      #3D3B56;  --gray-bg:   #EDEDF3;

  --radius:    8px;
  --radius-lg: 12px;
}

body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); font-size: 14px; min-height: 100vh; }

/* ── Top brand bar ── */
.brand-bar { background: var(--nc-navy); padding: 0 1.5rem; display: flex; align-items: center; gap: 12px; height: 52px; border-bottom: 1px solid rgba(255,255,255,0.06); }
.brand-bar .logo-mark { display: flex; align-items: center; gap: 9px; text-decoration: none; }
.brand-bar .logo-sq { width: 30px; height: 30px; background: var(--nc-orange); border-radius: 7px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(240,78,35,0.4); }
.brand-bar .logo-sq svg { width: 16px; height: 16px; fill: white; }
.brand-bar .logo-name { color: #FFFFFF; font-weight: 700; font-size: 15px; letter-spacing: -0.3px; }
.brand-bar .logo-sep { color: rgba(255,255,255,0.2); margin: 0 2px; }
.brand-bar .logo-sub { color: rgba(255,255,255,0.45); font-size: 13px; font-weight: 400; }
.brand-bar .bar-right { margin-left: auto; display: flex; align-items: center; gap: 16px; }
.brand-bar .bar-tag { font-size: 11px; font-weight: 600; background: rgba(240,78,35,0.2); color: #FF9070; border: 0.5px solid rgba(240,78,35,0.3); padding: 3px 10px; border-radius: 20px; letter-spacing: 0.05em; text-transform: uppercase; }

.page { max-width: 1320px; margin: 0 auto; padding: 1.75rem 1.5rem; }

/* ── Header ── */
.header { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 12px; }
.header h1 { font-size: 21px; font-weight: 700; letter-spacing: -0.5px; color: var(--nc-navy); }
.header .sub { font-size: 13px; color: var(--muted); margin-top: 3px; }
.updated { font-size: 11px; color: var(--faint); font-family: 'JetBrains Mono', monospace; background: var(--surface); border: 0.5px solid var(--border); padding: 5px 10px; border-radius: var(--radius); }

/* ── Metrics ── */
.metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 1.5rem; }
.metric { background: var(--surface); border: 0.5px solid var(--border); border-radius: var(--radius-lg); padding: 1.1rem 1.4rem; border-top: 3px solid var(--nc-orange); transition: box-shadow 0.15s; }
.metric:hover { box-shadow: 0 4px 16px rgba(13,12,43,0.07); }
.metric .lbl { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--faint); margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
.metric .val { font-size: 30px; font-weight: 700; letter-spacing: -0.8px; color: var(--nc-navy); line-height: 1; }
.metric .val-sub { font-size: 12px; color: var(--muted); margin-top: 5px; font-weight: 400; }

/* ── Controls ── */
.controls { background: var(--surface); border: 0.5px solid var(--border); border-radius: var(--radius-lg); padding: 12px 16px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; }
.search-wrap { position: relative; flex: 1; min-width: 220px; }
.search-wrap svg { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--faint); pointer-events: none; }
.search-wrap input { width: 100%; height: 36px; padding: 0 10px 0 34px; border: 0.5px solid var(--border-md); border-radius: var(--radius); font-family: inherit; font-size: 13px; background: var(--bg); color: var(--text); outline: none; }
.search-wrap input:focus { border-color: var(--nc-orange); box-shadow: 0 0 0 2px rgba(240,78,35,0.12); }
.sep { width: 0.5px; height: 24px; background: var(--border); }
.filter-group { display: flex; gap: 7px; flex-wrap: wrap; align-items: center; }
.filter-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--faint); }
select.pill { height: 32px; padding: 0 26px 0 10px; border: 0.5px solid var(--border-md); border-radius: 20px; font-family: inherit; font-size: 12px; font-weight: 500; background: var(--bg); color: var(--text); cursor: pointer; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239896B0' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 8px center; outline: none; transition: border-color 0.15s; }
select.pill:focus { border-color: var(--nc-orange); }
select.pill.active { background-color: var(--nc-orange-lt); border-color: var(--nc-orange); color: var(--nc-orange-dk); background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23C73D18' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E"); }
.clear-btn { font-size: 12px; color: var(--muted); background: none; border: 0.5px solid var(--border-md); cursor: pointer; padding: 5px 10px; border-radius: var(--radius); font-family: inherit; transition: all 0.15s; }
.clear-btn:hover { background: var(--nc-orange-lt); border-color: var(--nc-orange); color: var(--nc-orange-dk); }

/* ── Results bar ── */
.results-bar { display: flex; align-items: center; margin-bottom: 8px; }
.results-bar .count { font-size: 12px; color: var(--muted); }

/* ── Table ── */
.tbl-card { background: var(--surface); border: 0.5px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; }
.tbl-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
thead th { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.07em; color: var(--faint); padding: 10px 14px; text-align: left; background: var(--bg); border-bottom: 0.5px solid var(--border); white-space: nowrap; cursor: pointer; user-select: none; }
thead th:hover { color: var(--text); }
thead th .sort-icon { opacity: 0.35; margin-left: 3px; }
thead th.sorted { color: var(--nc-orange); }
thead th.sorted .sort-icon { opacity: 1; color: var(--nc-orange); }
tbody tr { cursor: pointer; border-bottom: 0.5px solid var(--border); transition: background 0.12s, box-shadow 0.12s; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover td { background: #FAFAF9; }
tbody tr:hover td:first-child { border-left: 3px solid var(--nc-orange); }
tbody tr.selected td { background: var(--nc-orange-lt); }
tbody tr.selected td:first-child { border-left: 3px solid var(--nc-orange); }
td { padding: 12px 14px; vertical-align: middle; }
.mono { font-family: 'JetBrains Mono', monospace; font-size: 11px; color: var(--muted); }
.client-name { font-weight: 600; color: var(--nc-navy); }

/* ── Badges ── */
.badge { display: inline-block; font-size: 11px; font-weight: 500; padding: 2px 8px; border-radius: 20px; margin: 2px 2px 2px 0; line-height: 1.6; }
.b-blue   { background: var(--blue-bg);   color: var(--blue); }
.b-teal   { background: var(--teal-bg);   color: var(--teal); }
.b-amber  { background: var(--amber-bg);  color: var(--amber); }
.b-coral  { background: var(--coral-bg);  color: var(--coral); }
.b-green  { background: var(--green-bg);  color: var(--green); }
.b-pink   { background: var(--pink-bg);   color: var(--pink); }
.b-purple { background: var(--purple-bg); color: var(--purple); }
.b-gray   { background: var(--gray-bg);   color: var(--gray); }
.b-orange { background: var(--nc-orange-lt); color: var(--nc-orange-dk); }

.status-dot { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: var(--muted); }
.dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.dot-done { background: #1D9E75; }
.dot-prog { background: var(--nc-orange); }
.bill-val { font-family: 'JetBrains Mono', monospace; font-size: 12px; font-weight: 500; color: var(--nc-navy); }

/* ── Modal ── */
.modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(13,12,43,0.65); z-index: 100; overflow-y: auto; padding: 12px 16px; backdrop-filter: blur(3px); }
.modal-backdrop.open { display: flex; align-items: flex-start; justify-content: center; }
.modal { background: var(--surface); border-radius: 14px; width: 100%; max-width: min(1360px,96vw); box-shadow: 0 24px 80px rgba(13,12,43,0.35), 0 0 0 0.5px rgba(13,12,43,0.12); position: relative; overflow: hidden; margin: auto; }
.modal-header { display: flex; align-items: center; gap: 10px; padding: 0 24px; border-bottom: 1px solid rgba(255,255,255,0.08); background: linear-gradient(135deg, var(--nc-navy) 0%, var(--nc-navy-3) 100%); position: sticky; top: 0; z-index: 10; height: 60px; }
.modal-header .dtitle { font-size: 16px; font-weight: 700; color: #FFFFFF; letter-spacing: -0.3px; }
.modal-header .mono { color: rgba(255,255,255,0.35); font-size: 12px; }
.modal-header .dsub { font-size: 12px; color: rgba(255,255,255,0.4); margin-left: auto; white-space: nowrap; }
.modal-close { margin-left: 12px; background: rgba(255,255,255,0.08); border: 0.5px solid rgba(255,255,255,0.15); border-radius: var(--radius); padding: 6px 14px; font-size: 12px; cursor: pointer; color: rgba(255,255,255,0.7); font-family: inherit; flex-shrink: 0; transition: all 0.15s; }
.modal-close:hover { background: rgba(240,78,35,0.3); border-color: var(--nc-orange); color: white; }
.detail-body { display: grid; grid-template-columns: repeat(3, 1fr); }
.detail-section { padding: 20px 24px; border-right: 0.5px solid var(--border); }
.detail-section:last-child { border-right: none; padding-right: 24px; }
.detail-section h4 { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--nc-orange); margin-bottom: 10px; }
.drow { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; padding: 5px 0; border-bottom: 0.5px solid var(--border); font-size: 12px; }
.drow:last-child { border-bottom: none; }
.dk { color: var(--muted); flex-shrink: 0; }
.dv { text-align: right; font-weight: 500; max-width: 160px; word-break: break-word; font-size: 12px; }
.detail-lower { padding: 20px 24px; border-top: 0.5px solid var(--border); display: grid; grid-template-columns: 1fr 1fr; gap: 20px; background: var(--bg); }
.detail-lower h4 { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--nc-orange); margin-bottom: 12px; }
.uc-item { background: var(--surface); border: 0.5px solid var(--border); border-left: 3px solid var(--nc-orange); border-radius: var(--radius); padding: 10px 12px; margin-bottom: 8px; }
.uc-name { font-weight: 600; font-size: 13px; margin-bottom: 3px; color: var(--nc-navy); }
.uc-meta { font-size: 11px; color: var(--muted); line-height: 1.5; }
.events-wrap { display: flex; flex-wrap: wrap; gap: 6px; }
.empty-state { text-align: center; padding: 3rem; color: var(--muted); font-size: 13px; }

/* ── Add to ATOM ── */
.atom-btn { display: inline-flex; align-items: center; gap: 6px; background: var(--nc-orange); color: white; border: none; border-radius: var(--radius); padding: 6px 14px; font-size: 12px; font-weight: 600; cursor: pointer; font-family: inherit; flex-shrink: 0; transition: background 0.15s; letter-spacing: 0.01em; }
.atom-btn:hover { background: var(--nc-orange-dk); }
.atom-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.atom-btn svg { width: 13px; height: 13px; }

#new-token-input:focus { border-color: var(--nc-orange); box-shadow: 0 0 0 2px rgba(240,78,35,0.12); }
.atom-exported-tag { display: inline-flex; align-items: center; gap: 5px; background: var(--green-bg); color: var(--green); border: 0.5px solid #BBF7D0; border-radius: var(--radius); padding: 5px 12px; font-size: 12px; font-weight: 600; flex-shrink: 0; }

.atom-panel { border-top: 0.5px solid var(--border); }
.atom-tabs { display: flex; border-bottom: 0.5px solid var(--border); background: var(--bg); }
.atom-tab { padding: 10px 18px; font-size: 12px; font-weight: 500; color: var(--muted); cursor: pointer; border-bottom: 2px solid transparent; background: none; border-top: none; border-left: none; border-right: none; font-family: inherit; }
.atom-tab.active { color: var(--nc-orange); border-bottom-color: var(--nc-orange); }
.atom-tab-active-label { padding: 10px 18px; font-size: 12px; font-weight: 600; color: var(--nc-orange); border-bottom: 2px solid var(--nc-orange); display: inline-block; }
.atom-tab-body { padding: 16px 24px; }

.payload-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
.payload-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; padding: 5px 0; border-bottom: 0.5px solid var(--border); font-size: 12px; }
.payload-row:last-child { border-bottom: none; }
.payload-key { color: var(--muted); font-family: 'JetBrains Mono', monospace; font-size: 11px; flex-shrink: 0; }
.payload-val { font-weight: 500; text-align: right; word-break: break-all; max-width: 200px; }
.payload-val.empty { color: var(--faint); font-style: italic; }
.payload-val.warn  { color: #925000; }
.payload-col { padding: 0 20px 0 0; }
.payload-col:last-child { padding-right: 0; border-left: 0.5px solid var(--border); padding-left: 20px; }
.payload-section-title { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--nc-orange); margin: 0 0 8px; }

.atom-confirm-bar { display: flex; align-items: center; justify-content: space-between; padding: 14px 24px; border-top: 0.5px solid var(--border); background: var(--bg); gap: 12px; }
.atom-warn-count { font-size: 12px; color: #925000; }
.atom-actions { display: flex; gap: 8px; }
.atom-send-btn { background: var(--nc-orange); color: white; border: none; border-radius: var(--radius); padding: 7px 18px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; transition: background 0.15s; }
.atom-send-btn:hover { background: var(--nc-orange-dk); }
.atom-send-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.atom-cancel-btn { background: none; border: 0.5px solid var(--border-md); border-radius: var(--radius); padding: 7px 14px; font-size: 13px; color: var(--muted); cursor: pointer; font-family: inherit; }
.atom-cancel-btn:hover { background: var(--surface); }

.atom-result { padding: 24px; }
.atom-result-success { background: #E8F8EC; border-radius: var(--radius); padding: 16px 20px; margin-bottom: 12px; }
.atom-result-success h4 { color: #1F6B2A; font-size: 15px; font-weight: 700; margin-bottom: 6px; }
.atom-result-success p { color: #2A5E12; font-size: 13px; }
.atom-result-error { background: #FDEEE8; border-radius: var(--radius); padding: 16px 20px; margin-bottom: 12px; }
.atom-result-error h4 { color: #C0360C; font-size: 15px; font-weight: 700; margin-bottom: 6px; }
.atom-result-error p { color: var(--coral); font-size: 13px; }
.atom-result-code { background: #0A0920; color: #7EFFA0; border: 0.5px solid rgba(255,255,255,0.08); border-radius: var(--radius); padding: 12px 16px; font-family: 'JetBrains Mono', monospace; font-size: 11px; overflow-x: auto; white-space: pre-wrap; word-break: break-word; margin-top: 10px; max-height: 220px; overflow-y: auto; }
.atom-loading { display: flex; align-items: center; gap: 10px; padding: 24px; font-size: 13px; color: var(--muted); }
.spinner { width: 18px; height: 18px; border: 2px solid var(--border); border-top-color: var(--nc-orange); border-radius: 50%; animation: spin 0.7s linear infinite; flex-shrink: 0; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Dashboard tabs ── */
.dash-tabs { display: flex; gap: 0; border-bottom: 0.5px solid var(--border); margin-bottom: 1rem; }
.dash-tab { padding: 10px 20px; font-size: 13px; font-weight: 500; color: var(--muted); cursor: pointer; border: none; background: none; border-bottom: 2px solid transparent; font-family: inherit; display: flex; align-items: center; gap: 7px; transition: color 0.15s; }
.dash-tab:hover { color: var(--text); }
.dash-tab.active { color: var(--nc-orange); border-bottom-color: var(--nc-orange); }
.dash-tab .tab-count { background: var(--gray-bg); color: var(--gray); font-size: 11px; font-weight: 600; padding: 1px 7px; border-radius: 20px; }
.dash-tab.active .tab-count { background: var(--nc-orange-lt); color: var(--nc-orange-dk); }

/* ── Editable payload form ── */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
.form-col { padding: 0 24px 0 0; }
.form-col:last-child { border-left: 0.5px solid var(--border); padding: 0 0 0 24px; }
.form-section-title { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--nc-orange); margin-bottom: 10px; }
.form-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 5px 0; border-bottom: 0.5px solid var(--border); min-height: 32px; }
.form-row:last-child { border-bottom: none; }
.form-row label { font-size: 11px; font-family: 'JetBrains Mono', monospace; color: var(--muted); flex-shrink: 0; max-width: 160px; }
.form-val-ro { font-size: 12px; font-weight: 500; text-align: right; max-width: 200px; word-break: break-word; }
.form-val-ro.empty { color: var(--faint); font-style: italic; }
.form-select { height: 28px; font-size: 12px; font-weight: 500; border: 0.5px solid var(--border-md); border-radius: 6px; padding: 0 22px 0 8px; background: var(--bg); color: var(--text); font-family: inherit; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%239896B0' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 6px center; cursor: pointer; outline: none; max-width: 180px; }
.form-select:focus { border-color: var(--nc-orange); }
.form-input { height: 28px; font-size: 12px; font-weight: 500; border: 0.5px solid var(--border-md); border-radius: 6px; padding: 0 8px; background: var(--bg); color: var(--text); font-family: inherit; outline: none; width: 140px; }
.form-input:focus { border-color: var(--nc-orange); }
.form-input-custom { height: 28px; font-size: 12px; border: 0.5px solid var(--nc-orange); border-radius: 6px; padding: 0 8px; background: var(--bg); color: var(--text); font-family: inherit; outline: none; width: 140px; margin-top: 4px; }
.sub-badges { display: flex; flex-wrap: wrap; gap: 4px; justify-content: flex-end; max-width: 220px; }
.dur-section { margin-bottom: 12px; }
.dur-group-title { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); margin: 10px 0 4px; padding-top: 8px; border-top: 0.5px solid var(--border); }
.dur-row { display: flex; align-items: center; justify-content: space-between; padding: 4px 0; border-bottom: 0.5px solid var(--border); min-height: 30px; }
.dur-row:last-child { border-bottom: none; }
.dur-row label { font-size: 11px; color: var(--muted); font-family: 'JetBrains Mono', monospace; }
.dur-input { width: 72px; height: 26px; font-size: 12px; font-weight: 500; border: 0.5px solid var(--border-md); border-radius: 6px; padding: 0 8px; background: var(--bg); color: var(--text); font-family: 'JetBrains Mono', monospace; outline: none; text-align: right; }
.dur-input:focus { border-color: var(--nc-orange); }
.warn-list { padding: 10px 24px; background: #FFFBEB; border-top: 0.5px solid #FDE68A; }
.warn-list ul { list-style: none; display: flex; flex-wrap: wrap; gap: 6px; }
.warn-list li { font-size: 11px; background: #FEF3C7; color: #92400E; padding: 2px 10px; border-radius: 20px; border: 0.5px solid #FDE68A; }
.warn-list.ok { background: #F0FDF4; border-color: #BBF7D0; }
.warn-list.ok li { background: #DCFCE7; color: #14532D; border-color: #BBF7D0; }


@media (max-width: 900px) {
  .metrics { grid-template-columns: repeat(2, 1fr); }
  .detail-body { grid-template-columns: 1fr; }
  .detail-section { border-right: none; border-bottom: 0.5px solid var(--border); }
  .detail-lower { grid-template-columns: 1fr; }
  .modal-header .dsub { display: none; }
  .brand-bar .logo-sub { display: none; }
}
</style>
</head>
<body>

<nav class="brand-bar">
  <div class="logo-mark">
    <div class="logo-sq">
      <svg viewBox="0 0 20 20"><path d="M3 3h6v6H3zm8 0h6v6h-6zm-8 8h6v6H3zm8 3.5a2.5 2.5 0 015 0 2.5 2.5 0 01-5 0z"/></svg>
    </div>
    <span class="logo-name">Netcore</span>
    <span class="logo-sep">/</span>
    <span class="logo-sub">BRD Dashboard</span>
  </div>
  <div class="bar-right">
    <span class="bar-tag">Internal</span>
  </div>
</nav>
<div class="page">

<?php if ($_tokExpired): ?>
  <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:var(--radius-lg);padding:12px 18px;margin-bottom:14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <span style="font-size:16px">🔑</span>
    <div style="flex:1;min-width:200px">
      <div style="font-size:13px;font-weight:700;color:#B91C1C">ATOM token expired — Push to ATOM will fail</div>
      <div style="font-size:12px;color:#EF4444;margin-top:2px">Expired: <?= htmlspecialchars($_tokExpiryFmt) ?> &nbsp;·&nbsp; <?= htmlspecialchars($_tokName) ?></div>
    </div>
    <button onclick="document.getElementById('token-update-panel').style.display='block';this.style.display='none'" style="padding:8px 16px;background:#DC2626;color:white;border:none;border-radius:var(--radius);font-size:13px;font-weight:600;cursor:pointer;font-family:inherit">Update Token</button>
  </div>
<?php else: ?>
  <div id="token-ok-bar" style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:var(--radius-lg);padding:10px 18px;margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <span style="font-size:13px">🔑</span>
    <div style="font-size:12px;color:#166534;flex:1">ATOM token valid &nbsp;·&nbsp; <?= htmlspecialchars($_tokName) ?> &nbsp;·&nbsp; expires <?= htmlspecialchars($_tokExpiryFmt) ?></div>
    <button onclick="document.getElementById('token-update-panel').style.display='block';document.getElementById('token-ok-bar').style.display='none'" style="padding:5px 12px;background:none;border:1px solid #16a34a;border-radius:var(--radius);font-size:12px;color:#15803d;cursor:pointer;font-family:inherit">Replace Token</button>
  </div>
<?php endif; ?>

<!-- Token update panel (hidden by default) -->
<div id="token-update-panel" style="display:none;background:var(--surface);border:1.5px solid var(--nc-orange);border-radius:var(--radius-lg);padding:20px 22px;margin-bottom:14px">
  <div style="font-size:14px;font-weight:700;color:var(--nc-navy);margin-bottom:4px">🔑 Update ATOM JWT Token</div>
  <div style="font-size:12px;color:var(--muted);margin-bottom:14px">Paste a fresh token from <strong>atom.netcorecloud.com</strong> → Login → open browser DevTools → Network tab → copy the <code>Authorization: Bearer …</code> value from any API request.</div>
  <textarea id="new-token-input" rows="3" placeholder="eyJhbGciOiJIUzI1NiIs…" style="width:100%;padding:10px 12px;border:1px solid var(--border-md);border-radius:var(--radius);font-family:'JetBrains Mono',monospace;font-size:11px;resize:vertical;outline:none;line-height:1.5"></textarea>
  <div style="display:flex;align-items:center;gap:10px;margin-top:10px;flex-wrap:wrap">
    <button onclick="saveAtomToken()" style="padding:9px 20px;background:var(--nc-orange);color:white;border:none;border-radius:var(--radius);font-size:13px;font-weight:700;cursor:pointer;font-family:inherit">Save Token</button>
    <button onclick="document.getElementById('token-update-panel').style.display='none'" style="padding:9px 14px;background:none;border:1px solid var(--border-md);border-radius:var(--radius);font-size:13px;color:var(--muted);cursor:pointer;font-family:inherit">Cancel</button>
    <span id="token-save-status" style="font-size:12px"></span>
  </div>
</div>

  <div class="header">
    <div>
      <h1>BRD Accounts</h1>
      <div class="sub">Business Requirements Documents &mdash; Netcore Onboarding</div>
    </div>
    <div class="updated">brd_drafts/ &mdash; <?= count($brds) ?>/<?= $totalFiles ?> files loaded<?php if ($parseErrors): ?> &nbsp;<span style="color:#B45309" title="Failed: <?= htmlspecialchars(implode(', ', $parseErrors)) ?>">&#9888; <?= count($parseErrors) ?> parse error<?= count($parseErrors)>1?'s':'' ?></span><?php endif; ?></div>
  </div>

  <?php
  function billingK(int $v): string {
    if ($v === 0) return '—';
    if ($v >= 10000000) return '₹' . round($v / 100000) . 'L';
    return '₹' . round($v / 1000) . 'K';
  }
  ?>

  <div class="metrics">
    <div class="metric">
      <div class="lbl">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
        Total Accounts
      </div>
      <div class="val"><?= count($brds) ?></div>
      <div class="val-sub"><?= $totalFiles ?> files in brd_drafts</div>
    </div>
    <div class="metric">
      <div class="lbl">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
        Total Billing
      </div>
      <div class="val"><?= billingK($totalBilling) ?></div>
      <div class="val-sub">Flat billing across all accounts</div>
    </div>
    <div class="metric">
      <div class="lbl">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        Complete
      </div>
      <div class="val"><?= count(array_filter($brds, fn($b) => ($b['step'] ?? 0) == 4)) ?></div>
      <div class="val-sub">Step 4 of 4</div>
    </div>
    <div class="metric">
      <div class="lbl">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        In Progress
      </div>
      <div class="val"><?= count(array_filter($brds, fn($b) => ($b['step'] ?? 0) < 4)) ?></div>
      <div class="val-sub">Awaiting completion</div>
    </div>
  </div>

  <div class="dash-tabs">
    <button class="dash-tab active" data-tab="all">All Accounts <span class="tab-count" id="tab-count-all">0</span></button>
    <button class="dash-tab" data-tab="exported">Exported to ATOM <span class="tab-count" id="tab-count-exported">0</span></button>
  </div>

  <div class="controls">
    <div class="search-wrap">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input id="search" type="text" placeholder="Search by client, BRD ID, AM, DM, DS, PSS…" autocomplete="off" />
    </div>
    <div class="sep"></div>
    <div class="filter-group">
      <span class="filter-label">Filter by</span>

      <select class="pill" id="f-vertical">
        <option value="">Vertical</option>
        <?php foreach ($verticals as $v): ?>
          <option value="<?= htmlspecialchars($v) ?>"><?= ucfirst(htmlspecialchars($v)) ?></option>
        <?php endforeach; ?>
      </select>

      <select class="pill" id="f-am">
        <option value="">AM</option>
        <?php foreach ($ams as $a): ?>
          <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
        <?php endforeach; ?>
      </select>

      <select class="pill" id="f-dm">
        <option value="">DM</option>
        <?php foreach ($dms as $a): ?>
          <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
        <?php endforeach; ?>
      </select>

      <select class="pill" id="f-ds">
        <option value="">DS</option>
        <?php foreach ($dss as $a): ?>
          <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
        <?php endforeach; ?>
      </select>

      <select class="pill" id="f-pss">
        <option value="">PSS</option>
        <?php foreach ($psss as $a): ?>
          <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
        <?php endforeach; ?>
      </select>

      <select class="pill" id="f-channel">
        <option value="">Channel</option>
        <?php
        $chanLabels = ['email'=>'Email','sms'=>'SMS','whatsapp'=>'WhatsApp','rcs'=>'RCS','webpush'=>'Web Push','apppush'=>'App Push'];
        foreach ($allChans as $c):
        ?>
          <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($chanLabels[$c] ?? ucfirst($c)) ?></option>
        <?php endforeach; ?>
      </select>

      <select class="pill" id="f-product">
        <option value="">Product</option>
        <?php
        $prodLabels = ['ce'=>'CE','px'=>'PX','cpaas'=>'CPaaS'];
        foreach ($allProds as $p):
        ?>
          <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($prodLabels[$p] ?? strtoupper($p)) ?></option>
        <?php endforeach; ?>
      </select>

      <button class="clear-btn" id="clear-filters">Clear all</button>
    </div>
  </div>

  <div class="results-bar">
    <span class="count" id="result-count"></span>
  </div>

  <div class="tbl-card">
    <div class="tbl-wrap">
      <table id="brd-table">
        <thead>
          <tr>
            <th data-col="id">BRD ID <span class="sort-icon">↕</span></th>
            <th data-col="clientName">Client <span class="sort-icon">↕</span></th>
            <th>Products</th>
            <th>Channels</th>
            <th data-col="am">AM</th>
            <th data-col="dm">DM</th>
            <th data-col="payFlat">Billing <span class="sort-icon">↕</span></th>
            <th data-col="startDate">Start Date <span class="sort-icon">↕</span></th>
          </tr>
        </thead>
        <tbody id="brd-body"></tbody>
      </table>
    </div>
  </div>

</div>

<!-- Modal -->
<div class="modal-backdrop" id="modal-backdrop">
  <div class="modal" id="modal-box">
    <div class="modal-header" id="modal-header"></div>
    <div id="modal-body"></div>
    <div id="atom-panel"></div>
  </div>
</div>

<script>
const BRDS = <?= $jsonBrds ?>;

const CHAN_COLOR = {email:'b-blue',sms:'b-teal',whatsapp:'b-green',rcs:'b-purple',webpush:'b-coral',apppush:'b-pink'};
const CHAN_LABEL = {email:'Email',sms:'SMS',whatsapp:'WhatsApp',rcs:'RCS',webpush:'Web Push',apppush:'App Push'};
const PROD_COLOR = {ce:'b-orange',px:'b-purple',cpaas:'b-amber'};
const PROD_LABEL = {ce:'CE',px:'PX',cpaas:'CPaaS'};
const VERT_COLOR = {ecommerce:'b-teal',travel:'b-blue',fintech:'b-amber',edtech:'b-green',bfsi:'b-purple'};

let sortCol = 'updatedAt', sortDir = -1, selectedId = null;

function billingK(v) {
  v = parseInt(v) || 0;
  if (!v) return '—';
  if (v >= 10000000) return '₹' + Math.round(v / 100000) + 'L';
  return '₹' + Math.round(v / 1000) + 'K';
}

function fmtDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'});
}

function badge(text, cls) { return `<span class="badge ${cls}">${text}</span>`; }

function getFiltered() {
  const q    = document.getElementById('search').value.toLowerCase().trim();
  const fv   = document.getElementById('f-vertical').value;
  const fam  = document.getElementById('f-am').value;
  const fdm  = document.getElementById('f-dm').value;
  const fds  = document.getElementById('f-ds').value;
  const fpss = document.getElementById('f-pss').value;
  const fch  = document.getElementById('f-channel').value;
  const fp   = document.getElementById('f-product').value;

  return BRDS.filter(b => {
    const d = b.data || {};
    if (q && !b.clientName?.toLowerCase().includes(q)
           && !b.id?.toLowerCase().includes(q)
           && !(d.am ||'').toLowerCase().includes(q)
           && !(d.dm ||'').toLowerCase().includes(q)
           && !(d.ds ||'').toLowerCase().includes(q)
           && !(d.pss||'').toLowerCase().includes(q)) return false;
    if (fv   && b.vertical      !== fv)   return false;
    if (fam  && (d.am  ||'')   !== fam)  return false;
    if (fdm  && (d.dm  ||'')   !== fdm)  return false;
    if (fds  && (d.ds  ||'')   !== fds)  return false;
    if (fpss && (d.pss ||'')   !== fpss) return false;
    if (fch  && !(d.channels||[]).includes(fch)) return false;
    if (fp   && !(d.products||[]).includes(fp))  return false;
    if (activeTab === 'exported' && !b.atomId) return false;
    if (activeTab === 'all') { /* show all */ }
    return true;
  }).sort((a, b) => {
    const da = a.data||{}, db = b.data||{};
    let av, bv;
    switch (sortCol) {
      case 'id':         av=a.id;                      bv=b.id;                     break;
      case 'clientName': av=a.clientName;               bv=b.clientName;             break;
      case 'vertical':   av=a.vertical;                 bv=b.vertical;               break;
      case 'step':       av=parseInt(a.step);           bv=parseInt(b.step);         break;
      case 'am':         av=da.am||'';                  bv=db.am||'';               break;
      case 'dm':         av=da.dm||'';                  bv=db.dm||'';               break;
      case 'payFlat':    av=parseInt(da.payFlat)||0;    bv=parseInt(db.payFlat)||0;  break;
      case 'startDate':  av=da.startDate||'';           bv=db.startDate||'';         break;
      case 'ucCount':    av=(da.useCases||[]).length;   bv=(db.useCases||[]).length; break;
      case 'evCount':    av=(da.events||[]).length;     bv=(db.events||[]).length;   break;
      default:           av=a.updatedAt;                bv=b.updatedAt;
    }
    return av < bv ? -sortDir : av > bv ? sortDir : 0;
  });
}

function renderTable() {
  const allCount      = BRDS.length;
  const exportedCount = BRDS.filter(b => b.atomId).length;
  document.getElementById('tab-count-all').textContent      = allCount;
  document.getElementById('tab-count-exported').textContent = exportedCount;

  // Show/hide Exported At column in thead
  const theadRow = document.querySelector('#brd-table thead tr');
  const existingExportTh = theadRow.querySelector('.th-exported-at');
  if (activeTab === 'exported') {
    if (!existingExportTh) {
      const th = document.createElement('th');
      th.className = 'th-exported-at';
      th.textContent = 'Exported At';
      theadRow.appendChild(th);
    }
  } else {
    if (existingExportTh) existingExportTh.remove();
  }

  const rows = getFiltered();
  document.getElementById('result-count').textContent =
    rows.length === BRDS.length ? `${BRDS.length} accounts` : `${rows.length} of ${BRDS.length} accounts`;

  const tbody = document.getElementById('brd-body');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="12"><div class="empty-state">No accounts match your filters</div></td></tr>`;
    return;
  }

  tbody.innerHTML = rows.map(b => {
    const d = b.data || {};
    const prods = (d.products||[]).map(p => badge(PROD_LABEL[p]||p.toUpperCase(), PROD_COLOR[p]||'b-gray')).join('');
    const chans = (d.channels||[]).map(c => badge(CHAN_LABEL[c]||c, CHAN_COLOR[c]||'b-gray')).join('');
    const stepEl = parseInt(b.step) >= 4
      ? `<span class="status-dot"><span class="dot dot-done"></span>${b.step}/4</span>`
      : `<span class="status-dot"><span class="dot dot-prog"></span>${b.step}/4</span>`;
    const atomBadge = b.atomId ? '<span class="badge b-green" title="ATOM: '+b.atomProjectId+'">In ATOM</span>' : '';
    const exportedAtCell = activeTab === 'exported'
      ? `<td style="color:var(--muted);font-size:12px;white-space:nowrap">${b.atomExportedAt ? fmtDate(b.atomExportedAt) : '—'}</td>`
      : '';
    return `<tr class="${b.id===selectedId?'selected':''}" data-id="${b.id}">
      <td><span class="mono">${b.id}</span></td>
      <td><span class="client-name">${b.clientName}</span> ${atomBadge}</td>
      <td>${prods}</td>
      <td>${chans}</td>
      <td style="color:var(--muted)">${d.am||'—'}</td>
      <td style="color:var(--muted)">${d.dm||'—'}</td>
      <td><span class="bill-val">${billingK(d.payFlat)}</span></td>
      <td style="color:var(--muted);font-size:12px">${fmtDate(d.startDate)}</td>
      ${exportedAtCell}
    </tr>`;
  }).join('');

  tbody.querySelectorAll('tr[data-id]').forEach(row => {
    row.addEventListener('click', () => {
      selectedId = row.dataset.id;
      renderTable();
      renderDetail();
    });
  });
}

function closeModal() {
  selectedId = null;
  document.getElementById('modal-backdrop').classList.remove('open');
  document.body.style.overflow = '';
  renderTable();
}

function renderDetail() {
  if (!selectedId) { closeModal(); return; }
  const b = BRDS.find(x => x.id === selectedId);
  if (!b) { closeModal(); return; }
  const d = b.data || {};

  const alreadyExported = !!b.atomId;
  const atomBtnHTML = alreadyExported
    ? `<span class="atom-exported-tag">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="13" height="13"><path d="M20 6L9 17l-5-5"/></svg>
        Exported to ATOM
       </span>`
    : `<button class="atom-btn" id="open-atom-btn" title="Push this BRD to ATOM">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12l7-7 7 7"/></svg>
        Add to ATOM
       </button>`;
  document.getElementById('modal-header').innerHTML = `
    <span class="mono">${b.id}</span>
    <span class="dtitle">${b.clientName}</span>
    ${badge((b.vertical.charAt(0).toUpperCase()+b.vertical.slice(1)), VERT_COLOR[b.vertical]||'b-gray')}
    <span class="dsub">Updated ${fmtDate(b.updatedAt)} &middot; Filled by ${b.filledBy}</span>
    ${atomBtnHTML}
    <button class="modal-close" id="close-modal">✕ Cancel</button>
  `;

  document.getElementById('modal-body').innerHTML = `
    <div class="detail-body">
      <div class="detail-section">
        <h4>Team</h4>
        <div class="drow"><span class="dk">AM</span><span class="dv">${d.am||'—'}</span></div>
        <div class="drow"><span class="dk">PSS</span><span class="dv">${d.pss||'—'}</span></div>
        <div class="drow"><span class="dk">DM</span><span class="dv">${d.dm||'—'}</span></div>
        <div class="drow"><span class="dk">DS</span><span class="dv">${d.ds||'—'}</span></div>
        <div class="drow"><span class="dk">Client contact</span><span class="dv">${(d.clientContact||{}).name||'—'}</span></div>
        <div class="drow"><span class="dk">Website</span><span class="dv" style="font-size:11px">${d.website||'—'}</span></div>
        <div class="drow"><span class="dk">Primary key</span><span class="dv">${d.primaryKey||'—'}</span></div>
      </div>
      <div class="detail-section">
        <h4>Integration</h4>
        <div class="drow"><span class="dk">Web</span><span class="dv">${d.webEnabled ? (d.webType||'Yes') : 'No'}</span></div>
        <div class="drow"><span class="dk">App SDK</span><span class="dv">${d.appEnabled ? (d.appSdkType||'Yes') : 'No'}</span></div>
        <div class="drow"><span class="dk">Web platform</span><span class="dv">${d.webPlatform||'—'}</span></div>
        <div class="drow"><span class="dk">Email warmup</span><span class="dv">${d.emailWarmup?'Yes':'No'}</span></div>
        <div class="drow"><span class="dk">WA type</span><span class="dv">${d.waType||'—'}</span></div>
        <div class="drow"><span class="dk">Data ingest</span><span class="dv" style="font-size:11px">${d.dataIngest||'—'}</span></div>
      </div>
      <div class="detail-section">
        <h4>Billing</h4>
        <div class="drow"><span class="dk">Amount</span><span class="dv" style="font-size:14px">${billingK(d.payFlat)}</span></div>
        <div class="drow"><span class="dk">Mode</span><span class="dv">${d.payMode||'—'}</span></div>
        <div class="drow"><span class="dk">Start date</span><span class="dv">${fmtDate(d.startDate)}</span></div>
        <div class="drow"><span class="dk">Billing date</span><span class="dv">${fmtDate(d.billingDate)}</span></div>
        <div class="drow"><span class="dk">Note</span><span class="dv" style="font-size:11px;color:var(--muted)">${d.billingNote||'—'}</span></div>
      </div>
    </div>
    <div class="detail-lower">
      <div>
        <h4 style="font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.07em;color:var(--faint);margin-bottom:10px">Use Cases (${(d.useCases||[]).length})</h4>
        ${(d.useCases||[]).length ? (d.useCases||[]).map(uc=>`
          <div class="uc-item">
            <div class="uc-name">${uc.name||'Unnamed'}</div>
            <div class="uc-meta">${uc.channel} &middot; Trigger: <strong>${uc.trigger}</strong> → Goal: <strong>${uc.goal}</strong>${uc.desc?`<br>${uc.desc}`:''}</div>
          </div>`).join('')
        : '<div style="color:var(--muted);font-size:13px">No use cases defined</div>'}
      </div>
      <div>
        <h4 style="font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.07em;color:var(--faint);margin-bottom:10px">Events (${(d.events||[]).length})</h4>
        ${(d.events||[]).length
          ? `<div class="events-wrap">${(d.events||[]).map(ev=>badge(ev.name,'b-gray')).join('')}</div>`
          : '<div style="color:var(--muted);font-size:13px">No events defined</div>'}
      </div>
    </div>
  `;

  const backdrop = document.getElementById('modal-backdrop');
  backdrop.classList.add('open');
  backdrop.scrollTop = 0;
  document.body.style.overflow = 'hidden';

  // Reset ATOM panel
  document.getElementById('atom-panel').innerHTML = '';

  document.getElementById('close-modal').addEventListener('click', closeModal);
  const atomBtn = document.getElementById('open-atom-btn');
  if (atomBtn) atomBtn.addEventListener('click', () => showAtomPreview(b));
}

// Close on backdrop click (not modal itself)
document.getElementById('modal-backdrop').addEventListener('click', e => {
  if (e.target === document.getElementById('modal-backdrop')) closeModal();
});

// Close on Escape
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

document.querySelectorAll('thead th[data-col]').forEach(th => {
  th.addEventListener('click', () => {
    const col = th.dataset.col;
    sortDir = sortCol === col ? sortDir * -1 : 1;
    sortCol = col;
    document.querySelectorAll('thead th').forEach(h => { h.classList.remove('sorted'); h.querySelector('.sort-icon') && (h.querySelector('.sort-icon').textContent='↕'); });
    th.classList.add('sorted');
    th.querySelector('.sort-icon').textContent = sortDir===1?'↑':'↓';
    renderTable();
  });
});

const FILTER_IDS = ['f-vertical','f-am','f-dm','f-ds','f-pss','f-channel','f-product'];

['search', ...FILTER_IDS].forEach(id => {
  document.getElementById(id).addEventListener('input', () => {
    FILTER_IDS.forEach(fid => {
      document.getElementById(fid).classList.toggle('active', document.getElementById(fid).value !== '');
    });
    renderTable();
  });
});

document.getElementById('clear-filters').addEventListener('click', () => {
  document.getElementById('search').value = '';
  FILTER_IDS.forEach(id => {
    const el = document.getElementById(id);
    el.value = ''; el.classList.remove('active');
  });
  renderTable();
});

// ── Dashboard tabs ────────────────────────────────────────────────────────
let activeTab = 'all';

document.querySelectorAll('.dash-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.dash-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    activeTab = tab.dataset.tab;
    renderTable();
  });
});

// ── Token management ──────────────────────────────────────────────────────

async function saveAtomToken() {
  const token  = (document.getElementById('new-token-input')?.value || '').trim();
  const status = document.getElementById('token-save-status');
  if (!token) { if(status) status.textContent = '⚠ Paste a token first'; return; }
  if(status) status.innerHTML = '<span style="color:var(--muted)">Saving…</span>';

  const form = new FormData();
  form.append('action', 'update_token');
  form.append('token',  token);
  try {
    const res  = await fetch(window.location.href, { method:'POST', body:form });
    const data = await res.json();
    if (data.success) {
      if(status) status.innerHTML = '<span style="color:#1F6B2A">✓ Saved — expires '+data.expiry+' · '+data.name+'</span>';
      // Reload page after 1.5s so the banner refreshes
      setTimeout(() => location.reload(), 1500);
    } else {
      if(status) status.innerHTML = '<span style="color:#C0360C">✗ '+data.error+'</span>';
    }
  } catch(e) {
    if(status) status.innerHTML = '<span style="color:#C0360C">✗ '+e.message+'</span>';
  }
}

async function saveInlineToken(retryPayload, retryBrd, retryPanel) {
  const token  = (document.getElementById('inline-token-input')?.value || '').trim();
  const status = document.getElementById('inline-token-status');
  if (!token) { if(status) status.textContent = '⚠ Paste a token first'; return; }
  if(status) status.innerHTML = '<span style="color:var(--muted)">Saving…</span>';

  const form = new FormData();
  form.append('action', 'update_token');
  form.append('token',  token);
  try {
    const res  = await fetch(window.location.href, { method:'POST', body:form });
    const data = await res.json();
    if (data.success) {
      if(status) status.innerHTML = '<span style="color:#1F6B2A">✓ Saved — retrying…</span>';
      // Also update the top banner immediately
      var topBar = document.getElementById('token-ok-bar');
      if (topBar) topBar.style.display = '';
      // Retry the ATOM push automatically
      if (retryPayload && retryBrd && retryPanel) {
        setTimeout(() => sendToAtom(retryPayload, retryBrd, retryPanel), 800);
      } else {
        setTimeout(() => location.reload(), 1200);
      }
    } else {
      if(status) status.innerHTML = '<span style="color:#C0360C">✗ '+data.error+'</span>';
    }
  } catch(e) {
    if(status) status.innerHTML = '<span style="color:#C0360C">✗ '+e.message+'</span>';
  }
}

// ── ATOM integration ──────────────────────────────────────────────────────

const DUR_MAP = {
  'Email Broadcast': ['BRD','Warm Up Type','Migration','Warm Up Progress','Current Usage','Remarks'],
  'PX':              ['BRD','Android Integration','iOS Integration','Integration Go Live','Use Case Go Live','Remarks'],
  'RBP':             ['Use Case Received','Banner Received','Use Case Live','Remarks'],
  'APP':             ['BRD','Android Integration','iOS Integration','Integration Go Live','Use Case Go Live','Remarks'],
  'Web':             ['BRD','Staging Integration','Production Integration','Integration Go Live','Use Case Go Live','Remarks'],
  'API':             ['Activity API','Activity API Integration','Contact API','Contact API Integration','Remarks'],
  'WA':              ['BRD','Channel Activation','Warm Up','Current Usage','Remarks'],
  'RCS':             ['BRD','Channel Activation','Current Usage','Remarks'],
  'SMS':             ['BRD','Channel Activation','Current Usage','Remarks'],
  'Journey':         ['Use Case','Remarks'],
};

function mapBrdToAtom(b) {
  const d = b.data || {};
  const subs = new Set();
  const chanMap = { email:'Email Broadcast', sms:'SMS', whatsapp:'WA', rcs:'RCS', webpush:'Web', apppush:'APP' };
  (d.channels||[]).forEach(c => { if (chanMap[c]) subs.add(chanMap[c]); });
  if ((d.products||[]).includes('px'))    subs.add('PX');
  if ((d.products||[]).includes('cpaas')) subs.add('API');
  if (d.webEnabled) subs.add('Web');
  if (d.appEnabled) subs.add('APP');
  if ((d.useCases||[]).length > 0) subs.add('Journey');
  if ((d.dataIngest||'').toLowerCase().includes('api')) subs.add('API');
  const durFields = {};
  Object.entries(DUR_MAP).forEach(([sp, keys]) => keys.forEach(k => { durFields['duration_'+sp+'_'+k] = '0'; }));
  const primaryProduct = (d.products||[]).includes('cpaas') ? 'CPaaS' : 'CE';
  return {
    accountName:       b.clientName || '',
    accountManager:    d.am  || '',
    projectManager:    d.dm  || '',
    manager:           d.ds  || '',
    productType:       primaryProduct,
    subProducts:       [...subs],
    accountScope:      '',              // manual entry only
    region:            d.region || '',  // pre-filled from BRD if set
    migrationPlatform: '',
    accountHealth:     'Good',
    mrr:               parseInt(d.payFlat) || 0,
    soiSignoffDate:    d.startDate   || '',
    billingDate:       d.billingDate || '',
    billingStatus:     d.payMode === 'flat' ? 'Billed' : 'Not Billed',
    progressStatus:    parseInt(b.step) >= 4 ? 'Active' : 'Yet to start',
    templateConfigs:   {},
    ...durFields,
  };
}

function getPayloadWarnings(p) {
  const w = [];
  if (!p.accountName)        w.push({ field:'accountName',    msg:'Client name missing' });
  if (!p.accountManager)     w.push({ field:'accountManager', msg:'AM not set on BRD' });
  if (!p.projectManager)     w.push({ field:'projectManager', msg:'DM not set on BRD' });
  if (!p.manager)            w.push({ field:'manager',        msg:'DS not set on BRD' });
  if (!p.region)             w.push({ field:'region',         msg:'Region must be selected' });
  if (!p.soiSignoffDate)     w.push({ field:'soiSignoffDate', msg:'SOI signoff date missing' });
  if (!p.billingDate)        w.push({ field:'billingDate',    msg:'Billing date missing' });
  if (!p.subProducts.length) w.push({ field:'subProducts',   msg:'No sub-products derived' });
  if (!p.mrr)                w.push({ field:'mrr',            msg:'MRR is 0' });
  return w;
}

function selOpts(opts, current) {
  return opts.map(o => '<option value="'+o+'"'+(o===current?' selected':'')+'>'+o+'</option>').join('');
}
function durKey(sp, k) { return 'dur__'+sp.replace(/ /g,'_')+'__'+k.replace(/ /g,'_'); }

function readPayloadFromForm(base) {
  const v = id => { const el = document.getElementById(id); return el ? el.value : ''; };
  const region = v('ap-region') === '__custom__' ? (v('ap-region-custom')||'') : v('ap-region');
  const p = Object.assign({}, base, {
    productType:       v('ap-productType')       || base.productType,
    region,
    migrationPlatform: v('ap-migrationPlatform') || '',
    accountHealth:     v('ap-accountHealth')     || 'Good',
    soiSignoffDate:    v('ap-soiSignoffDate')     || base.soiSignoffDate,
    billingDate:       v('ap-billingDate')        || base.billingDate,
    billingStatus:     v('ap-billingStatus')      || base.billingStatus,
    progressStatus:    v('ap-progressStatus')     || base.progressStatus,
    accountScope:      v('ap-accountScope') !== null ? v('ap-accountScope') : base.accountScope,
    mrr:               parseInt(v('ap-mrr'))      || base.mrr,
  });
  Object.entries(DUR_MAP).forEach(([sp, keys]) => {
    keys.forEach(k => { const el = document.getElementById(durKey(sp,k)); if (el) p['duration_'+sp+'_'+k] = el.value || '0'; });
  });
  return p;
}

const REGIONS = ['Australia','Bangladesh','Domestic - North','Domestic - South','Domestic - West','Europe','Indonesia','LATAM','Malaysia','Nigeria','North America','Philippines','South Africa','Thailand','UAE','Singapore'];
const MIGRATION_PLATFORMS = ['None','Clevertap','Moengage','SFMC','Webengage','Adobe','Amazon SES','Brevo','Gamooga','Keap','Klaviyo','Mailchimp','Mailgun','NotifyVisitor','Remarkety','Responsys','Selligent (Marigold)','Sendgrid','VTEX','Zoho','drip','emarsys','gokwik'];
const BILLING_STATUSES  = ['Billed','Consider Billed','Alert','No Clarity','Dropped','Not Billed','Moved to NXT Qtr'];
const PROGRESS_STATUSES = ['Active','On Hold','Completed','Slow Moving','Yet to start'];

function showAtomPreview(b) {
  const panel   = document.getElementById('atom-panel');
  const payload = mapBrdToAtom(b);
  const warns   = getPayloadWarnings(payload);
  const warnFields = new Set(warns.map(w => w.field));

  function roRow(lbl, val, fk) {
    const dot = warnFields.has(fk) ? '<span style="color:var(--nc-orange);margin-left:3px">●</span>' : '';
    let display;
    if (Array.isArray(val)) {
      display = val.length
        ? '<div class="sub-badges">'+val.map(function(v){return '<span class="badge b-teal">'+v+'</span>';}).join('')+'</div>'
        : '<span class="form-val-ro empty">(none)</span>';
    } else {
      display = '<span class="form-val-ro'+(val?'':' empty')+'">'+(val||'(empty)')+'</span>';
    }
    return '<div class="form-row"><label>'+lbl+dot+'</label>'+display+'</div>';
  }
  function selRow(lbl, id, opts, cur, fk) {
    const dot = warnFields.has(fk) ? '<span style="color:var(--nc-orange);margin-left:3px">●</span>' : '';
    return '<div class="form-row"><label>'+lbl+dot+'</label><select id="'+id+'" class="form-select">'+selOpts(opts,cur)+'</select></div>';
  }
  function inputRow(lbl, id, type, val, fk, extra) {
    const dot = warnFields.has(fk) ? `<span style="color:var(--nc-orange);margin-left:3px">●</span>` : '';
    return `<div class="form-row"><label>${lbl}${dot}</label><input type="${type}" id="${id}" value="${val||''}" class="form-input" ${extra||''}></div>`;
  }

  const regionOpts = '<option value="">— select region —</option>'
    + REGIONS.map(r=>'<option value="'+r+'"'+(r===payload.region?' selected':'')+'>'+r+'</option>').join('')
    + '<option value="__custom__">+ Add New Region</option>';

  const migOpts = '<option value="">— select platform —</option>'
    + MIGRATION_PLATFORMS.map(r=>'<option value="'+r+'"'+(r===payload.migrationPlatform?' selected':'')+'>'+r+'</option>').join('');

  const coreHTML =
    roRow('accountName',       payload.accountName,    'accountName') +
    roRow('accountManager',    payload.accountManager, 'accountManager') +
    roRow('projectManager (DM)', payload.projectManager, 'projectManager') +
    roRow('manager (DS)',      payload.manager,        'manager') +
    roRow('subProducts',       payload.subProducts,    'subProducts') +
    inputRow('accountScope', 'ap-accountScope', 'text', payload.accountScope, 'accountScope', 'style="width:200px" placeholder="Enter scope…"') +
    selRow('productType',      'ap-productType',       ['CE','CPaaS'],          payload.productType,    'productType') +
    '<div class="form-row"><label>region'+(warnFields.has('region')?'<span style="color:var(--nc-orange);margin-left:3px">●</span>':'')+
      '</label><select id="ap-region" class="form-select">'+regionOpts+'</select></div>'+
    '<div id="ap-region-custom-wrap" style="display:none"><div class="form-row"><label>custom region</label><input type="text" id="ap-region-custom" class="form-input-custom" placeholder="Type new region…"></div></div>'+
    '<div class="form-row"><label>migrationPlatform</label><select id="ap-migrationPlatform" class="form-select">'+migOpts+'</select></div>'+
    selRow('accountHealth',    'ap-accountHealth',     ['Good','Average','Poor'],          payload.accountHealth,  'accountHealth') +
    '<div class="form-row"><label>mrr'+(warnFields.has('mrr')?'<span style="color:var(--nc-orange);margin-left:3px">●</span>':'')+
      '</label><input type="number" id="ap-mrr" value="'+(payload.mrr||0)+'" min="0" step="1000" class="form-input"></div>'+
    '<div class="form-row"><label>soiSignoffDate'+(warnFields.has('soiSignoffDate')?'<span style="color:var(--nc-orange);margin-left:3px">●</span>':'')+
      '</label><input type="date" id="ap-soiSignoffDate" value="'+(payload.soiSignoffDate||'')+'" class="form-input"></div>'+
    '<div class="form-row"><label>billingDate'+(warnFields.has('billingDate')?'<span style="color:var(--nc-orange);margin-left:3px">●</span>':'')+
      '</label><input type="date" id="ap-billingDate" value="'+(payload.billingDate||'')+'" class="form-input"></div>'+
    selRow('billingStatus',    'ap-billingStatus',     BILLING_STATUSES,        payload.billingStatus,  'billingStatus') +
    selRow('progressStatus',   'ap-progressStatus',    PROGRESS_STATUSES,       payload.progressStatus, 'progressStatus');

  const durHTML = Object.entries(DUR_MAP).map(function(entry) {
    const sp = entry[0], keys = entry[1];
    return '<div class="dur-group-title">'+sp+'</div>'
      + keys.map(function(k){
          return '<div class="dur-row"><label>'+k+'</label><input type="number" id="'+durKey(sp,k)+'" value="0" min="0" class="dur-input"></div>';
        }).join('');
  }).join('');

  panel.innerHTML =
    '<div class="atom-panel">'
    + '<div class="atom-tabs">'
    +   '<span class="atom-tab-active-label">Payload Editor</span>'
    + '</div>'
    + '<div class="atom-tab-body" id="atom-tab-content">'
    +   '<div class="form-grid">'
    +     '<div class="form-col"><div class="form-section-title">Core fields</div>'+coreHTML+'</div>'
    +     '<div class="form-col"><div class="form-section-title">Duration fields</div><div class="dur-section">'+durHTML+'</div></div>'
    +   '</div>'
    + '</div>'
    + '<div class="warn-list'+(warns.length?'':' ok')+'">'
    +   '<ul>'+(warns.length
        ? warns.map(function(w){return '<li>⚠ '+w.msg+'</li>';}).join('')
        : '<li>✓ All core fields mapped — verify region before sending</li>')+'</ul>'
    + '</div>'
    + '<div class="atom-confirm-bar">'
    +   '<div style="font-size:12px;color:var(--muted)">'+warns.length+' warning'+(warns.length!==1?'s':'')+' &nbsp;·&nbsp; orange ● needs attention</div>'
    +   '<div class="atom-actions">'
    +     '<button class="atom-cancel-btn" id="atom-cancel">Hide</button>'
    +     '<button class="atom-send-btn" id="atom-send">Send to ATOM →</button>'
    +   '</div>'
    + '</div>'
    + '</div>';

  const regionSel = document.getElementById('ap-region');
  if (regionSel) {
    regionSel.addEventListener('change', function() {
      document.getElementById('ap-region-custom-wrap').style.display =
        regionSel.value === '__custom__' ? 'block' : 'none';
    });
  }

  // payload editor only — no tab switching needed

  document.getElementById('atom-cancel').addEventListener('click', function(){ panel.innerHTML = ''; });
  document.getElementById('atom-send').addEventListener('click', function(){
    sendToAtom(readPayloadFromForm(payload), b, panel);
  });
  // Scroll the backdrop so the payload editor is visible
  setTimeout(function() {
    var ap = document.getElementById('atom-panel');
    var bd = document.getElementById('modal-backdrop');
    if (ap && bd) {
      var apTop = ap.getBoundingClientRect().top - bd.getBoundingClientRect().top + bd.scrollTop;
      bd.scrollTo({ top: apTop - 12, behavior: 'smooth' });
    }
  }, 80);
}

async function sendToAtom(payload, b, panel) {
  panel.innerHTML = '<div class="atom-loading"><div class="spinner"></div>Sending <strong>'+b.clientName+'</strong> to ATOM\u2026</div>';
  const form = new FormData();
  form.append('action', 'add_to_atom');
  form.append('payload', JSON.stringify(payload));
  try {
    const res  = await fetch(window.location.href, { method:'POST', body:form });
    const data = await res.json();
    if (data.success && data.data) {
      const p = data.data;
      // Save IDs back to BRD file
      try {
        const sf = new FormData();
        sf.append('action', 'save_atom_ids');
        sf.append('brdId',         b.id);
        sf.append('atomMongoId',   p._id || '');
        sf.append('atomProjectId', p.id  || '');
        await fetch(window.location.href, { method:'POST', body:sf });
        const idx = BRDS.findIndex(function(x){return x.id===b.id;});
        if (idx >= 0) {
          BRDS[idx].atomId         = p._id;
          BRDS[idx].atomProjectId  = p.id;
          BRDS[idx].atomExportedAt = new Date().toISOString();
        }
        renderTable();
        // Swap button to exported tag in the still-open modal header
        const openBtn = document.getElementById('open-atom-btn');
        if (openBtn) {
          openBtn.outerHTML = `<span class="atom-exported-tag">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="13" height="13"><path d="M20 6L9 17l-5-5"/></svg>
            Exported to ATOM
          </span>`;
        }
      } catch(e2){ console.warn('save_atom_ids failed:', e2); }
      panel.innerHTML =
        '<div class="atom-result">'
        + '<div class="atom-result-success">'
        +   '<h4>\u2713 Project created in ATOM</h4>'
        +   '<p><strong>'+p.accountName+'</strong></p>'
        +   '<p style="margin-top:6px;font-family:\'JetBrains Mono\',monospace;font-size:12px">'
        +     '_id: <strong>'+p._id+'</strong><br>id: <strong>'+p.id+'</strong>'
        +   '</p>'
        +   '<p style="margin-top:6px">Tasks: <strong>'+p.tasksPending+'</strong> &nbsp;\u00b7&nbsp; '
        +     'Status: <strong>'+p.progressStatus+'</strong> &nbsp;\u00b7&nbsp; Billing: <strong>'+p.billingStatus+'</strong></p>'
        +   '<p style="margin-top:4px;font-size:11px;color:#2A5E12">ATOM IDs saved to BRD JSON \u2713</p>'
        + '</div>'
        + '<div style="font-size:11px;color:var(--muted);margin:10px 0 4px">Full API response:</div>'
        + '<div class="atom-result-code">'+JSON.stringify(data, null, 2)+'</div>'
        + '</div>';
    } else {
      const msg = data.error || data.message || JSON.stringify(data);
      const httpCode = data._httpCode || res.status;
      panel.innerHTML =
        '<div class="atom-result">'
        + '<div class="atom-result-error">'
        +   '<h4>\u2717 ATOM API Error <span style="font-weight:400;font-size:12px">(HTTP '+httpCode+')</span></h4>'
        +   '<p>'+msg+'</p>'
        + '</div>'
        + (httpCode===401||httpCode===403 ? '<div id="inline-token-update" style="background:#FFF7ED;border:1.5px solid var(--nc-orange);border-radius:var(--radius);padding:16px 18px;margin-top:10px">'
        +   '<div style="font-size:13px;font-weight:700;color:var(--nc-navy);margin-bottom:4px">🔑 Token expired — update it here</div>'
        +   '<div style="font-size:12px;color:var(--muted);margin-bottom:10px">Paste a fresh JWT from atom.netcorecloud.com (DevTools → Network → any request → Authorization header)</div>'
        +   '<textarea id="inline-token-input" rows="3" placeholder="eyJhbGciOiJIUzI1NiIs\u2026" style="width:100%;padding:9px 12px;border:1px solid var(--border-md);border-radius:var(--radius);font-family:\"JetBrains Mono\",monospace;font-size:11px;resize:vertical;outline:none;line-height:1.5"></textarea>'
        +   '<div style="display:flex;align-items:center;gap:10px;margin-top:10px;flex-wrap:wrap">'
        +     '<button onclick="saveInlineToken()" style="padding:8px 18px;background:var(--nc-orange);color:white;border:none;border-radius:var(--radius);font-size:13px;font-weight:700;cursor:pointer;font-family:inherit">Save &amp; Retry Push</button>'
        +     '<span id="inline-token-status" style="font-size:12px"></span>'
        +   '</div>'
        + '</div>' : '')
        + '<div style="font-size:11px;color:var(--muted);margin:12px 0 4px">Full response:</div>'
        + '<div class="atom-result-code">'+JSON.stringify(data, null, 2)+'</div>'
        + '<div style="margin-top:12px"><button class="atom-cancel-btn" id="back-to-preview">\u2190 Back to preview</button></div>'
        + '</div>';
      document.getElementById('back-to-preview').addEventListener('click', function(){ showAtomPreview(b); });
      // Wire inline token save button with retry context (payload + b + panel)
      if (httpCode===401||httpCode===403) {
        var inlineBtn = panel.querySelector('#inline-token-update button');
        if (inlineBtn) inlineBtn.onclick = function(){ saveInlineToken(payload, b, panel); };
      }
    }
  } catch(e) {
    panel.innerHTML =
      '<div class="atom-result"><div class="atom-result-error"><h4>\u2717 Request failed</h4><p>'+e.message+'</p></div></div>';
  }
}

renderTable();
</script>
</body>
</html>