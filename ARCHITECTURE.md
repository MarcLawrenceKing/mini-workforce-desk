# Architecture

Four ways the browser and the server talk to each other.

```text
                          ┌──────────────────────────────┐
                          │   Browser — Vue 3 + PrimeVue  │
                          └───┬───────┬──────────┬────────┘
                              │       │          │
              (1) Inertia     │  (2) Axios       │  (4) Socket.IO
              page visits     │  JSON calls      │  live events
                              │       │          │
                              ▼       ▼          ▼
                    ┌───────────────────────┐  ┌──────────────────┐
                    │  Laravel (port 8000)  │  │  Node Socket.IO  │
                    │                       │  │   (port 3001)    │
                    │  web.php  → Inertia   │  │                  │
                    │  api.php  → JSON      │◄─┤  receives push   │
                    │  Laratrust + Sanctum  │──┘  from Laravel    │
                    └───┬───────────┬───────┘
                        │           │
                        ▼           ▼  (3) dispatch
                  ┌──────────┐   ┌──────────────────────┐
                  │  MySQL   │   │        Redis         │
                  │          │   │  cache · queue · session │
                  └──────────┘   └──────────┬───────────┘
                                            │
                                            ▼
                                   ┌──────────────────┐
                                   │   Queue worker   │
                                   │  queue:work      │
                                   └──────────────────┘
```

## 1. Web — Inertia

Full pages. The controller returns `Inertia::render()` with props, Vue renders the page.
No manual API call, no client-side router. Authenticated with the session cookie.

Used by: dashboard, users, companies, employees, attendance logs.

## 2. API — Axios + Sanctum

`/api/*` returns JSON. Two kinds of client, one guard:

- **The Vue app** — same browser, already logged in, so its session cookie is enough.
  No token is ever exposed to JavaScript.
- **Postman / curl / mobile** — no session, so they trade credentials for a bearer
  token at `POST /api/login`.

Used by: attendance log CRUD, approvals, CSV export.

## 3. Queue — Redis

Slow work is pushed onto Redis instead of running inside the request. Approving a log
dispatches a job and returns immediately; the worker sends the notification afterwards.

Redis also holds the cached dashboard KPIs (invalidated by a model observer) and sessions.

## 4. Realtime — Socket.IO

A separate Node process. When something changes, Laravel POSTs to it over HTTP with a
shared secret; the socket server broadcasts the event to every connected browser, which
updates in place without a reload.

Publishing is best-effort — a stopped socket server never fails the request that
triggered it.

## Access control

Laratrust roles (`admin`, `company_admin`, `employee`) and `module.action` permissions gate
routes on both `web.php` and `api.php`. A denied request is a **403** — JSON for API
clients, a redirect with a flash message for browser page visits.
