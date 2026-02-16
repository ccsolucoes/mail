<?php
// CCMail API endpoint (POST JSON)
// - CORS allowlist (config)
// - honeypot
// - file-based rate limit
// - returns JSON

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/CCMail.php';

try {
  $cfg = require __DIR__ . '/mail-config.php';
  $mailer = new CCMail($cfg);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'server_config_error']);
  exit;
}

// Handle CORS preflight
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && $mailer->isOriginAllowed($origin)) {
  header('Access-Control-Allow-Origin: ' . $origin);
  header('Vary: Origin');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

// Only POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
  exit;
}

// Enforce allowed origin for browsers
if ($origin && !$mailer->isOriginAllowed($origin)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'origin_not_allowed']);
  exit;
}

// Read JSON body
$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'invalid_json']);
  exit;
}

// Simple honeypot field (bots fill it)
if (!empty($data['website'])) {
  http_response_code(200);
  echo json_encode(['ok' => true]); // silently succeed
  exit;
}

// Rate limit: per-IP + per-origin bucket
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$bucket = 'contact:' . $ip . ':' . ($origin ?: 'no-origin');
$max = 8;          // requests
$window = 10 * 60; // per 10 minutes
if (!$mailer->rateLimit($bucket, $max, $window)) {
  http_response_code(429);
  echo json_encode(['ok' => false, 'error' => 'rate_limited']);
  exit;
}

// Validate payload for contact form
$payload = [
  'name' => $data['name'] ?? '',
  'email' => $data['email'] ?? '',
  'phone' => $data['phone'] ?? '',
  'subject' => $data['subject'] ?? '',
  'message' => $data['message'] ?? '',
];

$res = $mailer->sendContact($payload);

if (!$res['ok']) {
  http_response_code(400);
}
echo json_encode($res);
