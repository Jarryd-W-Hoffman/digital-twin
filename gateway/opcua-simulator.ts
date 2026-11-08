import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { OPCUACertificateManager } from 'node-opcua-certificate-manager';
import { DataType, Variant } from 'node-opcua-client';
import { OPCUAServer } from 'node-opcua-server';

/**
 * A small OPC UA server to develop and test against: a cold store's
 * temperature and door, and a meter's energy count, as variables in its own
 * namespace (ns=1). Values are set from outside with `set`.
 *
 *     npm run opcua-simulator      # opc.tcp://127.0.0.1:4840, the store warming and cooling
 */

export interface SimulatorOptions {
  port?: number;
  /** When set, only these logins may connect; otherwise anyone may, anonymously. */
  users?: Record<string, string>;
}

export const SIMULATED = {
  'Store.TempC': DataType.Double,
  'Store.DoorOpen': DataType.Boolean,
  'Meter.EnergyWh': DataType.UInt32,
  'Store.Status': DataType.String,
} as const;

export type SimulatedNode = keyof typeof SIMULATED;

export async function startSimulator(options: SimulatorOptions = {}) {
  const values: Record<SimulatedNode, unknown> = {
    'Store.TempC': -18.5,
    'Store.DoorOpen': false,
    'Meter.EnergyWh': 0,
    'Store.Status': 'ok',
  };
  const users = options.users;
  const server = new OPCUAServer({
    port: options.port ?? 4840,
    resourcePath: '/meridian',
    buildInfo: { productName: 'Meridian OPC UA simulator' },
    serverCertificateManager: new OPCUACertificateManager({
      rootFolder: mkdtempSync(join(tmpdir(), 'meridian-opcua-')),
      automaticallyAcceptUnknownCertificate: true,
    }),
    allowAnonymous: !users,
    ...(users ? { userManager: { isValidUser: (name: string, password: string) => users[name] === password } } : {}),
  });
  await server.initialize();
  const addressSpace = server.engine.addressSpace!;
  const namespace = addressSpace.getOwnNamespace();
  const site = namespace.addObject({ organizedBy: addressSpace.rootFolder.objects, browseName: 'Site' });
  for (const [name, dataType] of Object.entries(SIMULATED) as [SimulatedNode, DataType][]) {
    namespace.addVariable({
      componentOf: site,
      nodeId: `s=${name}`,
      browseName: name,
      dataType,
      minimumSamplingInterval: 100,
      value: { get: () => new Variant({ dataType, value: values[name] }) },
    });
  }
  await server.start();
  return {
    endpoint: server.getEndpointUrl().replace(/\/\/[^:/]+/, '//127.0.0.1'),
    set(name: SimulatedNode, value: unknown) {
      values[name] = value;
    },
    stop: () => server.shutdown(0),
  };
}

if (process.argv[1] === import.meta.filename) {
  const sim = await startSimulator({ port: Number(process.env.OPCUA_PORT ?? 4840) });
  console.log(`OPC UA simulator on ${sim.endpoint}`);
  let t = 0;
  setInterval(() => {
    t += 1;
    sim.set('Store.TempC', Math.round((-18 + 2 * Math.sin(t / 30)) * 100) / 100);
    sim.set('Store.DoorOpen', t % 120 < 10);
    sim.set('Meter.EnergyWh', t * 25);
  }, 1000);
}
