import { spawn, spawnSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { randomBytes } from 'node:crypto';

const runDirectory = resolve('storage/app/playwright', String(Date.now()));
mkdirSync(runDirectory, { recursive: true });
const database = resolve(runDirectory, 'central.sqlite');
writeFileSync(database, '');
const env = {
    ...process.env,
    APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: 'http://127.0.0.1:8015',
    APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
    APP_CONFIG_CACHE: resolve(runDirectory, 'config.php'),
    DB_CONNECTION: 'sqlite', DB_DATABASE: database, DB_URL: '',
    CENTRAL_DB_DRIVER: 'sqlite', TENANCY_CENTRAL_CONNECTION: 'central',
    SESSION_DRIVER: 'file', SESSION_COOKIE: 'playwright_session', SESSION_DOMAIN: '', SESSION_SECURE_COOKIE: 'false',
    CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array',
    BCRYPT_ROUNDS: '4', LOG_CHANNEL: 'single', BROWSER_RUN_DIRECTORY: runDirectory,
};
const setup = spawnSync('php', ['tests/Browser/setup.php'], { env, stdio: 'inherit' });
if (setup.status !== 0) process.exit(setup.status ?? 1);
const server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', '--port=8015', '--no-reload'], { env, stdio: 'inherit' });
server.on('exit', code => process.exit(code ?? 0));
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => { server.kill(); process.exit(0); });
