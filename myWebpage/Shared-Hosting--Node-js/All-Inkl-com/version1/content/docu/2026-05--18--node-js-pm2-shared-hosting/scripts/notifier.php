<?php
/**
 * Asynchronous SMTP Notifier for DEINE-DOMAIN
 * =======================================================
 * Handles Urgent Emergency Alerts and Daily Watchdog Summaries.
 * Executed asynchronously in the background via CLI by cron_pm2.php.
 */

if (php_sapi_name() !== "cli") {
    http_response_code(403);
    die("Forbidden - CLI execution only\n");
}

$mode = isset($argv[1]) ? $argv[1] : "";
if ($mode !== "emergency" && $mode !== "daily") {
    die("Usage: php notifier.php [emergency|daily]\n");
}

$webroot = "/www/htdocs/ACCOUNT_ID/DEINE-DOMAIN";
$logDir  = $webroot . "/watchdog_logs";
$today   = date("Y-m-d");
$logFile = $logDir . "/watchdog-" . $today . ".log";
$env     = "export HOME=/www/htdocs/ACCOUNT_ID; export PATH=/www/htdocs/ACCOUNT_ID/nodejs_current/bin:\$PATH; ";

function smtp_read($socket) {
    $data = "";
    while ($str = fgets($socket, 512)) {
        $data .= $str;
        if (substr($str, 3, 1) === " ") { break; }
    }
    return $data;
}

function sendSmtpMail($to, $subject, $message, $isUrgent = false) {
    $host = "ACCOUNT_ID.kasserver.com";
    $port = 465;
    $user = "mail@DEINE-DOMAIN";
    $pass = "SMTP_PASSWORT";
    $from = "mail@DEINE-DOMAIN";

    $socket = fsockopen("ssl://" . $host, $port, $errno, $errstr, 15);
    if (!$socket) { error_log("SMTP Connect Error: $errstr ($errno)"); return false; }
    smtp_read($socket);

    fwrite($socket, "EHLO " . $host . "\r\n");
    smtp_read($socket);

    fwrite($socket, "AUTH LOGIN\r\n");
    smtp_read($socket);
    fwrite($socket, base64_encode($user) . "\r\n");
    smtp_read($socket);
    fwrite($socket, base64_encode($pass) . "\r\n");
    $authRes = smtp_read($socket);
    if (strpos($authRes, "235") === false) { error_log("SMTP Auth Failed: $authRes"); fclose($socket); return false; }

    fwrite($socket, "MAIL FROM:<$from>\r\n");
    smtp_read($socket);
    fwrite($socket, "RCPT TO:<$to>\r\n");
    smtp_read($socket);
    fwrite($socket, "DATA\r\n");
    smtp_read($socket);

    $headers  = "From: System Watchdog <$from>\r\n";
    $headers .= "To: <$to>\r\n";
    $headers .= "Subject: $subject\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    if ($isUrgent) {
        $headers .= "X-Priority: 1 (Highest)\r\n";
        $headers .= "X-MSMail-Priority: High\r\n";
        $headers .= "Importance: High\r\n";
    }
    $headers .= "\r\n";

    fwrite($socket, $headers . $message . "\r\n.\r\n");
    smtp_read($socket);
    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return true;
}

if ($mode === "emergency") {
    // Throttling: max 1 Notfall-Mail pro 15 Minuten
    $throttleFile = $logDir . "/last_emergency_mail.ts";
    if (file_exists($throttleFile) && (time() - filemtime($throttleFile)) < 900) {
        die("Emergency email throttled (last sent < 15m ago)\n");
    }
    file_put_contents($throttleFile, time());

    $pm2List = shell_exec($env . "/www/htdocs/ACCOUNT_ID/nodejs_current/bin/pm2 list 2>&1");
    $lastLogs = file_exists($logFile) ? trim(shell_exec("tail -n 25 " . escapeshellarg($logFile))) : "Keine Logdatei gefunden.";

    $subject = "[EMERGENCY ALERT] DEINE-DOMAIN - Systemausfall & Neustart";
    $msg  = "DRINGENDER SYSTEM-ALARM\n";
    $msg .= "========================================\n";
    $msg .= "Datum/Zeit: " . date("Y-m-d H:i:s") . "\n";
    $msg .= "Ereignis: Beide Node.js Ports (3004 & 3005) haben nicht geantwortet.\n";
    $msg .= "Der KAS-Watchdog hat die automatische Notfallwiederherstellung eingeleitet.\n\n";
    $msg .= "--- Aktueller PM2 Status ---\n" . $pm2List . "\n\n";
    $msg .= "--- Letzte Watchdog Logs ---\n" . $lastLogs . "\n";

    sendSmtpMail("EMPFAENGER@EMAIL.COM", $subject, $msg, true);
    echo "Emergency notification sent.\n";
} elseif ($mode === "daily") {
    $pm2List = shell_exec($env . "/www/htdocs/ACCOUNT_ID/nodejs_current/bin/pm2 list 2>&1");
    
    $totalChecks = 0;
    $totalEmergencies = 0;
    if (file_exists($logFile)) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, "PortA") !== false) $totalChecks++;
            if (strpos($line, "EMERGENCY") !== false) $totalEmergencies++;
        }
    }

    $subject = "[DAILY REPORT] DEINE-DOMAIN - Watchdog Zusammenfassung " . $today;
    $msg  = "TÄGLICHER SYSTEM-BERICHT (" . $today . ")\n";
    $msg .= "========================================\n";
    $msg .= "Durchgeführte Überprüfungen: " . $totalChecks . " (minütlich)\n";
    $msg .= "Erfasste Notfall-Neustarts: " . $totalEmergencies . "\n";
    $msg .= "Systemstabilität: " . ($totalChecks > 0 ? round((($totalChecks - $totalEmergencies) / $totalChecks) * 100, 2) : 100) . "%\n\n";
    $msg .= "--- Aktueller PM2 Status ---\n" . $pm2List . "\n";

    sendSmtpMail("EMPFAENGER@EMAIL.COM", $subject, $msg, false);
    echo "Daily summary sent.\n";
}
?>
