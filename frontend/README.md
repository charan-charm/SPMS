# SPMS Frontend (F1 — React Foundation)

React SPA foundation for SPMS. This is **infrastructure only** — no SPMS
product pages exist yet (see `docs/09_DEVELOPMENT_PLAN.md`, F1–F5).

Stack (locked): React + Vite + Tailwind CSS + Motion + shadcn/ui (where
appropriate) + Lucide + Recharts + React Router + centralized `fetch()`
API client. Node.js is build tooling only; the SPMS backend remains PHP.

## Run

```bash
npm install
npm run dev      # http://localhost:5173 — /api/* proxies to the PHP backend
npm run build    # production build → dist/
npm run lint
```

API base path: `VITE_API_BASE_URL` (default `/api/v1`). Dev proxy target:
`VITE_DEV_PROXY_TARGET` (default `http://127.0.0.1:8080`). Only `VITE_*`
public configuration is exposed — never server secrets.

## Structure (docs/11 §4)

```text
src/
  app/       application wiring: router, theme provider
  components/  ui/ · navigation/ · feedback/ · data-display/
  layouts/   page layouts (F3)
  pages/     public/ · auth/ · app/ (route targets)
  features/  domain feature components (F2+)
  charts/    shared SPMS chart components (F2+)
  hooks/     shared hooks (useTheme)
  services/api/  centralized API client foundation
  lib/       shared utilities (cn)
  styles/    design tokens + Tailwind entry
```

The current route targets are explicitly marked **foundation placeholders**
so that routing, tokens, and theme behavior can be verified before the
design-system (F2) and application-shell (F3) milestones.
