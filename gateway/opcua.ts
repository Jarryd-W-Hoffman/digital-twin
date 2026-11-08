import { OPCUACertificateManager } from 'node-opcua-certificate-manager';
import {
  AttributeIds,
  ClientMonitoredItem,
  type ClientSession,
  type ClientSubscription,
  type DataValue,
  MessageSecurityMode,
  OPCUAClient,
  SecurityPolicy,
  TimestampsToReturn,
  type UserIdentityInfo,
  UserTokenType,
} from 'node-opcua-client';
import type { MonitoredNode } from '../resources/js/twin/core/opcua.ts';

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
    this.client = OPCUAClient.create({
      applicationName: 'Meridian gateway',
      endpointMustExist: false,
      securityMode: MODES[config.securityMode],
      securityPolicy: SecurityPolicy[config.securityPolicy],
      clientCertificateManager: certificates,
      keepSessionAlive: true,
      connectionStrategy: { initialDelay: 1000, maxDelay: 10_000, maxRetry: Number.MAX_SAFE_INTEGER },
    });
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
      const identity: UserIdentityInfo = this.config.username
        ? { type: UserTokenType.UserName, userName: this.config.username, password: this.config.password ?? '' }
        : { type: UserTokenType.Anonymous };
      this.session = await this.client.createSession(identity);
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
