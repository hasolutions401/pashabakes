<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

/*
 * Sends one email. Never throws: returns [bool ok, string error] and records
 * the attempt in email_log so failures are visible (and resendable) in admin.
 */
/** Stored on emails that went out through the web server instead of Pasha's Gmail. */
const SPAM_RISK_NOTE = 'Sent without Gmail — may land in spam';

/**
 * Where the Gmail app password comes from: 'env' (PB_GMAIL_APP_PASSWORD), 'config'
 * (mail.gmail_app_password in server/config.php), 'admin' (saved in Admin → Settings, i.e.
 * in the database) or '' (none). The server-side places win: they stay out of database copies.
 */
function gmail_password_source(): string
{
    if ((string) getenv('PB_GMAIL_APP_PASSWORD') !== '') {
        return 'env';
    }
    if ((string) (config('mail.gmail_app_password') ?? '') !== '') {
        return 'config';
    }
    return setting('gmail_app_password') !== '' ? 'admin' : '';
}

function gmail_app_password(): string
{
    $value = match (gmail_password_source()) {
        'env' => (string) getenv('PB_GMAIL_APP_PASSWORD'),
        'config' => (string) config('mail.gmail_app_password'),
        'admin' => setting('gmail_app_password'),
        default => '',
    };
    return str_replace(' ', '', $value);
}

function send_email(string $to, string $subject, string $html, string $text, string $kind, ?int $orderId = null, ?string $replyTo = null): array
{
    $transport = (string) (config('mail.transport') ?: 'mail');
    $error = '';
    $ok = false;

    // Preferred: send through Pasha's own Gmail (set in Admin → Settings). Mail that
    // really comes from Gmail's servers is far less likely to land in spam.
    $gmailUser = trim(setting('gmail_address'));
    $gmailPass = gmail_app_password();
    if ($transport !== 'log' && $gmailUser !== '' && $gmailPass !== '') {
        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->Port = 587;
            $mail->SMTPAuth = true;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Username = $gmailUser;
            $mail->Password = $gmailPass;
            $mail->Timeout = 15;
            $mail->setFrom($gmailUser, (string) (config('mail.from_name') ?: 'Pashabakess'));
            $mail->addAddress($to);
            if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($replyTo);
            }
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $text;
            $ok = $mail->send();
        } catch (Throwable $e) {
            // Fall back to the server's own mail below, so the email still goes out.
            $error = 'Gmail: ' . $e->getMessage();
            log_error("Gmail send '{$kind}'" . ($orderId ? " (order #{$orderId})" : '') . " failed: {$e->getMessage()}");
        }
        if ($ok) {
            return email_log_result($to, $kind, $orderId, true, '');
        }
    }

    try {
        if ($transport === 'log') {
            $dir = data_dir('mail');
            $file = $dir . '/' . date('Ymd-His') . '-' . $kind . '-' . bin2hex(random_bytes(3)) . '.html';
            file_put_contents($file, "<!-- To: {$to}\nSubject: {$subject} -->\n" . $html);
            $ok = true;
        } else {
            $mail = new PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            if ($transport === 'smtp') {
                $mail->isSMTP();
                $mail->Host = (string) config('mail.smtp_host');
                $mail->Port = (int) (config('mail.smtp_port') ?: 587);
                $mail->SMTPAuth = true;
                $mail->Username = (string) config('mail.smtp_user');
                $mail->Password = (string) config('mail.smtp_pass');
                $mail->SMTPSecure = config('mail.smtp_secure') === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Timeout = 15;
            } else {
                $mail->isMail();
            }
            $mail->setFrom((string) config('mail.from_email'), (string) (config('mail.from_name') ?: 'Pashabakess'));
            $mail->addAddress($to);
            $reply = $replyTo ?: (string) config('mail.reply_to');
            if ($reply !== '' && filter_var($reply, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($reply);
            }
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $text;
            $ok = $mail->send();
        }
    } catch (Throwable $e) {
        $error = trim($error . ' | ' . $e->getMessage(), ' |');
        log_error("Email '{$kind}'" . ($orderId ? " (order #{$orderId})" : '') . " failed: {$e->getMessage()}");
    }

    // Sent, but not through Gmail: record why, so admin can show it may have gone to spam.
    $note = '';
    if ($ok && $transport !== 'log') {
        $note = SPAM_RISK_NOTE . ($gmailPass === '' ? ' (no Gmail app password set up)' : ' (Gmail refused: ' . $error . ')');
    }
    [$sent, $err] = email_log_result($to, $kind, $orderId, $ok, $ok ? $note : $error);
    // If Gmail failed but the fallback worked, still report the Gmail problem.
    return [$sent, $sent ? $error : $err];
}

/** Records a send attempt in email_log and returns [ok, error]. */
function email_log_result(string $to, string $kind, ?int $orderId, bool $ok, string $error): array
{
    try {
        db_exec('INSERT INTO email_log (order_id, kind, recipient, status, error, created_at) VALUES (?, ?, ?, ?, ?, ?)', [
            $orderId, $kind, $to, $ok ? 'sent' : 'failed', mb_substr($error, 0, 500), now_str(),
        ]);
    } catch (Throwable $e) {
        log_error('Could not write email_log: ' . $e->getMessage());
    }
    return [$ok, $error];
}

/**
 * Email problems of the last $days days, for the dashboard: order emails whose latest attempt
 * failed or went out without Gmail, and failed inquiry alerts.
 * Returns ['failed' => rows, 'no_gmail' => rows]; rows have order_id, code, kind, created_at.
 */
function recent_email_problems(int $days = 7): array
{
    $since = (new DateTimeImmutable('now', new DateTimeZone(PB_TZ)))->modify("-{$days} days")->format('Y-m-d H:i:s');
    $latest = db_all("SELECT e.order_id, o.code, e.kind, e.status, e.error, e.created_at FROM email_log e
        JOIN orders o ON o.id = e.order_id
        WHERE e.created_at >= ? AND e.id IN (SELECT MAX(id) FROM email_log WHERE order_id IS NOT NULL GROUP BY order_id, kind)
        ORDER BY e.id DESC", [$since]);
    $out = ['failed' => [], 'no_gmail' => []];
    foreach ($latest as $row) {
        if ($row['status'] === 'failed') {
            $out['failed'][] = $row;
        } elseif (str_starts_with($row['error'], SPAM_RISK_NOTE)) {
            $out['no_gmail'][] = $row;
        }
    }
    foreach (db_all("SELECT NULL AS order_id, NULL AS code, kind, created_at FROM email_log
        WHERE order_id IS NULL AND kind = 'enquiry' AND status = 'failed' AND created_at >= ? ORDER BY id DESC", [$since]) as $row) {
        $out['failed'][] = $row;
    }
    return $out;
}

/** Latest send status per email kind for an order, e.g. ['admin_alert' => 'sent']. */
function email_statuses(int $orderId): array
{
    $out = [];
    foreach (db_all('SELECT kind, status FROM email_log WHERE order_id = ? ORDER BY id', [$orderId]) as $row) {
        $out[$row['kind']] = $row['status'];
    }
    return $out;
}

/** Latest attempt per email kind for an order: status, recipient, time and any error. */
function email_details(int $orderId): array
{
    $out = [];
    foreach (db_all('SELECT kind, recipient, status, error, created_at FROM email_log WHERE order_id = ? ORDER BY id', [$orderId]) as $row) {
        $out[$row['kind']] = $row;
    }
    return $out;
}
