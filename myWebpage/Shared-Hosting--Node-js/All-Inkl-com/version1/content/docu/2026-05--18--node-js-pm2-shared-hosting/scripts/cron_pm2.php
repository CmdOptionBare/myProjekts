<?php
/**
 * Emergency Watchdog for DEINE-DOMAIN
 * ================================================
 * This script is the LAST LINE OF DEFENSE.
 * It only intervenes when BOTH ports (3004 + 3005) are down
 * AND the orchestrator is also dead.
 * The normal flip-flop rotation is handled by orchestrator.js (via PM2).
 */

$appDir    = "/www/htdocs/ACCOUNT_ID/DEINE-DOMAIN/app/.next/standalone";
$orchFile  = "/www/htdocs/ACCOUNT_ID/DEINE-DOMAIN/orchestrator.js";
$flagB     = "/www/htdocs/ACCOUNT_ID/DEINE-DOMAIN/port_b_active.flag";
$pm2       = "/www/htdocs/ACCOUNT_ID/nodejs_current/bin/pm2";
$homeDir   = "/www/htdocs/ACCOUNT_ID";
$env       = "export HOME=$homeDir; export PATH=/www/htdocs/ACCOUNT_ID/nodejs_current/bin:\$PATH; ";

// Daily log rotation (max 31 days)
$logDir = __DIR__ . "/watchdog_logs";
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$today    = date("Y-m-d");
$logFile  = "$logDir/watchdog-$today.log";
$ts       = date("Y-m-d H:i:s");
$ip       = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : "CLI";

// Log rotation: keep max 31 files
$files = glob("$logDir/watchdog-*.log");
if (is_array($files) && count($files) > 31) {
  rsort($files);
  foreach (array_slice($files, 31) as $f) { if (is_file($f)) unlink($f); }
}

// --- Step 1: Check both ports ---
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

// --- Step 2: If at least one port responds, we are OK ---
if ($portA_ok || $portB_ok) {
  echo "Status: OK\n";
  exit(0);
}

// --- Step 3: EMERGENCY - Both ports are dead, restart everything ---
file_put_contents($logFile, "[$ts] EMERGENCY: Both ports dead. Restarting orchestrator.\n", FILE_APPEND);
echo "EMERGENCY: Both ports dead. Restarting...\n";

// Delete any dead state individually so PM2 never aborts early
$outA = shell_exec($env . "$pm2 delete prod-A 2>&1; true");
$outB = shell_exec($env . "$pm2 delete prod-B 2>&1; true");
$outO = shell_exec($env . "$pm2 delete orchestrator 2>&1; true");
file_put_contents($logFile, "[$ts] Cleanup: A($outA) B($outB) O($outO)\n", FILE_APPEND);

// Remove stale flag file so we start on Port A (3004)
if (file_exists($flagB)) unlink($flagB);

// Start the orchestrator - it handles everything from here
$startOrch = $env . "NODE_ENV=production $pm2 start $orchFile " .
  "--name orchestrator " .
  "--kill-timeout 30000 " .
  "2>&1";
$out = shell_exec($startOrch);
file_put_contents($logFile, "[$ts] Orchestrator start: $out\n", FILE_APPEND);
echo "Orchestrator restarted: $out\n";

$saveOut = shell_exec($env . "$pm2 save --force 2>&1");
file_put_contents($logFile, "[$ts] PM2 save: $saveOut\n", FILE_APPEND);

echo "Emergency restart done.\n";
?>
