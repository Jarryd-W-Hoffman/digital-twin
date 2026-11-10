import { OPCUACertificateManager } from 'node-opcua-certificate-manager';
import {
  AttributeIds,
  BrowseDirection,
  ClientMonitoredItem,
  DataType,
  type ClientSession,
  type ClientSubscription,
  type DataValue,
  MessageSecurityMode,
  NodeClass,
  OPCUAClient,
  SecurityPolicy,
  TimestampsToReturn,
  type UserIdentityInfo,
  UserTokenType,
  Variant,
} from 'node-opcua-client';
import type { MonitoredNode } from '../resources/js/twin/core/opcua.ts';
import type { OpcUaBrowseResult } from '../resources/js/twin/core/protocol.ts';

/**
 * The gateway's OPC UA side: one client session per server the twin's
 * mappings use, with a subscription monitoring each mapped node. Values are
 * handed to `onValue`; turning them into readings is the core's job.
 *
 * node-opcua reconnects on its own and carries the subscription across.
 * When the twin's mappings or a server's settings change, `follow` adds and
 * drops monitored nodes, and reconnects servers whose settings changed.
 */

/** A server registered in the twin's project, as the control plane sends it. */
export interface OpcUaServerConfig {
  name: string;
  endpoint: string;
  securityMode: 'none' | 'sign' | 'signAndEncrypt';
  securityPolicy: 'None' | 'Basic256Sha256' | 'Aes128_Sha256_RsaOaep' | 'Aes256_Sha256_RsaPss';
  username: string | null;
  password: string | null;
}

export type OpcUaStatus = 'connecting' | 'connected' | 'reconnecting' | 'failed';

export interface OpcUaServerHealth {
  status: OpcUaStatus;
  nodes: number;
  /** The last connection error, and nodes the server refused. */
  error?: string;
  badNodes?: Record<string, string>;
}

export interface OpcUaOptions {
  /** Where the gateway keeps its own certificate and the servers' it trusts. */
  pkiDir?: string;
  /** Trust a server's certificate the first time it's seen (for Sign and SignAndEncrypt). */
  acceptUnknownCertificates?: boolean;
}

const MODES = {
  none: MessageSecurityMode.None,
  sign: MessageSecurityMode.Sign,
  signAndEncrypt: MessageSecurityMode.SignAndEncrypt,
};

const settingsOf = (config: OpcUaServerConfig) => JSON.stringify(config);

/** Where browsing starts: the address space's Objects folder. */
export const OBJECTS_FOLDER = 'i=85';
const MAX_CHILDREN = 200;
const BROWSE_TIMEOUT_MS = 10_000;

const clientFor = (config: OpcUaServerConfig, certificates: OPCUACertificateManager, retry: boolean) =>
  OPCUAClient.create({
    applicationName: 'Meridian gateway',
    endpointMustExist: false,
    securityMode: MODES[config.securityMode],
    securityPolicy: SecurityPolicy[config.securityPolicy],
    clientCertificateManager: certificates,
    keepSessionAlive: retry,
    connectionStrategy: retry
      ? { initialDelay: 1000, maxDelay: 10_000, maxRetry: Number.MAX_SAFE_INTEGER }
      : { initialDelay: 100, maxDelay: 100, maxRetry: 0 },
  });

const identityOf = (config: OpcUaServerConfig): UserIdentityInfo =>
  config.username
    ? { type: UserTokenType.UserName, userName: config.username, password: config.password ?? '' }
    : { type: UserTokenType.Anonymous };

/** A built-in type's name, or the node id of a server's own type. */
function typeName(value: unknown): string {
  const id = value as { namespace: number; value: unknown; toString(): string };
  return id.namespace === 0 && typeof id.value === 'number' && DataType[id.value] ? DataType[id.value]! : id.toString();
}

function shown(value: unknown): number | boolean | string | null {
  if (typeof value === 'number' || typeof value === 'boolean') return value;
  if (typeof value === 'string') return value.slice(0, 100);
  if (typeof value === 'bigint') return String(value);
  return null;
}

