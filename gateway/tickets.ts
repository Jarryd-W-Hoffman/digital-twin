import { createHash, createHmac, timingSafeEqual } from 'node:crypto';

/**
 * Browser tickets, signed by the control plane (app/Actions/IssueGatewayTicket.php).
 * The HMAC key is the SHA-256 of the gateway token: the control plane stores
 * only that hash, and the gateway can compute it from the token it holds.
 */
export interface TicketAccess {
  user: string;
  operate: boolean;
}

export const ticketKey = (token: string): string => createHash('sha256').update(token).digest('hex');

export function verifyTicket(
  ticket: string | null | undefined,
  key: string,
  twinId: string,
  nowMs = Date.now(),
): TicketAccess | null {
  const parts = (ticket ?? '').split('.');
  if (parts.length !== 2 || !parts[0] || !parts[1]) return null;
  const [payload, signature] = parts as [string, string];

  const expected = createHmac('sha256', key).update(payload).digest();
  const given = Buffer.from(signature, 'base64url');
  if (given.length !== expected.length || !timingSafeEqual(given, expected)) return null;

  let data: unknown;
  try {
    data = JSON.parse(Buffer.from(payload, 'base64url').toString('utf8'));
  } catch {
    return null;
  }
  if (typeof data !== 'object' || data === null) return null;
  const { twin, user, operate, exp } = data as Record<string, unknown>;
  if (twin !== twinId || typeof user !== 'string' || typeof operate !== 'boolean') return null;
  if (typeof exp !== 'number' || exp * 1000 <= nowMs) return null;
  return { user, operate };
}
