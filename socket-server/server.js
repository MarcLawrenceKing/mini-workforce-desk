import { createServer } from "node:http";
import { Server } from "socket.io";

const port = Number(process.env.SOCKET_PORT ?? 3001);
const secret = process.env.REALTIME_SECRET;

if (!secret) {
    console.error("REALTIME_SECRET is missing. Add it to .env before starting the socket server.");
    process.exit(1);
}

const httpServer = createServer((request, response) => {
    if (request.method === "GET" && request.url === "/health") {
        return sendJson(response, 200, { ok: true });
    }

    if (request.method !== "POST" || request.url !== "/publish/users-kpi") {
        return sendJson(response, 404, { message: "Not found" });
    }

    if (request.headers["x-realtime-secret"] !== secret) {
        return sendJson(response, 401, { message: "Invalid realtime secret" });
    }

    let body = "";

    request.on("data", (chunk) => {
        body += chunk;

        if (body.length > 10_000) {
            request.destroy();
        }
    });

    request.on("end", () => {
        try {
            const payload = JSON.parse(body);

            if (!Number.isInteger(payload.users) || payload.users < 0) {
                return sendJson(response, 422, { message: "users must be a non-negative integer" });
            }

            io.emit("users.kpi.updated", { users: payload.users });
            console.log(`Broadcast users.kpi.updated: ${payload.users}`);

            return sendJson(response, 202, { emitted: true });
        } catch {
            return sendJson(response, 400, { message: "Invalid JSON" });
        }
    });
});

const io = new Server(httpServer, {
    cors: {
        origin: ["http://127.0.0.1:8000", "http://localhost:8000"],
        methods: ["GET", "POST"],
    },
});

io.on("connection", (socket) => {
    console.log(`Dashboard connected: ${socket.id}`);

    socket.on("disconnect", () => {
        console.log(`Dashboard disconnected: ${socket.id}`);
    });
});

httpServer.listen(port, "127.0.0.1", () => {
    console.log(`Socket.IO server listening at http://127.0.0.1:${port}`);
});

function sendJson(response, status, payload) {
    response.writeHead(status, { "Content-Type": "application/json" });
    response.end(JSON.stringify(payload));
}