/** The objects and variables one level below a node, with each variable's type, value and access. */
export async function browseSession(session: ClientSession, node: string): Promise<OpcUaBrowseResult> {
  const result = await session.browse({
    nodeId: node,
    referenceTypeId: 'HierarchicalReferences',
    includeSubtypes: true,
    browseDirection: BrowseDirection.Forward,
    nodeClassMask: NodeClass.Object | NodeClass.Variable,
    resultMask: 0x3f,
  });
  if (!result.statusCode.isGood()) throw new Error(result.statusCode.name);
  // Namespace 0 holds the standard's own objects (the server's diagnostics,
  // aliases), never a plant's.
  const references = (result.references ?? []).filter((r) => !(r.nodeClass === NodeClass.Object && r.nodeId.namespace === 0));
  const idOf = (r: (typeof references)[number]) => r.nodeId.toString().replace(/^ns=0;/, '');
  const listed = references.slice(0, MAX_CHILDREN);
  const variables = listed.filter((r) => r.nodeClass === NodeClass.Variable);
  const attributes = variables.length
    ? await session.read(
        variables.flatMap((r) =>
          [AttributeIds.DataType, AttributeIds.Value, AttributeIds.UserAccessLevel].map((attributeId) => ({ nodeId: r.nodeId, attributeId })),
        ),
      )
    : [];
  const details = new Map(
    variables.map((r, i) => {
      const [type, value, access] = attributes.slice(i * 3, i * 3 + 3) as [DataValue, DataValue, DataValue];
      return [
        idOf(r),
        {
          ...(type.statusCode.isGood() ? { dataType: typeName(type.value.value) } : {}),
          value: value.statusCode.isGood() ? shown(value.value.value) : null,
          writable: access.statusCode.isGood() && (Number(access.value.value) & 0x02) !== 0,
        },
      ];
    }),
  );
  return {
    node,
    children: listed.map((r) => {
      const id = idOf(r);
      const name = r.displayName.text ?? r.browseName.name ?? id;
      return r.nodeClass === NodeClass.Variable ? { node: id, name, kind: 'variable', ...details.get(id) } : { node: id, name, kind: 'object' };
    }),
    more: references.length > listed.length || result.continuationPoint !== null,
  };
}

/** The integer types a setpoint can be written as, and their ranges. */
const INTEGERS: Partial<Record<DataType, [number, number]>> = {
  [DataType.SByte]: [-128, 127],
  [DataType.Byte]: [0, 255],
  [DataType.Int16]: [-32_768, 32_767],
  [DataType.UInt16]: [0, 65_535],
  [DataType.Int32]: [-2_147_483_648, 2_147_483_647],
  [DataType.UInt32]: [0, 4_294_967_295],
};

/**
 * A setpoint as a node of this built-in type holds it: a boolean is on unless
 * it's 0, an integer is rounded and must fit.
 */
export function variantFor(dataType: DataType, value: number): Variant {
  if (dataType === DataType.Boolean) return new Variant({ dataType, value: value !== 0 });
  if (dataType === DataType.Float || dataType === DataType.Double) return new Variant({ dataType, value });
  const range = INTEGERS[dataType];
  if (!range) throw new Error(`can't write a number to a ${DataType[dataType] ?? 'non-numeric'} node`);
  const rounded = Math.round(value);
  if (rounded < range[0] || rounded > range[1]) throw new Error(`${rounded} is out of range for ${DataType[dataType]}`);
  return new Variant({ dataType, value: rounded });
}

class Connection {
  status: OpcUaStatus = 'connecting';
  error?: string;
  readonly badNodes = new Map<string, string>();
  private readonly client: OPCUAClient;
  private session?: ClientSession;
  private subscription?: ClientSubscription;
  private readonly items = new Map<string, { item: ClientMonitoredItem; samplingMs: number }>();
  private wanted: MonitoredNode[] = [];
  private closed = false;

  constructor(
    readonly config: OpcUaServerConfig,
    certificates: OPCUACertificateManager,
    private readonly onValue: (node: string, value: unknown) => void,
  ) {
    this.client = clientFor(config, certificates, true);
    this.client.on('backoff', () => {
      if (this.status !== 'connected') this.status = this.session ? 'reconnecting' : 'connecting';
    });
    this.client.on('connection_failed', (error) => this.fail(error));
    this.client.on('start_reconnection', () => (this.status = 'reconnecting'));
    this.client.on('after_reconnection', () => (this.status = 'connected'));
    void this.start();
  }

  get nodes(): number {
    return this.items.size;
  }

  private fail(error: unknown): void {
    this.status = 'failed';
    this.error = error instanceof Error ? error.message : String(error);
  }

  private async start(): Promise<void> {
    try {
      await this.client.connect(this.config.endpoint);
      if (this.closed) return;
      this.session = await this.client.createSession(identityOf(this.config));
      this.subscription = await this.session.createSubscription2({
        requestedPublishingInterval: 500,
        requestedLifetimeCount: 600,
        requestedMaxKeepAliveCount: 20,
        maxNotificationsPerPublish: 1000,
        publishingEnabled: true,
        priority: 1,
      });
      if (this.closed) return;
      this.status = 'connected';
      this.error = undefined;
      this.apply();
    } catch (error) {
      if (!this.closed) this.fail(error);
    }
  }

  /** Writes a value to a node, as the node's own data type. */
  async write(node: string, value: number): Promise<void> {
    const session = this.session;
    if (!session || this.status !== 'connected') throw new Error(`${this.config.name} is not connected`);
    const type = await session.read({ nodeId: node, attributeId: AttributeIds.DataType });
    if (!type.statusCode.isGood()) throw new Error(type.statusCode.name);
    const id = type.value.value as { namespace: number; value: unknown };
    // Built-in types are numbered 1 to 25 in namespace 0.
    const dataType = id.namespace === 0 && typeof id.value === 'number' ? (id.value as DataType) : DataType.Null;
    const status = await session.write({
      nodeId: node,
      attributeId: AttributeIds.Value,
      value: { value: variantFor(dataType, value) },
    });
    if (!status.isGood()) throw new Error(status.name);
  }

