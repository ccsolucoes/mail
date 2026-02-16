<?php
/**
 * CCMail v1.1 — simple, client-ship-ready mailer (mail() or SMTP AUTH LOGIN).
 * - No external deps.
 * - Safe headers (anti-injection), validation helpers, rate-limit + honeypot, CORS helpers.
 *
 * Usage:
 *   require __DIR__ . '/CCMail.php';
 *   $cfg = require __DIR__ . '/mail-config.php';
 *   $mailer = new CCMail($cfg);
 *   $mailer->send([...]);
 */

class CCMail
{
  private array $cfg;
  private string $logFile;

  public function __construct(array $config)
  {
    $this->cfg = $this->normalizeConfig($config);
    $this->logFile = $this->cfg['logging']['file'] ?? (sys_get_temp_dir() . '/ccmail.log');
  }

  /** Public: send a plain email */
  public function send(array $opts): array
  {
    $to      = $this->mustEmail($opts['to'] ?? '');
    $subject = $this->safeHeaderText($opts['subject'] ?? '');
    $message = (string)($opts['message'] ?? '');

    if ($to === '' || $subject === '' || trim($message) === '') {
      return $this->fail('missing_fields', 'Required: to, subject, message');
    }

    $fromEmail = $this->mustEmail($opts['from_email'] ?? $this->cfg['defaults']['from_email']);
    $fromName  = $this->safeHeaderText($opts['from_name'] ?? $this->cfg['defaults']['from_name']);
    $replyTo   = $this->safeHeaderText($opts['reply_to'] ?? '');
    if ($replyTo !== '') $replyTo = $this->mustEmail($replyTo);

    $textOnly = $this->cfg['defaults']['text_only'] ?? true;
    $asHtml   = (bool)($opts['html'] ?? !$textOnly);

    $headers = $this->buildHeaders($fromEmail, $fromName, $replyTo, $asHtml);

    $result = $this->deliver($to, $subject, $message, $headers, $fromEmail);
    $this->audit('send', [
      'ok' => $result['ok'],
      'to' => $this->redactEmail($to),
      'subject' => $subject,
      'mode' => $this->cfg['mode'],
      'ip' => $this->clientIp(),
      'origin' => $this->origin(),
      'bytes' => strlen($message),
      'err' => $result['error'] ?? null,
    ]);
    return $result;
  }

  /** Public: turnkey contact-form send (validates typical fields) */
  public function sendContact(array $payload): array
  {
    $name    = $this->cleanText($payload['name'] ?? '', 120);
    $email   = $this->mustEmail($payload['email'] ?? '');
    $message = $this->cleanText($payload['message'] ?? '', 5000);
    $phone   = $this->cleanText($payload['phone'] ?? '', 60);
    $subject = $this->safeHeaderText($payload['subject'] ?? $this->cfg['contact']['default_subject']);

    if ($name === '' || $email === '' || $message === '') {
      return $this->fail('missing_fields', 'Required: name, email, message');
    }
    if (mb_strlen($name) < 2)   return $this->fail('invalid_name', 'Name too short');
    if (mb_strlen($message) < 10) return $this->fail('invalid_message', 'Message too short');

    $to = $this->mustEmail($this->cfg['contact']['to_email']);

    // Build a clean, readable body (avoid dumping raw payload)
    $lines = [];
    $lines[] = "New contact form submission";
    $lines[] = "—";
    $lines[] = "Name: " . $name;
    $lines[] = "Email: " . $email;
    if ($phone !== '') $lines[] = "Phone: " . $phone;
    $lines[] = "—";
    $lines[] = $message;
    $body = implode("\n", $lines);

    return $this->send([
      'to' => $to,
      'subject' => $subject,
      'message' => $body,
      'reply_to' => $email,
      // You may override from_* here per client if desired
    ]);
  }

