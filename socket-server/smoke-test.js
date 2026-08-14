import { io } from "socket.io-client";

const url = process.env.REALTIME_SERVER_URL ?? "http://127.0.0.1:3001";
const secret = process.env.REALTIME_SECRET;
const socket = io(url);

const timeout = setTimeout(() => {
    console.error("Timed out waiting for users.kpi.updated");
    socket.disconnect();
    process.exit(1);
}, 5_000);

socket.on("connect", async () => {
    const response = await fetch(`${url}/publish/users-kpi`, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-Realtime-Secret": secret,
        },
        body: JSON.stringify({ users: 42 }),
    });

    if (!response.ok) {
        console.error(`Publish failed with HTTP ${response.status}`);
        clearTimeout(timeout);
        socket.disconnect();
        process.exit(1);
    }
});

socket.on("users.kpi.updated", (payload) => {
    if (payload.users !== 42) {
        console.error(`Unexpected payload: ${JSON.stringify(payload)}`);
        process.exitCode = 1;
    } else {
        console.log("Received users.kpi.updated with users=42");
    }

    clearTimeout(timeout);
    socket.disconnect();
});
