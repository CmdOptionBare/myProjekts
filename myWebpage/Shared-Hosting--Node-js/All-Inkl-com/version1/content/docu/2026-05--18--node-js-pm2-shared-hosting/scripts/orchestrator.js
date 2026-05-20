#!/usr/bin/env node
/**
 * Dual PM2 Ping-Pong Orchestrator for deine-domain.de (Anonymisiert)
 * =================================================================
 */

const { execSync } = require("child_process");
const http = require("http");
const fs = require("fs");

// --- Konfiguration ---
const WEBROOT    = "/www/htdocs/ACCOUNT_ID/DEINE-DOMAIN";
const APPDIR     = WEBROOT + "/app";
const PM2        = "/www/htdocs/ACCOUNT_ID/nodejs_current/bin/pm2";
const HOME       = "/www/htdocs/ACCOUNT_ID";
const PATH_ENV   = "/www/htdocs/ACCOUNT_ID/nodejs_current/bin:" + process.env.PATH;

const PORT_A     = 3004;
const PORT_B     = 3005;
const NAME_A     = "prod-A";
const NAME_B     = "prod-B";
const HOME_A     = HOME + "/.pm2_1";
const HOME_B     = HOME + "/.pm2_2";
const FLAG_B     = WEBROOT + "/port_b_active.flag";

const ROTATE_INTERVAL_MS = 7.5 * 60 * 1000; // 7,5 Minuten
const HEALTH_TIMEOUT_MS  = 30 * 1000;       // 30s Start-Timeout
const DRAIN_TIMEOUT_MS   = 15 * 1000;       // 15s Graceful Drain

const args = process.argv.slice(2);
const isSlotB = args.includes("--slot") && args[args.indexOf("--slot") + 1] === "B";

const selfSlot   = isSlotB ? "B" : "A";
const selfPort   = isSlotB ? PORT_B : PORT_A;
const selfName   = isSlotB ? NAME_B : NAME_A;
const selfHome   = isSlotB ? HOME_B : HOME_A;
const selfOrch   = isSlotB ? "orchestrator_B" : "orchestrator_A";

const otherSlot  = isSlotB ? "A" : "B";
const otherPort  = isSlotB ? PORT_A : PORT_B;
const otherName  = isSlotB ? NAME_A : NAME_B;
const otherHome  = isSlotB ? HOME_A : HOME_B;
const otherOrch  = isSlotB ? "orchestrator_A" : "orchestrator_B";

function log(msg) {
  const now = new Date().toISOString().replace("T", " ").substring(0, 19);
  const line = "[" + now + "] [ORCH_" + selfSlot + "] " + msg + "\n";
  process.stdout.write(line);
  const logDir = HOME + "/watchdog_logs";
  if (!fs.existsSync(logDir)) {
    try { fs.mkdirSync(logDir, { recursive: true, mode: 0o755 }); } catch (_) {}
  }
  const logFile = logDir + "/orchestrator-" + new Date().toISOString().substring(0, 10) + ".log";
  try { fs.appendFileSync(logFile, line); } catch (_) {}
}

/**
 * Bereinigt die Umgebungsvariablen von PM2-internen Werten,
 * um eine saubere Prozess-Isolation der Daemons zu garantieren.
 */
function getCleanEnv(pm2Home) {
  const cleanEnv = {};
  for (const key in process.env) {
    const lowerKey = key.toLowerCase();
    if (
      !lowerKey.startsWith("pm2_") &&
      !lowerKey.startsWith("pm_") &&
      !lowerKey.startsWith("axm_") &&
      !lowerKey.startsWith("npm_") &&
      lowerKey !== "node_app_instance" &&
      lowerKey !== "vizion" &&
      lowerKey !== "name" &&
      lowerKey !== "namespace"
    ) {
      cleanEnv[key] = process.env[key];
    }
  }
  cleanEnv.HOME = HOME;
  cleanEnv.PM2_HOME = pm2Home;
  cleanEnv.PATH = PATH_ENV;
  cleanEnv.NODE_ENV = "production";
  return cleanEnv;
}