  /** Public: CORS check */
  public function isOriginAllowed(string $origin): bool
  {
    $origin = trim($origin);
    if ($origin === '') return false;

    $allowed = $this->cfg['security']['allowed_origins'] ?? [];
    foreach ($allowed as $pattern) {
      if ($this->originMatches($origin, $pattern)) return true;
    }
    return false;
  }

  /** Public: simple rate limit (file-based) */
  public function rateLimit(string $bucketKey, int $max, int $windowSeconds): bool
  {
    $dir = $this->cfg['security']['rate_limit_dir'] ?? (sys_get_temp_dir() . '/ccmail_rl');
    if (!is_dir($dir)) @mkdir($dir, 0700, true);

    $hash = hash('sha256', $bucketKey);
    $file = rtrim($dir, '/\\') . "/$hash.json";

    $now = time();
    $data = ['t' => $now, 'n' => 0];
    if (is_file($file)) {
      $raw = @file_get_contents($file);
      $json = json_decode($raw ?: '', true);
      if (is_array($json) && isset($json['t'], $json['n'])) $data = $json;
      // reset window
      if (($now - (int)$data['t']) > $windowSeconds) $data = ['t' => $now, 'n' => 0];
    }

    $data['n'] = (int)$data['n'] + 1;
    @file_put_contents($file, json_encode($data), LOCK_EX);

    return ((int)$data['n'] <= $max);
  }

  // ----------------------------
  // Internals
  // ----------------------------

  private function normalizeConfig(array $c): array
  {
    $defaults = [
      'mode' => 'mail', // 'mail' or 'smtp'
      'defaults' => [
        'from_email' => 'no-reply@example.com',
        'from_name' => 'Website',
        'text_only' => true,
      ],
      'contact' => [
        'to_email' => 'you@example.com',
        'default_subject' => 'New contact form message',
      ],
      'smtp' => [
        'host' => '',
        'port' => 587,
        'secure' => 'tls', // 'tls' | 'ssl' | 'none'
        'username' => '',
        'password' => '',
        'helo' => '',
        'timeout' => 12,
      ],
      'security' => [
        'allowed_origins' => [],
        'rate_limit_dir' => sys_get_temp_dir() . '/ccmail_rl',
      ],
      'logging' => [
        'enabled' => true,
        'file' => sys_get_temp_dir() . '/ccmail.log',
      ],
    ];

    $merged = array_replace_recursive($defaults, $c);

    // Basic sanity
    $mode = strtolower((string)($merged['mode'] ?? 'mail'));
    if ($mode !== 'smtp') $mode = 'mail';
    $merged['mode'] = $mode;

    // If smtp selected, require host + username + password
    if ($mode === 'smtp') {
      foreach (['host','username','password'] as $k) {
        if (trim((string)$merged['smtp'][$k]) === '') {
          throw new Exception("CCMail config invalid: smtp.$k required when mode=smtp");
        }
      }
    }

    return $merged;
  }

  private function deliver(string $to, string $subject, string $message, array $headers, string $fromEmail): array
  {
    if ($this->cfg['mode'] === 'smtp') {
      return $this->smtpSend($to, $subject, $message, $headers, $fromEmail);
    }

    // mail()
    $headersStr = implode("\r\n", $headers);
    $ok = @mail($to, $subject, $message, $headersStr);
    return $ok ? $this->ok() : $this->fail('mail_failed', 'mail() returned false');
  }

  private function buildHeaders(string $fromEmail, string $fromName, string $replyTo, bool $asHtml): array
  {
    $fromName = $this->encodeHeader($fromName);
    $from = sprintf('From: %s <%s>', $fromName, $fromEmail);

    $h = [];
    $h[] = $from;
    $h[] = 'MIME-Version: 1.0';
    $h[] = $asHtml
      ? 'Content-Type: text/html; charset=UTF-8'
      : 'Content-Type: text/plain; charset=UTF-8';

    if ($replyTo !== '') $h[] = 'Reply-To: ' . $replyTo;

    // Nice-to-have: reduce spammy signatures
    $h[] = 'X-Mailer: CCMail';
    return $h;
  }

