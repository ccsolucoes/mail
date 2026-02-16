<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

final class CCMail {
  private array $cfg;

  public function __construct(string $configPath) {
    if (!is_file($configPath)) throw new RuntimeException("Config não encontrada");
    $this->cfg = require $configPath;
  }

  public function send(array $payload): array {
    $mail = new PHPMailer(true);

    try {
      $smtp = $this->cfg["smtp"];
      $mail->isSMTP();
      $mail->Host       = $smtp["host"];
      $mail->Port       = $smtp["port"];
      $mail->SMTPSecure = $smtp["secure"];
      $mail->SMTPAuth   = true;
      $mail->Username   = $smtp["username"];
      $mail->Password   = $smtp["password"];

      $mail->CharSet = $this->cfg["defaults"]["charset"] ?? "UTF-8";

      $fromEmail = $payload["from_email"] ?? $this->cfg["from"]["email"];
      $fromName  = $payload["from_name"]  ?? $this->cfg["from"]["name"];
      $mail->setFrom($fromEmail, $fromName);

      if (!empty($payload["reply_to_email"])) {
        $mail->addReplyTo($payload["reply_to_email"], $payload["reply_to_name"] ?? "");
      }

      $this->addRecipients($mail, "addAddress", $payload["to"] ?? null);
      $this->addRecipients($mail, "addCC",      $payload["cc"] ?? null);
      $this->addRecipients($mail, "addBCC",     $payload["bcc"] ?? null);

      $mail->isHTML(true);
      $mail->Subject = $payload["subject"];
      $mail->Body    = $payload["html"];
      $mail->AltBody = strip_tags($payload["html"]);

      $ok = $mail->send();
      return ["ok" => $ok, "error" => $ok ? null : $mail->ErrorInfo];
    } catch (Exception $e) {
      return ["ok" => false, "error" => $e->getMessage()];
    }
  }

  private function addRecipients(PHPMailer $mail, string $method, $value): void {
    if ($value === null) return;

    if (is_string($value)) {
      $value = array_map("trim", explode(",", $value));
    }

    if (!is_array($value)) return;

    foreach ($value as $addr) {
      if ($addr) $mail->$method($addr);
    }
  }
}
