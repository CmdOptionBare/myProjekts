<?php
/**
 * cron_pm2.php – Emergency Watchdog für Node.js auf All-Inkl Shared Hosting
 * ============================================================================
 * Zweck: LETZTES SICHERHEITSNETZ. Greift NUR ein, wenn BEIDE Node.js Ports
 *        (PORT_A und PORT_B) gleichzeitig nicht antworten UND der Orchestrator
 *        ebenfalls gestoppt wurde (z.B. durch den Hoster-Reaper).
 *
 * Im Normalbetrieb wird das Routing durch orchestrator.js verwaltet.
 *
 * Variablen zum Anpassen:
 *   ACCOUNT_DIR  → /www/htdocs/DEIN-ACCOUNT-ID
 *   DOMAIN_DIR   → /www/htdocs/DEIN-ACCOUNT-ID/DEINE-DOMAIN
 *   PORT_A       → 3004 (oder dein primärer Port)
 *   PORT_B       → 3005 (oder dein sekundärer Port)
 *   NODE_BIN     → /www/htdocs/DEIN-ACCOUNT-ID/nodejs_current/bin
 */

// ── Konfiguration (anpassen!) ─────────────────────────────────────────────────
$accountDir = "/www/htdocs/ACCOUNT_ID";
$domainDir  = "/www/htdocs/ACCOUNT_ID/DEINE-DOMAIN";
$orchFile   = $domainDir . "/orchestrator.js";
$flagB      = $domainDir . "/port_b_active.flag";
$pm2        = $accountDir . "/nodejs_current/bin/pm2";
$env        = "export HOME=$accountDir; export PATH=$accountDir/nodejs_current/bin:\$PATH; ";
// ─────────────────────────────────────────────────────────────────────────────

// Tägliche Log-Rotation (maximal 31 Tage)
$logDir  = $domainDir . "/watchdog_logs";
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$today   = date("Y-m-d");
$logFile = "$logDir/watchdog-$today.log";
$ts      = date("Y-m-d H:i:s");
$ip      = $_SERVER["REMOTE_ADDR"] ?? "CLI";

// Maximal 31 Logdateien behalten
$files = glob("$logDir/watchdog-*.log");
if (is_array($files) && count($files) > 31) {
    rsort($files);
    foreach (array_slice($files, 31) as $f) { if (is_file($f)) unlink($f); }
}

// ── Hilfsfunktion: HTTP-Check auf lokalen Port ───────────────────────────────
function checkPort($port) {
    $ch = curl_init("http://127.0.0.1:$port/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 500;
}

// ── Schritt 1: Beide Ports prüfen ────────────────────────────────────────────
$portA_ok = checkPort(3004); // → PORT_A anpassen
$portB_ok = checkPort(3005); // → PORT_B anpassen
$log = "[$ts] IP: $ip - PortA(3004)=" . ($portA_ok ? "OK" : "DEAD")
     . " PortB(3005)=" . ($portB_ok ? "OK" : "DEAD");
file_put_contents($logFile, $log . "\n", FILE_APPEND);

// ── Schritt 2: Wenn mindestens ein Port antwortet → alles gut ────────────────
if ($portA_ok || $portB_ok) {
    echo "Status: OK\n";
    exit(0);
}

// ── Schritt 3: NOTFALL – Beide Ports tot → Orchestrator neu starten ──────────
file_put_contents($logFile, "[$ts] EMERGENCY: Both ports dead. Restarting orchestrator.\n", FILE_APPEND);
echo "EMERGENCY: Both ports dead. Restarting...\n";

// Alte PM2-Prozesse bereinigen
$out = shell_exec($env . "$pm2 delete prod-A prod-B orchestrator 2>&1; true");
file_put_contents($logFile, "[$ts] Cleanup: $out\n", FILE_APPEND);

// Flag-Datei entfernen → Apache fällt auf Port A zurück
if (file_exists($flagB)) unlink($flagB);

// Orchestrator starten (er kümmert sich um den Rest)
$startOrch = $env . "NODE_ENV=production $pm2 start $orchFile "
           . "--name orchestrator "
           . "--kill-timeout 30000 "
           . "2>&1";
$out = shell_exec($startOrch);
file_put_contents($logFile, "[$ts] Orchestrator start: $out\n", FILE_APPEND);
echo "Orchestrator restarted: $out\n";

$saveOut = shell_exec($env . "$pm2 save --force 2>&1");
file_put_contents($logFile, "[$ts] PM2 save: $saveOut\n", FILE_APPEND);
echo "Emergency restart done.\n";
?>