function checkPort(port, cb) {
  let done = false;
  const req = http.get({ hostname: "127.0.0.1", port: port, path: "/", timeout: 3000 }, (res) => {
    res.resume();
    if (!done) { done = true; cb(res.statusCode >= 200 && res.statusCode < 500); }
  });
  req.on("error", () => { if (!done) { done = true; cb(false); } });
  req.on("timeout", () => { req.destroy(); if (!done) { done = true; cb(false); } });
}

function startSelf() {
  log("Starting up on Port " + selfPort + " under PM2_HOME " + selfHome);
  
  // Vorbeugende Socket-Bereinigung bei Start
  try {
    if (fs.existsSync(selfHome + "/rpc.sock")) fs.unlinkSync(selfHome + "/rpc.sock");
    if (fs.existsSync(selfHome + "/pub.sock")) fs.unlinkSync(selfHome + "/pub.sock");
  } catch (_) {}

  const env = getCleanEnv(selfHome);
  env.PORT = selfPort.toString();
  env.HOSTNAME = "127.0.0.1";
  
  try {
    const out = execSync(PM2 + " start " + APPDIR + "/server.js --name " + selfName + " --ignore-watch " + HOME + " --kill-timeout 30000 2>&1", { env }).toString().trim();
    log("Started Next.js worker: " + out);
    execSync(PM2 + " save --force", { env, stdio: "ignore" });
  } catch (e) {
    log("ERROR starting worker " + selfName + ": " + e.message);
  }

  const deadline = Date.now() + HEALTH_TIMEOUT_MS;
  function verifyHealthy() {
    checkPort(selfPort, (alive) => {
      if (!alive) {
        if (Date.now() > deadline) {
          log("WARN: Worker not healthy yet. Still checking Port " + selfPort + "...");
        }
        setTimeout(verifyHealthy, 1500);
        return;
      }
      
      // Routing umschalten
      if (selfSlot === "B") {
        try { fs.writeFileSync(FLAG_B, selfPort.toString()); } catch (_) {}
      } else {
        if (fs.existsSync(FLAG_B)) { try { fs.unlinkSync(FLAG_B); } catch (_) {} }
      }
      log("Traffic successfully routed to Slot " + selfSlot + " (Port " + selfPort + ").");

      // Alten Daemon nach Drain-Zeit killen
      log("Waiting 15s for active connections on Slot " + otherSlot + " to drain...");
      setTimeout(() => {
        log("Draining complete. Completely terminating PM2 God Daemon of Slot " + otherSlot + " (" + otherHome + ")...");
        try {
          execSync(PM2 + " kill", { env: getCleanEnv(otherHome), stdio: "ignore" });
          log("Slot " + otherSlot + " PM2 daemon successfully destroyed.");
        } catch (e) {
          log("Note during kill of Slot " + otherSlot + ": " + e.message);
        }

        log("Normal operation. Next rotation scheduled in 7.5 minutes.");
        setTimeout(spawnNextSlot, ROTATE_INTERVAL_MS);
      }, DRAIN_TIMEOUT_MS);
    });
  }
  verifyHealthy();
}

function spawnNextSlot() {
  log("ROTATION TIMER REACHED. Spawning next Slot " + otherSlot + " under PM2_HOME " + otherHome + "...");
  
  // Vorbeugende Socket-Bereinigung für den nächsten Slot
  try {
    if (fs.existsSync(otherHome + "/rpc.sock")) fs.unlinkSync(otherHome + "/rpc.sock");
    if (fs.existsSync(otherHome + "/pub.sock")) fs.unlinkSync(otherHome + "/pub.sock");
  } catch (_) {}

  const orchScript = WEBROOT + "/orchestrator.js";
  const env = getCleanEnv(otherHome);
  
  try {
    const out = execSync(PM2 + " start " + orchScript + " --name " + otherOrch + " --ignore-watch " + HOME + " --kill-timeout 30000 -- --slot " + otherSlot + " 2>&1", { env }).toString().trim();
    log("Successfully spawned next Slot orchestrator: " + out);
    execSync(PM2 + " save --force", { env, stdio: "ignore" });
    log("Handover fully initiated. Waiting to be terminated by Slot " + otherSlot + " once it is fully live.");
  } catch (e) {
    log("ERROR spawning next Slot " + otherSlot + ": " + e.message + ". Will retry in 1 minute.");
    setTimeout(spawnNextSlot, 60 * 1000);
  }
}

startSelf();
setInterval(() => {}, 60000);
