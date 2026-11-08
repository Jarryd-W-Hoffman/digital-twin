# OPC UA

Most PLCs, SCADA systems and building controllers speak **OPC UA**. A server publishes its data as **nodes**, each with an id such as `ns=2;s=Store.TempC`, and clients can subscribe to be told whenever a node's value changes. Meridian keeps a list of each project's OPC UA servers. A twin says which nodes feed which asset metrics, and the twin's gateway connects to the servers, watches those nodes, and treats every new value as a reading, just like one from MQTT.

![The OPC UA servers section of a project: the Depot PLC, its endpoint, and how the gateway connects to it.](../images/opcua-servers.png)

## How it works

```mermaid
flowchart LR
    CP["Meridian<br/>the project's servers"] -->|"with the twin's definition"| GW["Twin's gateway"]
    GW -->|"connect, log in, subscribe to mapped nodes"| PLC["OPC UA server<br/>(PLC, SCADA)"]
    PLC -->|"ns=1;s=Store.DoorOpen changed: true"| GW
    GW -->|"Seafood store · Door open = 100 %"| Twin["The live twin"]
    GW -->|"every 10 s, and each server's status"| CP
```

- The gateway connects to the servers your twin maps, not every server in the project.
- It keeps one connection per server, and reconnects on its own if the server restarts or the network drops.
- It's only told about changes, so a value that holds steady doesn't send anything. A reading lasts as long as its **TTL**; after that, the model carries on simulating the metric.
- Readings from OPC UA are kept and charted just like MQTT ones. See [what was recorded](10-devices-and-mqtt.md#see-what-was-recorded).

For now, Meridian only reads from OPC UA servers. It never writes to them.

## Add a server

Only editors can add servers.

1. On the project page, find **OPC UA servers**.
2. Fill in:
    - **Server name**: what twins will call it, such as `Depot PLC`. Names are unique within a project.
    - **Endpoint**: the server's address, which starts with `opc.tcp://`, such as `opc.tcp://plc.local:4840`. Your controls engineer or the server's documentation will have it.
    - **Security**: **None**, **Sign** or **Sign and encrypt**. Use what the server is set up for; see [Secured servers](#secured-servers).
    - **Security policy**: the algorithms for **Sign** or **Sign and encrypt**. `Basic256Sha256` works with most servers.
    - **Username** and **Password** (optional): if the server wants a login rather than anonymous access.
3. Choose **Add server**.

The list shows each server's endpoint, security and login. The password is kept encrypted and is never shown again; it's only ever sent to the project's gateways. To change a server, delete it and add it again with the same name. Twins find servers by name, so their mappings keep working.

## Map nodes onto metrics

![The OPC UA section of the Layout panel: the store's door switch on the Depot PLC, mapped onto the Seafood store's Door open with a scale of 100.](../images/opcua-mappings.png)

In the twin's **Layout** panel, under **OPC UA**:

1. Choose **Add OPC UA mapping**.
2. Fill in:
    - **Server**: one of the project's servers.
    - **Node**: the node id, such as `ns=2;s=Store.TempC` or `ns=3;i=1042`. Find it in the server's documentation, or browse the server with a client such as UaExpert.
    - **Asset** and **Metric**: what the value is.
    - **Scale** and **Offset** (optional): to convert units. The value is multiplied by the scale, then the offset is added.
    - **Sampling, ms** (optional): how often the server should check the node for changes, from 50 ms; 1000 ms by default.
3. Choose **Save OPC UA mappings**. They're checked, then saved as a new version, and the gateway follows them within a few seconds.

Numbers are used as they are. A switch (a boolean) counts as 1 when on and 0 when off, so a scale of `100` turns a door switch into **Door open** as a percentage. Text that isn't a number is skipped.

A node id is written as an optional namespace (`ns=2;`) followed by the kind of identifier and the identifier itself:

| Kind   | Example                                       |
| ------ | --------------------------------------------- |
| String | `ns=2;s=Store.TempC`                          |
| Number | `ns=3;i=1042`                                 |
| GUID   | `ns=2;g=5ce9dbce-5d79-434c-9ac3-1cfba9a6e92c` |
| Bytes  | `ns=2;b=M/RbKBsRVkePCePcx24oRA==`             |

A mapping to a server the project doesn't have is skipped, and so is one to a metric the asset doesn't have. The gateway logs a warning for each.

## See the connections

![The Gateway section with the gateway online, connected to the broker, and connected to the Depot PLC.](../images/gateway-online.png)

While the gateway is online, the **Gateway** section of the Layout panel lists each OPC UA server it's using and how its connection is:

| It says          | Meaning                                                                      |
| ---------------- | ---------------------------------------------------------------------------- |
| **connecting**   | Trying to reach the server for the first time; it keeps trying               |
| **connected**    | Logged in and subscribed                                                     |
| **reconnecting** | The connection dropped; it's reconnecting and will pick the subscription up  |
| **failed**       | The server refused it, such as a wrong password or a certificate not trusted |

The gateway's `/health` page has more detail: the last error, and any nodes the server refused (such as a node id that doesn't exist).

## Secured servers

With **Sign** or **Sign and encrypt**, the gateway and the server each prove who they are with a certificate, and each has to trust the other's:

1. The gateway makes its own certificate the first time it starts, in its PKI folder (`OPCUA_PKI_DIR`; see [Running Meridian](14-running-meridian.md#the-gateway)).
2. On the server, trust the gateway's certificate. Most servers put a new client's certificate in a "rejected" or "pending" list for an administrator to approve.
3. In the gateway's PKI folder, move the server's certificate from `rejected/` to `trusted/certs/`. Or start the gateway with `OPCUA_ACCEPT_UNKNOWN_CERTIFICATES=1` to trust whatever certificate a server presents first.

Until both sides trust each other, the server shows as **failed**.

## Try it with the simulator

Meridian comes with a small OPC UA server to try things on:

```bash
npm run opcua-simulator
```

It listens at `opc.tcp://127.0.0.1:4840/meridian`, anonymous and unsecured, with:

| Node                    | What it is                                           |
| ----------------------- | ---------------------------------------------------- |
| `ns=1;s=Store.TempC`    | A freezer temperature, swinging slowly around −18 °C |
| `ns=1;s=Store.DoorOpen` | A door switch, open for 10 seconds every 2 minutes   |
| `ns=1;s=Meter.EnergyWh` | An energy meter, counting up                         |

Add it to a project as a server, map `ns=1;s=Store.TempC` onto a cold store's **Temperature**, and start the twin's gateway on the same machine.

## Tips

- **Name servers for what they are.** "Depot PLC" is clearer to whoever maps nodes than "192.168.1.20".
- **Give the gateway a login of its own** on the server, with read-only access. Then it can't change anything, and you can see its sessions in the server's logs.
- **Don't sample faster than you need.** Most building and cold chain values change over seconds or minutes. 1000 ms is plenty, and faster sampling loads the PLC.
- **Use security outside a closed network.** **None** sends values and passwords in the clear.

## See also

- [Devices and MQTT](10-devices-and-mqtt.md): readings from sensors over MQTT, and the recorded history.
- [Gateways and live data](09-gateways-and-live-data.md): running the gateway that connects.
- [Running Meridian](14-running-meridian.md#the-gateway): the gateway's settings.
