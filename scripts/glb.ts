/** A box in metres: x east, y north, z up from the ground, then its size. */
export interface Box {
    x: number;
    y: number;
    z?: number;
    w: number;
    d: number;
    h: number;
}

/** One merged mesh; glTF is Y-up, so east = x, up = y, north = -z. */
export function toGlb(
    boxes: Box[],
    color: [number, number, number, number],
    generator: string,
): Buffer {
    const positions: number[] = [];
    const normals: number[] = [];
    const indices: number[] = [];
    const quad = (
        a: number[],
        b: number[],
        c: number[],
        d: number[],
        n: number[],
    ) => {
        const start = positions.length / 3;
        for (const p of [a, b, c, d]) {
            positions.push(p[0]!, p[1]!, p[2]!);
            normals.push(n[0]!, n[1]!, n[2]!);
        }
        indices.push(start, start + 1, start + 2, start, start + 2, start + 3);
    };
    for (const { x, y, z, w, d, h } of boxes) {
        const [x0, x1, z0, z1] = [x, x + w, -y, -(y + d)] as const;
        const [y0, y1] = [z ?? 0, (z ?? 0) + h];
        quad(
            [x0, y0, z1],
            [x0, y1, z1],
            [x1, y1, z1],
            [x1, y0, z1],
            [0, 0, -1],
        );
        quad([x1, y0, z0], [x1, y1, z0], [x0, y1, z0], [x0, y0, z0], [0, 0, 1]);
        quad([x1, y0, z1], [x1, y1, z1], [x1, y1, z0], [x1, y0, z0], [1, 0, 0]);
        quad(
            [x0, y0, z0],
            [x0, y1, z0],
            [x0, y1, z1],
            [x0, y0, z1],
            [-1, 0, 0],
        );
        quad([x0, y1, z0], [x1, y1, z0], [x1, y1, z1], [x0, y1, z1], [0, 1, 0]);
    }
    const pos = Buffer.from(new Float32Array(positions).buffer);
    const nor = Buffer.from(new Float32Array(normals).buffer);
    const idx = Buffer.from(new Uint32Array(indices).buffer);
    const bin = Buffer.concat([pos, nor, idx]);
    const min = [0, 1, 2].map((a) =>
        Math.min(...positions.filter((_, i) => i % 3 === a)),
    );
    const max = [0, 1, 2].map((a) =>
        Math.max(...positions.filter((_, i) => i % 3 === a)),
    );

    const gltf = {
        asset: { version: '2.0', generator },
        scene: 0,
        scenes: [{ nodes: [0] }],
        nodes: [{ mesh: 0 }],
        meshes: [
            {
                primitives: [
                    {
                        attributes: { POSITION: 0, NORMAL: 1 },
                        indices: 2,
                        material: 0,
                    },
                ],
            },
        ],
        materials: [
            {
                pbrMetallicRoughness: {
                    baseColorFactor: color,
                    metallicFactor: 0,
                    roughnessFactor: 0.9,
                },
            },
        ],
        accessors: [
            {
                bufferView: 0,
                componentType: 5126,
                count: positions.length / 3,
                type: 'VEC3',
                min,
                max,
            },
            {
                bufferView: 1,
                componentType: 5126,
                count: normals.length / 3,
                type: 'VEC3',
            },
            {
                bufferView: 2,
                componentType: 5125,
                count: indices.length,
                type: 'SCALAR',
            },
        ],
        bufferViews: [
            { buffer: 0, byteOffset: 0, byteLength: pos.length, target: 34962 },
            {
                buffer: 0,
                byteOffset: pos.length,
                byteLength: nor.length,
                target: 34962,
            },
            {
                buffer: 0,
                byteOffset: pos.length + nor.length,
                byteLength: idx.length,
                target: 34963,
            },
        ],
        buffers: [{ byteLength: bin.length }],
    };
    const pad = (b: Buffer, fill: number) =>
        Buffer.concat([b, Buffer.alloc((4 - (b.length % 4)) % 4, fill)]);
    const json = pad(Buffer.from(JSON.stringify(gltf)), 0x20);
    const binary = pad(bin, 0);
    const total = 12 + 8 + json.length + 8 + binary.length;
    const header = Buffer.alloc(12);
    header.writeUInt32LE(0x46546c67, 0);
    header.writeUInt32LE(2, 4);
    header.writeUInt32LE(total, 8);
    const chunk = (data: Buffer, type: number) => {
        const head = Buffer.alloc(8);
        head.writeUInt32LE(data.length, 0);
        head.writeUInt32LE(type, 4);
        return Buffer.concat([head, data]);
    };
    return Buffer.concat([
        header,
        chunk(json, 0x4e4f534a),
        chunk(binary, 0x004e4942),
    ]);
}
