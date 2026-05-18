# myProjekts

Technische Projekte, Dokumentationen und Konfigurationen.

## Struktur

```
myWebpage/
└── Shared-Hosting--Node-js/
    └── All-Inkl-com/
        └── version1/
            └── content/
                ├── blogs/    – Blog-Artikel (MDX)
                ├── docu/     – Technische Dokumentation (MDX + Skripte)
                ├── news/     – News-Einträge
                └── projects/ – Projekt-Dokumentationen
```

## Inhalt: Node.js auf All-Inkl Shared Hosting

**Ordner:** `myWebpage/Shared-Hosting--Node-js/All-Inkl-com/version1/`

Vollständige, anonymisierte Dokumentation und alle Konfigurationsdateien für den Betrieb einer Node.js / Next.js App auf einem All-Inkl.com Shared-Hosting-Paket.

### Enthaltene Dateien (`2026-05--18--node-js-pm2-shared-hosting/`)

| Datei | Beschreibung |
|---|---|
| `pm2-setup-all-inkl.mdx` | Vollständige technische Dokumentation mit Mermaid-Diagrammen |
| `scripts/orchestrator.js` | Flip-Flop-Orchestrator (Node.js, PM2-gesteuert) |
| `scripts/cron_pm2.php` | PHP-Notfall-Watchdog (via KAS-Cronjob) |
| `scripts/.htaccess` | Apache Reverse Proxy mit Flag-File-Routing |

### Das System auf einen Blick

- **Orchestrator** rotiert alle 7:30 Min. zwischen zwei Node.js-Instanzen (Ports 3004/3005)
- **`.htaccess`** routet via `port_b_active.flag` – immer erst nach erfolgreichem Health-Check
- **PHP-Watchdog** ist der Notfall-Defibrillator – greift nur ein, wenn beide Ports tot sind
- **Graceful Drain** (30s) schützt laufende Downloads/Streams beim Instanzwechsel

> **Hinweis:** Alle Pfade (`ACCOUNT_ID`, `DEINE-DOMAIN`) und IP-Adressen sind anonymisiert. Vor der Nutzung die Platzhalter durch eigene Werte ersetzen.

## Lizenz

MIT – Nutzung und Weiterentwicklung ausdrücklich erwünscht.