  /** The connected session, if there is one. */
  get connected(): ClientSession | undefined {
    return this.status === 'connected' ? this.session : undefined;
  }

  /** Monitors these nodes, and only these. */
  follow(nodes: MonitoredNode[]): void {
    this.wanted = nodes;
    this.apply();
  }

  private apply(): void {
    const subscription = this.subscription;
    if (!subscription) return;
    const wanted = new Map(this.wanted.map((n) => [n.node, n]));
    for (const [node, { item, samplingMs }] of this.items) {
      if (wanted.get(node)?.samplingMs === samplingMs) continue;
      void item.terminate();
      this.items.delete(node);
      this.badNodes.delete(node);
    }
    for (const { node, samplingMs } of wanted.values()) {
      if (this.items.has(node)) continue;
      const item = ClientMonitoredItem.create(
        subscription,
        { nodeId: node, attributeId: AttributeIds.Value },
        { samplingInterval: samplingMs, discardOldest: true, queueSize: 1 },
        TimestampsToReturn.Neither,
      );
      item.on('changed', (value: DataValue) => {
        if (value.statusCode.isGood()) this.onValue(node, value.value.value);
      });
      item.on('err', (message: string) => this.badNodes.set(node, message));
      this.items.set(node, { item, samplingMs });
    }
  }

  async close(): Promise<void> {
    this.closed = true;
    try {
      await this.subscription?.terminate();
      await this.session?.close();
    } catch {
      // The server may already be gone.
    }
    await this.client.disconnect();
  }
}

export class OpcUaBridge {
  private readonly connections = new Map<string, Connection>();
  private readonly certificates: OPCUACertificateManager;
  private ready: Promise<void>;

  constructor(
    private readonly onValue: (server: string, node: string, value: unknown) => void,
    options: OpcUaOptions = {},
  ) {
    this.certificates = new OPCUACertificateManager({
      rootFolder: options.pkiDir,
      automaticallyAcceptUnknownCertificate: options.acceptUnknownCertificates ?? false,
    });
    this.ready = this.certificates.initialize();
  }

  /**
   * Connects to the servers that have nodes to monitor and monitors them;
   * disconnects from the rest, and reconnects a server whose settings changed.
   */
  async follow(configs: OpcUaServerConfig[], wanted: Map<string, MonitoredNode[]>): Promise<void> {
    await this.ready;
    const byName = new Map(configs.map((c) => [c.name, c]));
    for (const [name, connection] of this.connections) {
      const config = byName.get(name);
      if (config && wanted.has(name) && settingsOf(config) === settingsOf(connection.config)) continue;
      this.connections.delete(name);
      void connection.close();
    }
    for (const [name, nodes] of wanted) {
      const config = byName.get(name);
      if (!config) continue;
      let connection = this.connections.get(name);
      if (!connection) {
        connection = new Connection(config, this.certificates, (node, value) => this.onValue(name, node, value));
        this.connections.set(name, connection);
      }
      connection.follow(nodes);
    }
  }

  /** Writes a value to a node on a server the twin maps. */
  async write(server: string, node: string, value: number): Promise<void> {
    const connection = this.connections.get(server);
    if (!connection) throw new Error(`not connected to ${server}`);
    await connection.write(node, value);
  }

  /**
   * Browses one level of a server's address space. A server the twin
   * already uses is browsed on its session; any other gets a session of its
   * own for the browse, so this also tests whether the gateway can reach it.
   */
  async browse(config: OpcUaServerConfig, node: string = OBJECTS_FOLDER): Promise<OpcUaBrowseResult> {
    await this.ready;
    const existing = this.connections.get(config.name);
    const session = existing && settingsOf(existing.config) === settingsOf(config) ? existing.connected : undefined;
    if (session) return browseSession(session, node);

    const client = clientFor(config, this.certificates, false);
    let timer: ReturnType<typeof setTimeout> | undefined;
    const timeout = new Promise<never>((_, reject) => {
      timer = setTimeout(() => reject(new Error(`${config.name} didn't answer within ${BROWSE_TIMEOUT_MS / 1000} s`)), BROWSE_TIMEOUT_MS);
    });
    const attempt = (async () => {
      await client.connect(config.endpoint);
      const own = await client.createSession(identityOf(config));
      try {
        return await browseSession(own, node);
      } finally {
        await own.close();
      }
    })();
    try {
      return await Promise.race([attempt, timeout]);
    } finally {
      clearTimeout(timer);
      attempt.catch(() => undefined);
      await client.disconnect();
    }
  }

  health(): Record<string, OpcUaServerHealth> {
    return Object.fromEntries(
      [...this.connections].map(([name, c]) => [
        name,
        {
          status: c.status,
          nodes: c.nodes,
          ...(c.error ? { error: c.error } : {}),
          ...(c.badNodes.size ? { badNodes: Object.fromEntries(c.badNodes) } : {}),
        },
      ]),
    );
  }

  async close(): Promise<void> {
    await Promise.all([...this.connections.values()].map((c) => c.close()));
    this.connections.clear();
    await this.certificates.dispose();
  }
}
