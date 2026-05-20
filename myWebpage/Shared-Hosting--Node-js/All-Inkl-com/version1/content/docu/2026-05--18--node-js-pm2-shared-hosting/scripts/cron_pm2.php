<?php
/**
 * Emergency Watchdog for deine-domain.de (Anonymisiert)
 * ====================================================
 */

$webroot   = "/www/htdocs/ACCOUNT_ID/DEINE-DOMAIN";
$appDir    = $webroot . "/app";
$orchFile  = $webroot . "/orchestrator.js";
$notifier  = $webroot . "/notifier.php";
$flagB     = $webroot . "/port_b_active.flag";
$pm2       = "/www/htdocs/ACCOUNT_ID/nodejs_current/bin/pm2";
$homeDir   = "/www/htdocs/ACCOUNT_ID";
$pathEnv   = "/www/htdocs/ACCOUNT_ID/nodejs_current/bin:" . getenv("PATH");

$homeA     = $homeDir . "/.pm2_1";
$homeB     = $homeDir . "/.pm2_2";

$logDir = "/www/htdocs/ACCOUNT_ID/watchdog_logs";
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$today    = date("Y-m-d");
$logFile  = "$logDir/watchdog-$today.log";
$ts       = date("Y-m-d H:i:s");
$ip       = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : "CLI";

// Log-Rotation (maximal 31 Tage aufheben)
$files = glob("$logDir/watchdog-*.log");
if (is_array($files) && count($files) > 31) {
  rsort($files);
  foreach (array_slice($files, 31) as $f) { if (is_file($f)) unlink($f); }
}

// Täglicher Statusbericht um 23:50 Uhr
$dailyFlag = "$logDir/daily_sent_$today.flag";
if (date("H:i") >= "23:50" && !file_exists($dailyFlag)) {
  file_put_contents($dailyFlag, "1");
  shell_exec("php " . escapeshellarg($notifier) . " daily > /dev/null 2>&1 &");
}

function checkPort($port) {
  $ch = curl_init("http://127.0.0.1:$port/");
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 4);
  curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return $code >= 200 && $code < 500;
}

$portA_ok = checkPort(3004);
$portB_ok = checkPort(3005);
$log = "[$ts] IP: $ip - PortA(3004)=" . ($portA_ok ? "OK" : "DEAD") . " PortB(3005)=" . ($portB_ok ? "OK" : "DEAD");
file_put_contents($logFile, $log . "\n", FILE_APPEND);

if ($portA_ok || $portB_ok) {
  echo "Status: OK\n";
  exit(0);
}

// --- EMERGENCY RESTART ---
file_put_contents($logFile, "[$ts] EMERGENCY: Both ports dead. Executing Dual PM2 reset.\n", FILE_APPEND);
echo "EMERGENCY: Both ports dead. Restarting...\n";

// Notfall-Benachrichtigung anstoßen
shell_exec("php " . escapeshellarg($notifier) . " emergency > /dev/null 2>&1 &");

function makePm2Env($pm2Home) {
  global $homeDir, $pathEnv;
  return "export HOME=$homeDir; export PM2_HOME=$pm2Home; export PATH=$pathEnv; ";
}

// Blockierende Socket-Dateien löschen
@unlink($homeA . "/rpc.sock");
@unlink($homeA . "/pub.sock");
@unlink($homeB . "/rpc.sock");
@unlink($homeB . "/pub.sock");

// Zerstöre beide Daemons hart
$killA = shell_exec(makePm2Env($homeA) . "$pm2 kill 2>&1");
$killB = shell_exec(makePm2Env($homeB) . "$pm2 kill 2>&1");
file_put_contents($logFile, "[$ts] Kill daemons: A($killA) B($killB)\n", FILE_APPEND);

if (file_exists($flagB)) unlink($flagB);

// Starte Slot A sauber neu
$startA = shell_exec(makePm2Env($homeA) . "NODE_ENV=production $pm2 start $orchFile --name orchestrator_A --ignore-watch $homeDir --kill-timeout 30000 -- --slot A 2>&1");
file_put_contents($logFile, "[$ts] Started Slot A: $startA\n", FILE_APPEND);
shell_exec(makePm2Env($homeA) . "$pm2 save --force 2>&1");

echo "Emergency restart done.\n";
?>
