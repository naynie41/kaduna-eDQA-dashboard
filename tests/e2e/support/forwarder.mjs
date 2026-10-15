// Run in the background by tests/e2e/run.sh inside the playwright service: E2E_FORWARD
// ("8080=web:80,5173=vite:5173") makes the container's localhost:8080 and :5173 reach the web and
// Vite services, so the browser sees the developer's origins (APP_URL, the CSP, the Vite dev
// server). A process of its own: Playwright's global-setup process does not serve connections
// while the tests run.
import net from 'node:net';

const rules = (process.env.E2E_FORWARD ?? '').split(',').filter((rule) => rule !== '');

for (const rule of rules) {
    const match = /^(\d+)=([\w.-]+):(\d+)$/.exec(rule);
    if (match === null) {
        console.error(`E2E_FORWARD rule "${rule}" is not "localPort=host:port".`);
        process.exit(1);
    }
    const [, local, host, port] = match;

    net.createServer((client) => {
        const upstream = net.connect(Number(port), host);
        client.pipe(upstream).pipe(client);
        client.on('error', () => upstream.destroy());
        upstream.on('error', () => client.destroy());
    }).listen(Number(local), '127.0.0.1');
}
