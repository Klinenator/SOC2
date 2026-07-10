<?php
// Thin PHPMailer wrapper. SMTP credentials are read from data/smtp.json (gitignored,
// present only on the server) — never hardcoded here.
// The bundled PHPMailer is an older release; silence its parse-time deprecation
// notices so cron output stays clean (does not affect real errors).
error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/../lib/class.phpmailer.php';
require_once __DIR__ . '/../lib/class.smtp.php';

function smtp_config() {
    $path = __DIR__ . '/../data/smtp.json';
    if (!file_exists($path)) return null;
    return json_decode(file_get_contents($path), true) ?: null;
}

/**
 * Send an HTML email. Returns [ok(bool), error(string)].
 * $cc is an optional array of addresses.
 */
function send_mail($to, $subject, $html, $cc = [], $text = '') {
    $cfg = smtp_config();
    if (!$cfg) return [false, 'no data/smtp.json on this host'];

    $mail = new PHPMailer(true);
    try {
        $mail->IsSMTP();
        $mail->Host       = $cfg['host'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'] ?? '';
        $mail->Password   = $cfg['password'] ?? '';
        $mail->SMTPSecure = $cfg['secure'] ?? 'tls';
        $mail->Port       = (int)($cfg['port'] ?? 587);

        $mail->SetFrom($cfg['from_email'] ?? 'soc@accessrrs.com', $cfg['from_name'] ?? 'RRS SOC Compliance');
        $mail->AddAddress($to);
        foreach ((array)$cc as $c) { if ($c) $mail->AddCC($c); }

        $mail->IsHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text ?: trim(strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $html)));

        $mail->Send();
        return [true, ''];
    } catch (Exception $e) {
        return [false, $mail->ErrorInfo ?: $e->getMessage()];
    }
}
