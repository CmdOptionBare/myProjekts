#!/usr/bin/env node
/**
 * Flip-Flop Orchestrator for DEINE-DOMAIN
 * =====================================================
 * Rotates between 2 Next.js instances every 7.5 minutes (450 seconds)
 * Uses flag-file signaling so .htaccess only routes to a ready instance.
 * Graceful shutdown (SIGTERM + 30s drain) preserves active connections.
 */

const { execSync } = require("child_process");
const http = require("http");
const fs = require("fs");

// --- Configuration ---
const WEBROOT    = "/www/htdocs/ACCOUNT_ID/DEINE-DOMAIN";
const APPDIR     = WEBROOT + "/app/.next/standalone";
const PM2        = "/www/htdocs/ACCOUNT_ID/nodejs_current/bin/pm2";
const HOME       = "/www/htdocs/ACCOUNT_ID";
const ENV_PREFIX = "export HOME=" + HOME + "; export PATH=/www/htdocs/ACCOUNT_ID/nodejs_current/bin:$PATH; ";

const PORT_A     = 3004;
const PORT_B     = 3005;
const NAME_A     = "prod-A";
const NAME_B     = "prod-B";
const FLAG_B     = WEBROOT + "/port_b_active.flag";

const ROTATE_INTERVAL_MS = 7.5 * 60 * 1000; // 7 minutes 30 seconds
const HEALTH_TIMEOUT_MS  = 15 * 1000;        // 15 seconds to wait for new instance to be ready
const DRAIN_TIMEOUT_MS   = 30 * 1000;        // 30 seconds graceful drain for old instance

// --- Helpers ---
function log(msg) {
  const now = new Date().toISOString().replace("T", " ").substring(0, 19);
  const line = "[" + now + "] [ORCH] " + msg + "\n";
  process.stdout.write(line);
  fs.appendFileSync(
    WEBROOT + "/watchdog_logs/orchestrator-" + new Date().toISOString().substring(0, 10) + ".log",
    line
  );
}

function pm2start(name, port) {
  const cmd = ENV_PREFIX + "PORT=" + port + " HOSTNAME=127.0.0.1 " + PM2 + " start " + APPDIR + "/server.js --name " + name + " --kill-timeout 30000 2>&1";
  return execSync(cmd).toString().trim();
}

function pm2stop(name) {
  try { execSync(ENV_PREFIX + PM2 + " stop " + name + " --silent 2>/dev/null; true"); } catch (_) {}
}

function pm2delete(name) {
  try { execSync(ENV_PREFIX + PM2 + " delete " + name + " --silent 2>/dev/null; true"); } catch (_) {}
}

function pm2save() {
  try { execSync(ENV_PREFIX + PM2 + " save --force --silent 2>/dev/null; true"); } catch (_) {}
}

function checkPort(port, cb) {
  const req = http.get({ hostname: "127.0.0.1", port, path: "/", timeout: 4000 }, (res) => {
    cb(res.statusCode >= 200 && res.statusCode < 500);
  });
  req.on("error", () => cb(false));
  req.on("timeout", () => { req.destroy(); cb(false); });
}

function activeBPort() {
  return fs.existsSync(FLAG_B);
}

// --- Startup: ensure one instance is running ---
function ensureStarted() {
  const useB = activeBPort();
  const activeName = useB ? NAME_B : NAME_A;
  const activePort = useB ? PORT_B : PORT_A;
  log("Startup: active slot is " + activeName + " on port " + activePort);

  checkPort(activePort, (alive) => {
    if (alive) {
      log("Instance " + activeName + " is already responding. Scheduling rotation.");
    } else {
      log("Instance " + activeName + " not responding. Starting it now on port " + activePort);
      pm2delete(activeName);
      try {
        pm2start(activeName, activePort);
        log("Started " + activeName + " on port " + activePort);
      } catch (e) {
        log("ERROR starting " + activeName + ": " + e.message);
      }
    }
    setTimeout(rotate, ROTATE_INTERVAL_MS);
  });
}

// --- Core rotation logic ---
function rotate() {
  const currentlyB = activeBPort();
  const incomingName = currentlyB ? NAME_A : NAME_B;
  const incomingPort = currentlyB ? PORT_A : PORT_B;
  const outgoingName = currentlyB ? NAME_B : NAME_A;
  const outgoingPort = currentlyB ? PORT_B : PORT_A;

  log("ROTATION START: " + outgoingName + ":" + outgoingPort + " -> " + incomingName + ":" + incomingPort);

  // 1. Start incoming instance
  pm2delete(incomingName);
  try {
    pm2start(incomingName, incomingPort);
    log("Started incoming instance " + incomingName + " on port " + incomingPort);
  } catch (e) {
    log("ERROR: Could not start " + incomingName + ": " + e.message + ". Keeping current instance.");
    setTimeout(rotate, ROTATE_INTERVAL_MS);
    return;
  }

  // 2. Wait for incoming instance to be healthy
  const deadline = Date.now() + HEALTH_TIMEOUT_MS;
  function waitForReady() {
    if (Date.now() > deadline) {
      log("WARN: " + incomingName + " did not become healthy in time. Aborting rotation, keeping " + outgoingName);
      pm2delete(incomingName);
      setTimeout(rotate, ROTATE_INTERVAL_MS);
      return;
    }
    checkPort(incomingPort, (alive) => {
      if (!alive) {
        setTimeout(waitForReady, 1000);
        return;
      }
      // 3. Switch flag file atomically -> .htaccess now routes to new instance
      if (currentlyB) {
        if (fs.existsSync(FLAG_B)) fs.unlinkSync(FLAG_B); // back to A
      } else {
        fs.writeFileSync(FLAG_B, incomingPort.toString()); // switch to B
      }
      log("FLAG switched: traffic now going to " + incomingName + " on port " + incomingPort);

      // 4. Graceful drain: give outgoing instance 30s to finish open connections
      setTimeout(() => {
        log("Drain complete. Stopping outgoing instance " + outgoingName);
        pm2stop(outgoingName);
        pm2delete(outgoingName);
        pm2save();
        log("ROTATION COMPLETE. Active: " + incomingName + ":" + incomingPort);
        setTimeout(rotate, ROTATE_INTERVAL_MS);
      }, DRAIN_TIMEOUT_MS);
    });
  }
  waitForReady();
}

// --- Entry point ---
log("Orchestrator starting up.");
ensureStarted();
