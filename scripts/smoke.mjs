import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import assert from 'node:assert/strict';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const env = Object.fromEntries(readFileSync(resolve(root, '.env'), 'utf8').split(/\r?\n/).filter(x => /^[A-Z_]+=/.test(x)).map(x => { const i = x.indexOf('='); return [x.slice(0, i), x.slice(i + 1)]; }));
const base = process.env.ACCESSPOLICY_BASE_URL || 'http://127.0.0.1:8102';
if (!/^http:\/\/127\.0\.0\.1:[0-9]+$/.test(base)) throw new Error('Smoke test only accepts a loopback API URL.');
const evidence = [];
async function call(method, path, expected, token, body) {
  const response = await fetch(base + path, { method, headers: { Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}), ...(body === undefined ? {} : { 'Content-Type': 'application/json' }) }, ...(body === undefined ? {} : { body: JSON.stringify(body) }), signal: AbortSignal.timeout(15000) });
  const value = response.status === 204 ? null : await response.json();
  assert.equal(response.status, expected, `${method} ${path}: unexpected status ${response.status}`);
  evidence.push({ method, path, status: response.status });
  return value;
}
async function login(name, key) {
  return (await call('POST', '/api/v1/auth/login', 201, null, { email: `${name}@accesspolicy.test`, password: env[key] })).data.token;
}
await call('GET', '/health', 200);
assert.equal((await call('GET', '/openapi.json', 200)).openapi, '3.0.3');
await call('GET', '/api/v1/me', 401);
const owner = await login('owner', 'DEMO_OWNER_PASSWORD');
const admin = await login('admin', 'DEMO_ADMIN_PASSWORD');
const member = await login('member', 'DEMO_MEMBER_PASSWORD');
const me = (await call('GET', '/api/v1/me', 200, member)).data;
const orgs = (await call('GET', '/api/v1/orgs', 200, member)).data;
const atlas = orgs.find(x => x.slug === 'atlas').id;
const birch = orgs.find(x => x.slug === 'birch').id;
await call('GET', `/api/v1/orgs/${birch}/members`, 404, owner);
const members = (await call('GET', `/api/v1/orgs/${atlas}/members?limit=50`, 200, admin)).data;
const target = members.find(x => Number(x.userId) === Number(me.id));
if (target.role !== 'member') throw new Error('Smoke expects the synthetic Atlas member role. No mutation was performed.');
await call('PATCH', `/api/v1/orgs/${atlas}/members/${me.id}`, 403, member, { role: 'owner', expectedVersion: target.version });
const changed = (await call('PATCH', `/api/v1/orgs/${atlas}/members/${me.id}`, 200, admin, { role: 'viewer', expectedVersion: target.version })).data;
assert.equal(changed.tokensRevoked >= 1, true);
await call('GET', '/api/v1/me', 401, member);
await call('PATCH', `/api/v1/orgs/${atlas}/members/${me.id}`, 409, admin, { role: 'member', expectedVersion: target.version });
await call('PATCH', `/api/v1/orgs/${atlas}/members/${me.id}`, 200, admin, { role: 'member', expectedVersion: changed.version });
const audit = await call('GET', `/api/v1/orgs/${atlas}/audit?limit=50`, 200, owner);
assert.equal(audit.data.some(x => x.action === 'membership.role_changed'), true);
for (const secret of [owner, admin, member, env.DEMO_OWNER_PASSWORD, env.DEMO_ADMIN_PASSWORD, env.DEMO_MEMBER_PASSWORD]) assert.equal(JSON.stringify(audit).includes(secret), false);
await call('POST', '/api/v1/auth/logout', 204, owner, {});
await call('POST', '/api/v1/auth/logout', 204, admin, {});
mkdirSync(resolve(root, '.local'), { recursive: true });
writeFileSync(resolve(root, '.local/http-smoke.json'), JSON.stringify({ checkedAt: new Date().toISOString(), requests: evidence, note: 'Synthetic Atlas member role restored; version and audit history intentionally retained.' }, null, 2));
console.log(`HTTP smoke passed: ${evidence.length} requests. Secrets omitted. Evidence: .local/http-smoke.json`);