  private function smtpSend(string $to, string $subject, string $message, array $headers, string $fromEmail): array
  {
    $smtp = $this->cfg['smtp'];
    $host = $smtp['host'];
    $port = (int)$smtp['port'];
    $secure = strtolower((string)$smtp['secure']);
    $timeout = (int)$smtp['timeout'];

    $transport = ($secure === 'ssl') ? "ssl://$host:$port" : "$host:$port";
    $fp = @stream_socket_client($transport, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$fp) return $this->fail('smtp_connect_failed', "SMTP connect failed: $errstr ($errno)");

    stream_set_timeout($fp, $timeout);
    $read = function() use ($fp) {
      $data = '';
      while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) break;
        $data .= $line;
        // Last line of a multiline response starts with "xyz " not "xyz-"
        if (preg_match('/^\d{3} /', $line)) break;
      }
      return $data;
    };
    $write = function($cmd) use ($fp) {
      fwrite($fp, $cmd . "\r\n");
    };
    $expect = function($resp, $codes) {
      foreach ((array)$codes as $c) {
        if (strpos($resp, (string)$c) === 0) return true;
      }
      return false;
    };

    $banner = $read();
    if (!$expect($banner, [220])) { fclose($fp); return $this->fail('smtp_bad_banner', trim($banner)); }

    $helo = trim((string)($smtp['helo'] ?: ($_SERVER['SERVER_NAME'] ?? 'localhost')));
    $write("EHLO " . $this->safeHelo($helo));
    $ehlo = $read();
    if (!$expect($ehlo, [250])) {
      // Try HELO fallback
      $write("HELO " . $this->safeHelo($helo));
      $heloResp = $read();
      if (!$expect($heloResp, [250])) { fclose($fp); return $this->fail('smtp_helo_failed', trim($heloResp)); }
    }

    // STARTTLS if requested
    if ($secure === 'tls') {
      $write("STARTTLS");
      $tlsResp = $read();
      if (!$expect($tlsResp, [220])) { fclose($fp); return $this->fail('smtp_starttls_failed', trim($tlsResp)); }

      if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fclose($fp); return $this->fail('smtp_tls_failed', 'TLS negotiation failed');
      }

      // Re-EHLO after TLS
      $write("EHLO " . $this->safeHelo($helo));
      $ehlo2 = $read();
      if (!$expect($ehlo2, [250])) { fclose($fp); return $this->fail('smtp_ehlo_failed', trim($ehlo2)); }
    }

    // AUTH LOGIN
    $user = (string)$smtp['username'];
    $pass = (string)$smtp['password'];
    $write("AUTH LOGIN");
    $a1 = $read();
    if (!$expect($a1, [334])) { fclose($fp); return $this->fail('smtp_auth_failed', trim($a1)); }
    $write(base64_encode($user));
    $a2 = $read();
    if (!$expect($a2, [334])) { fclose($fp); return $this->fail('smtp_auth_failed', trim($a2)); }
    $write(base64_encode($pass));
    $a3 = $read();
    if (!$expect($a3, [235, 503])) { fclose($fp); return $this->fail('smtp_auth_failed', trim($a3)); }

    $write("MAIL FROM:<$fromEmail>");
    $mf = $read();
    if (!$expect($mf, [250])) { fclose($fp); return $this->fail('smtp_mailfrom_failed', trim($mf)); }

    $write("RCPT TO:<$to>");
    $rt = $read();
    if (!$expect($rt, [250, 251])) { fclose($fp); return $this->fail('smtp_rcptto_failed', trim($rt)); }

    $write("DATA");
    $dr = $read();
    if (!$expect($dr, [354])) { fclose($fp); return $this->fail('smtp_data_failed', trim($dr)); }

    // Build RFC822 message
    $headersStr = implode("\r\n", $headers);
    $rfc = "To: <$to>\r\n";
    $rfc .= "Subject: " . $this->encodeHeader($subject) . "\r\n";
    $rfc .= $headersStr . "\r\n\r\n";
    $rfc .= $this->dotStuff($message) . "\r\n.";

    fwrite($fp, $rfc . "\r\n");
    $done = $read();
    if (!$expect($done, [250])) { fclose($fp); return $this->fail('smtp_send_failed', trim($done)); }

    $write("QUIT");
    fclose($fp);
    return $this->ok();
  }

  private function dotStuff(string $s): string
  {
    // Dot-stuffing: lines starting with "." must be doubled.
    return preg_replace('/^\./m', '..', $s);
  }

  private function safeHelo(string $h): string
  {
    // keep it RFC-ish: letters, numbers, dots, hyphens
    $h = preg_replace('/[^a-zA-Z0-9\.\-]/', '', $h);
    return $h !== '' ? $h : 'localhost';
  }

  private function mustEmail(string $email): string
  {
    $email = trim((string)$email);
    if ($email === '') return '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return '';
    // prevent header injection
    if (preg_match('/[\r\n]/', $email)) return '';
    return $email;
  }

  private function cleanText(string $s, int $maxLen): string
  {
    $s = trim((string)$s);
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/\n{3,}/', "\n\n", $s);
    $s = mb_substr($s, 0, $maxLen);
    return $s;
  }

  private function safeHeaderText(string $s): string
  {
    $s = trim((string)$s);
    // remove CR/LF to avoid header injection
    $s = preg_replace('/[\r\n]+/', ' ', $s);
    $s = preg_replace('/\s{2,}/', ' ', $s);
    $s = mb_substr($s, 0, 180);
    return $s;
  }

  private function encodeHeader(string $s): string
  {
    // Encode non-ascii safely
    if ($s === '') return '';
    if (preg_match('/[^\x20-\x7E]/', $s)) {
      return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }
    return $s;
  }

  private function ok(): array
  {
    return ['ok' => true];
  }
  private function fail(string $code, string $msg): array
  {
    return ['ok' => false, 'error' => $code, 'message' => $msg];
  }

  private function audit(string $event, array $data): void
  {
    if (!($this->cfg['logging']['enabled'] ?? true)) return;

    // redact any suspiciously sensitive fields (defensive)
    foreach (['email','message','phone'] as $k) {
      if (isset($data[$k])) $data[$k] = '[redacted]';
    }

    $line = json_encode([
      'ts' => gmdate('c'),
      'event' => $event,
      'data' => $data,
    ], JSON_UNESCAPED_SLASHES);

    @file_put_contents($this->logFile, $line . "\n", FILE_APPEND | LOCK_EX);
  }

  private function redactEmail(string $email): string
  {
    $email = (string)$email;
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) return '[redacted]';
    $u = $parts[0];
    $d = $parts[1];
    $u2 = mb_substr($u, 0, 2) . '***';
    return $u2 . '@' . $d;
  }

  private function clientIp(): string
  {
    $keys = ['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'];
    foreach ($keys as $k) {
      if (!empty($_SERVER[$k])) {
        $v = (string)$_SERVER[$k];
        if ($k === 'HTTP_X_FORWARDED_FOR') $v = trim(explode(',', $v)[0]);
        return $v;
      }
    }
    return '';
  }

  private function origin(): string
  {
    return isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : '';
  }

  private function originMatches(string $origin, string $pattern): bool
  {
    $pattern = trim($pattern);
    if ($pattern === '') return false;

    // Exact match
    if (strcasecmp($origin, $pattern) === 0) return true;

    // Wildcard pattern like https://*.example.com
    // Convert to regex safely
    $re = preg_quote($pattern, '/');
    $re = str_replace('\*', '.*', $re);
    return (bool)preg_match('/^' . $re . '$/i', $origin);
  }
}
