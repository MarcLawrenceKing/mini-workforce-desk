# Task 10 Guide: Realtime Users KPI with Socket.IO

This task adds one deliberately small realtime feature: when a new user is
created, an already-open `/dashboard` page updates its **Users Count** card
without reloading the page.

## What a socket is (in plain language)

A normal browser request is short-lived: the browser asks Laravel for a page or
data, Laravel replies, and the connection is finished. A socket stays connected.
That lets the server push a small message to the browser as soon as something
changes.

This example has three participants:

```text
UserController                 Socket.IO server              /dashboard in Vue
creates a user  -- HTTP POST -> receives users count -- push event -> updates card
```

The Socket.IO server runs locally on port `3001`. Laravel still runs on port
`8000`, and Vite normally runs on port `5173`.

## Scope

Only one event is used:

- Event name: `users.kpi.updated`
- Event payload: `{ "users": 7 }`
- Trigger: a user is successfully created from the Users page
- Listener: `resources/js/Pages/AdminDashboard.vue`

The event contains only the new total. It does not contain a user's name,
email, password, or any other private information.

## Files used by the feature

- `socket-server/server.js` - the local Socket.IO server
- `config/services.php` - Laravel's realtime server settings
- `app/Services/RealtimeUsersKpi.php` - sends the count from Laravel
- `app/Http/Controllers/UserController.php` - publishes after user creation
- `resources/js/Pages/AdminDashboard.vue` - listens and updates the card
- `.env.example` - documents local environment variables
- `README.md` - documents the start commands

## Environment variables

Add these values to `.env` (the example file already contains them):

```dotenv
REALTIME_ENABLED=true
REALTIME_SERVER_URL=http://127.0.0.1:3001
REALTIME_SECRET=local-realtime-secret
VITE_SOCKET_URL=http://127.0.0.1:3001
```

`REALTIME_SECRET` is a shared secret. Laravel sends it in the
`X-Realtime-Secret` header, and the socket server rejects publish requests that
do not have the same value. Change it if this server is exposed beyond your own
computer.

Variables beginning with `VITE_` are readable by browser JavaScript. The secret
therefore must **not** use the `VITE_` prefix.

After changing `.env`, restart Laravel and Vite. If configuration was cached,
run:

```bash
php artisan config:clear
```

## Starting the feature locally

Install the JavaScript dependencies once (or after pulling these changes):

```bash
npm install
```

Open three terminals in the project directory:

```bash
# Terminal 1: Laravel
php artisan serve

# Terminal 2: Vue/Vite
npm run dev

# Terminal 3: Socket.IO
npm run socket
```

Then sign in as an admin and open `http://127.0.0.1:8000/dashboard`.

## Manual test

1. Keep `/dashboard` open in one browser tab.
2. Open `/users` in another tab.
3. Create a user.
4. Return to the dashboard tab. The Users Count should already be one higher.
5. The small realtime status beside the count should say `Live` while the
   socket is connected.

The socket server terminal also prints a line when it broadcasts the new count.

## Failure behavior

Realtime is an enhancement, not a requirement for creating users. If the socket
server is stopped, user creation still succeeds. Laravel records the failed
publish in its log, and the dashboard will show the correct database count on
its next normal page load.

## Why this example does not use Redis

Redis is helpful when several application or socket-server processes need to
share events. This local demo has one Laravel process and one Socket.IO process,
so a direct local HTTP publish is easier to understand and is enough for Task
10. Redis can be added later without changing the Vue event name or payload.
