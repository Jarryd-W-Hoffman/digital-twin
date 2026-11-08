import { readFileSync } from 'node:fs';
import { createHmac } from 'node:crypto';
import { describe, expect, it } from 'vitest';
import { ticketKey, verifyTicket } from './tickets.ts';

const fixture = JSON.parse(readFileSync('tests/fixtures/gateway-ticket.json', 'utf8')) as {
  token: string;
  twin: string;
  user: string;
  operate: boolean;
  edit: boolean;
  issuedAt: number;
  ticket: string;
};
const key = ticketKey(fixture.token);
const at = fixture.issuedAt * 1000;

describe('gateway tickets', () => {
  it('accepts a ticket the control plane signed', () => {
    expect(verifyTicket(fixture.ticket, key, fixture.twin, at)).toEqual({ user: fixture.user, operate: false, edit: false });
  });

  it('refuses it once expired, for another twin, or with another token', () => {
    expect(verifyTicket(fixture.ticket, key, fixture.twin, at + 12 * 3600 * 1000)).toBeNull();
    expect(verifyTicket(fixture.ticket, key, 'another-twin', at)).toBeNull();
    expect(verifyTicket(fixture.ticket, ticketKey('mgw_other'), fixture.twin, at)).toBeNull();
  });

  it('refuses tampered or malformed tickets', () => {
    const [payload] = fixture.ticket.split('.') as [string];
    const raised = Buffer.from(
      Buffer.from(payload, 'base64url').toString().replace('"operate":false', '"operate":true'),
    ).toString('base64url');
    expect(verifyTicket(`${raised}.${fixture.ticket.split('.')[1]}`, key, fixture.twin, at)).toBeNull();
    for (const bad of [null, undefined, '', 'abc', 'a.b.c', `${payload}.`, '.sig']) {
      expect(verifyTicket(bad, key, fixture.twin, at)).toBeNull();
    }
    const sign = (body: string) => {
      const p = Buffer.from(body).toString('base64url');
      return `${p}.${createHmac('sha256', key).update(p).digest('base64url')}`;
    };
    expect(verifyTicket(sign('not json'), key, fixture.twin, at)).toBeNull();
    expect(verifyTicket(sign('null'), key, fixture.twin, at)).toBeNull();
    expect(verifyTicket(sign(JSON.stringify({ twin: fixture.twin, user: 1, operate: true, exp: 9e9 })), key, fixture.twin, at)).toBeNull();
    expect(verifyTicket(sign(JSON.stringify({ twin: fixture.twin, user: 'u', operate: true })), key, fixture.twin, at)).toBeNull();
    expect(verifyTicket(sign(JSON.stringify({ twin: fixture.twin, user: 'u', operate: true, edit: 'yes', exp: 9e9 })), key, fixture.twin, at)).toBeNull();
    // A ticket from before the edit claim can't edit.
    expect(verifyTicket(sign(JSON.stringify({ twin: fixture.twin, user: 'u', operate: true, exp: 9e9 })), key, fixture.twin, at)).toEqual({ user: 'u', operate: true, edit: false });
  });
});
