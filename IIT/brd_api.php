<?php
/**
 * Netcore BRD Portal — Server-side Draft Storage API
 * Place alongside netcore_brd_portal.html
 * Location: /var/www/html/client_customize/IIT/brd_api.php
 */

// ── Always output JSON, even on fatal errors ──────────────────────────────
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_exception_handler(function($e) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'PHP exception: ' . $e->getMessage()]);
    exit;
});
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            header('Access-Control-Allow-Origin: *');
        }
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'PHP fatal error: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']]);
    }
});

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Storage directory — tries multiple locations ──────────────────────────
function resolveDraftsDir() {
    $candidates = [
        __DIR__ . '/brd_drafts',
        sys_get_temp_dir() . '/brd_drafts',
    ];
    foreach ($candidates as $dir) {
        if (is_dir($dir) && is_writable($dir)) {
            return rtrim($dir, '/') . '/';
        }
        if (!is_dir($dir)) {
            if (@mkdir($dir, 0775, true)) {
                @chmod($dir, 0775);
                return rtrim($dir, '/') . '/';
            }
        }
    }
    return null;
}

$DRAFTS_DIR = resolveDraftsDir();

// ── Read input ─────────────────────────────────────────────────────────────
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input) $input = $_REQUEST;
$action = isset($input['action']) ? $input['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

// ── Diagnostic endpoint (?action=ping) ───────────────────────────────────
if ($action === 'ping') {
    $preferred = __DIR__ . '/brd_drafts';
    $info = [
        'ok'           => true,
        'php_version'  => PHP_VERSION,
        'script_dir'   => __DIR__,
        'drafts_dir'   => $DRAFTS_DIR,
        'dir_writable' => $DRAFTS_DIR ? is_writable($DRAFTS_DIR) : false,
        'process_user' => function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? get_current_user()) : get_current_user(),
        'preferred_exists'   => is_dir($preferred),
        'preferred_writable' => is_writable($preferred),
        'preferred_perms'    => is_dir($preferred) ? substr(sprintf('%o', fileperms($preferred)), -4) : 'n/a',
        'storage_ok'         => !empty($DRAFTS_DIR),
        'fix_cmd'            => 'mkdir -p ' . $preferred . ' && chmod 775 ' . $preferred,
    ];
    echo json_encode($info, JSON_PRETTY_PRINT);
    exit;
}

// ── Guard: storage must be available ─────────────────────────────────────
if (!$DRAFTS_DIR) {
    jsonError('Storage unavailable. Fix with: mkdir -p ' . __DIR__ . '/brd_drafts && chown apache:apache ' . __DIR__ . '/brd_drafts && chmod 775 ' . __DIR__ . '/brd_drafts');
}

// ── Route ─────────────────────────────────────────────────────────────────
switch ($action) {

    case 'save':
        $id   = sanitizeId(isset($input['id']) ? $input['id'] : '');
        $meta = isset($input['meta']) ? $input['meta'] : null;
        if (!$id)   jsonError('Missing draft id');
        if (!$meta) jsonError('Missing draft meta');
        $meta['id']        = $id;
        $meta['updatedAt'] = date('c');
        $path = draftPath($id);
        if (file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            jsonError('Failed to write draft. Dir=' . $DRAFTS_DIR . ' writable=' . (is_writable($DRAFTS_DIR) ? 'yes' : 'no'));
        }
        jsonOk(['saved' => true, 'id' => $id, 'updatedAt' => $meta['updatedAt']]);
        break;

    case 'get':
        $id = sanitizeId(isset($input['id']) ? $input['id'] : (isset($_GET['id']) ? $_GET['id'] : ''));
        if (!$id) jsonError('Missing draft id');
        $path = draftPath($id);
        if (!file_exists($path)) jsonError('Draft not found: ' . $id, 404);
        $meta = json_decode(file_get_contents($path), true);
        if (!$meta) jsonError('Corrupted draft file: ' . $id);
        jsonOk($meta);
        break;

    case 'list':
        $files = glob($DRAFTS_DIR . 'brd_*.json');
        $items = [];
        if ($files) {
            foreach ($files as $f) {
                $meta = json_decode(file_get_contents($f), true);
                if (!$meta) continue;
                $items[] = [
                    'id'         => $meta['id'] ?? '',
                    'clientName' => $meta['clientName'] ?? 'Untitled',
                    'vertical'   => $meta['vertical'] ?? '',
                    'step'       => $meta['step'] ?? 0,
                    'filledBy'   => $meta['filledBy'] ?? 'Unknown',
                    'updatedAt'  => $meta['updatedAt'] ?? '',
                ];
            }
            usort($items, function($a, $b) {
                return strcmp($b['updatedAt'], $a['updatedAt']);
            });
        }
        jsonOk(['drafts' => $items]);
        break;

    case 'delete':
        $id = sanitizeId(isset($input['id']) ? $input['id'] : '');
        if (!$id) jsonError('Missing draft id');
        $path = draftPath($id);
        if (!file_exists($path)) jsonError('Draft not found: ' . $id, 404);
        if (!unlink($path)) jsonError('Failed to delete draft');
        jsonOk(['deleted' => true, 'id' => $id]);
        break;

    default:
        jsonError('Unknown action: ' . htmlspecialchars($action), 400);
}

// ── Helpers ───────────────────────────────────────────────────────────────
function sanitizeId($id) {
    $id = strtoupper(trim($id));
    if (!preg_match('/^NC-[A-Z0-9]{4}$/', $id)) return '';
    return $id;
}
function draftPath($id) {
    global $DRAFTS_DIR;
    return $DRAFTS_DIR . 'brd_' . $id . '.json';
}
function jsonOk($data) {
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}
function jsonError($msg, $code = 200) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}