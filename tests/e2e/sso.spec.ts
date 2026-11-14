import { createServer, type Server } from 'node:http';
import { expect, test, type Page } from '@playwright/test';

/**
 * Single sign-on against a mock OpenID Connect provider on this machine: it
 * signs in whoever the login hint names, at once. Runs on the seeded e2e app.
 */

// The second test needs the connection the first one makes.
test.describe.configure({ mode: 'serial' });

const port = 8797;
const issuer = `http://127.0.0.1:${port}`;
let idp: Server;
const nonces = new Map<string, { nonce: string; email: string }>();

const encode = (part: object) => Buffer.from(JSON.stringify(part)).toString('base64url');

test.beforeAll(async () => {
  idp = createServer((req, res) => {
    const url = new URL(req.url ?? '/', issuer);

    if (url.pathname === '/.well-known/openid-configuration') {
      res.writeHead(200, { 'Content-Type': 'application/json' }).end(
        JSON.stringify({ issuer, authorization_endpoint: `${issuer}/authorize`, token_endpoint: `${issuer}/token` }),
      );
      return;
    }

    if (url.pathname === '/authorize') {
      const code = `code-${nonces.size + 1}`;
      nonces.set(code, { nonce: url.searchParams.get('nonce') ?? '', email: url.searchParams.get('login_hint') ?? '' });
      const back = new URL(url.searchParams.get('redirect_uri') ?? '');
      back.searchParams.set('code', code);
      back.searchParams.set('state', url.searchParams.get('state') ?? '');
      res.writeHead(302, { Location: back.toString() }).end();
      return;
    }

    if (url.pathname === '/token' && req.method === 'POST') {
      let body = '';
      req.on('data', (chunk: Buffer) => (body += chunk.toString()));
      req.on('end', () => {
        const form = new URLSearchParams(body);
        const grant = nonces.get(form.get('code') ?? '');
        const basic = Buffer.from((req.headers.authorization ?? '').replace('Basic ', ''), 'base64').toString();
        if (!grant || basic !== 'meridian:idp-secret' || !form.get('code_verifier')) {
          res.writeHead(400, { 'Content-Type': 'application/json' }).end(JSON.stringify({ error: 'invalid_grant' }));
          return;
        }
        const now = Math.floor(Date.now() / 1000);
        const claims = {
          iss: issuer,
          aud: 'meridian',
          sub: `sub-${grant.email}`,
          email: grant.email,
          email_verified: true,
          name: grant.email === 'ana@harbour.example' ? 'Ana Ruiz' : undefined,
          nonce: grant.nonce,
          iat: now,
          exp: now + 300,
        };
        res.writeHead(200, { 'Content-Type': 'application/json' }).end(
          JSON.stringify({ access_token: 'a', token_type: 'Bearer', id_token: `${encode({ alg: 'RS256' })}.${encode(claims)}.sig` }),
        );
      });
      return;
    }

    res.writeHead(404).end();
  });
  await new Promise<void>((done) => idp.listen(port, '127.0.0.1', done));
});

test.afterAll(async () => {
  await new Promise<void>((done) => idp.close(() => done()));
});

async function logIn(page: Page, email: string) {
  await page.context().clearCookies();
  await page.goto('/login');
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password', { exact: true }).fill('password');
  await page.getByRole('button', { name: 'Log in' }).click();
  await expect(page).toHaveURL(/\/projects$/);
}

async function signInWithSso(page: Page, email: string) {
  await page.context().clearCookies();
  await page.goto('/login');
  await page.getByRole('link', { name: 'Sign in with single sign-on' }).click();
  await page.getByLabel('Work email address').fill(email);
  await page.getByRole('button', { name: 'Continue' }).click();
}

test('an owner sets up single sign-on; a newcomer joins through it, and a member links their account', async ({ page }) => {
  await logIn(page, 'test@example.com');
  await page.getByRole('link', { name: 'Single sign-on' }).click();
  await expect(page.getByLabel('Redirect URI')).toHaveValue('http://127.0.0.1:8199/sso/callback');
  await page.getByLabel('Issuer').fill(issuer);
  await page.getByLabel('Client ID').fill('meridian');
  await page.getByLabel('Client secret').fill('idp-secret');
  await page.getByLabel('Email domains').fill('harbour.example, example.com');
  await page.getByLabel('Add people signing in for the first time as').check();
  await page.getByLabel('Role for new people').selectOption({ label: 'Operator' });
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('0 people sign in through it.')).toBeVisible();

  // Someone new joins as an operator.
  await signInWithSso(page, 'ana@harbour.example');
  await expect(page).toHaveURL(/\/projects$/);
  await expect(page.getByText('Ana Ruiz').first()).toBeVisible();
  await expect(page.getByText('Northshore Logistics').first()).toBeVisible();

  // Someone with an account is told to link it, then does.
  await signInWithSso(page, 'viewer@example.com');
  await expect(page.getByText('You already have a Meridian account.')).toBeVisible();
  await logIn(page, 'viewer@example.com');
  await page.goto('/settings/sso');
  await page.getByRole('button', { name: 'Link Northshore Logistics' }).click();
  await expect(page.getByRole('list', { name: 'Linked identities' })).toContainText('viewer@example.com');
  await signInWithSso(page, 'viewer@example.com');
  await expect(page).toHaveURL(/\/projects$/);
  await expect(page.getByText('Viewer User').first()).toBeVisible();

  // An address no organisation signs in.
  await signInWithSso(page, 'someone@elsewhere.example');
  await expect(page.getByText('No organisation signs in elsewhere.example through single sign-on.')).toBeVisible();

  await logIn(page, 'test@example.com');
  await page.getByRole('link', { name: 'Audit log' }).click();
  const log = page.getByRole('table', { name: 'Audit log' });
  await expect(log.getByRole('row', { name: /Meridian.*Added a member: ana@harbour\.example/ })).toContainText('via: single sign-on');
  await expect(log.getByRole('row', { name: /Viewer User.*Linked single sign-on: viewer@example\.com/ })).toBeVisible();
  await expect(log.getByRole('row', { name: /Test User.*Set up single sign-on/ })).toBeVisible();
  await page.getByRole('link', { name: 'Single sign-on' }).click();
  await expect(page.getByText('2 people sign in through it.')).toBeVisible();
});

test('an organisation that requires single sign-on sends a member signed in with a password through it, but not an owner', async ({ page }) => {
  await logIn(page, 'test@example.com');
  await page.getByRole('link', { name: 'Single sign-on' }).click();
  await page.getByLabel('Require single sign-on for everyone but owners').check();
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByLabel('Require single sign-on for everyone but owners')).toBeChecked();

  try {
    // The operator signs in with a password, and is sent through the provider before working.
    await page.context().clearCookies();
    await page.goto('/login');
    await page.getByLabel('Email address').fill('operator@example.com');
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.getByRole('heading', { name: 'Single sign-on required' })).toBeVisible();
    await page.getByRole('button', { name: 'Continue with single sign-on' }).click();
    await expect(page).toHaveURL(/\/projects$/);
    await expect(page.getByRole('link', { name: /^Pilot sites/ })).toBeVisible();

    // The owner still comes in with a password.
    await logIn(page, 'test@example.com');
    await expect(page.getByRole('link', { name: /^Pilot sites/ })).toBeVisible();
  } finally {
    await logIn(page, 'test@example.com');
    await page.goto('/organisation/sso');
    await page.getByLabel('Require single sign-on for everyone but owners').uncheck();
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByLabel('Require single sign-on for everyone but owners')).not.toBeChecked();
  }
});
