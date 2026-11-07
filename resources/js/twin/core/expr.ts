/**
 * A small, safe expression language for behaviour written in a twin
 * definition: derived metrics, state transitions and rules. It has numbers,
 * arithmetic, comparisons, `&&`, `||`, `!`, `? :`, a handful of functions,
 * bare names (an asset's own metrics and states) and `{asset.metric}`
 * references to other assets. Nothing in it can reach the host language.
 *
 * The PHP control plane parses the same grammar (app/Support/Expression.php)
 * and both are held to tests/fixtures/expression-cases.json, so keep the
 * messages identical when changing either.
 *
 * Booleans are 1 and 0, and any non-zero value counts as true.
 */

export type Node =
    | { t: 'num'; v: number }
    | { t: 'name'; name: string }
    | { t: 'ref'; asset: string; metric: string }
    | { t: 'not' | 'neg'; a: Node }
    | { t: 'bin'; op: BinaryOp; a: Node; b: Node }
    | { t: 'if'; c: Node; a: Node; b: Node }
    | { t: 'call'; fn: string; args: Node[] };

type BinaryOp =
    | '+'
    | '-'
    | '*'
    | '/'
    | '%'
    | '^'
    | '<'
    | '<='
    | '>'
    | '>='
    | '=='
    | '!='
    | '&&'
    | '||';

export const MAX_LENGTH = 500;

/** Functions and how many arguments each takes; min and max take one or more. */
export const FUNCTIONS: Record<string, number> = {
    abs: 1,
    ceil: 1,
    clamp: 3,
    floor: 1,
    max: -1,
    min: -1,
    round: 1,
    sqrt: 1,
};

const OPERATORS = [
    '&&',
    '||',
    '<=',
    '>=',
    '==',
    '!=',
    '<',
    '>',
    '+',
    '-',
    '*',
    '/',
    '%',
    '^',
    '!',
    '?',
    ':',
    '(',
    ')',
    ',',
];

type Token =
    | { k: 'num'; v: number; text: string }
    | { k: 'name'; text: string }
    | { k: 'ref'; asset: string; metric: string; text: string }
    | { k: 'op'; text: string };

export class ExpressionError extends Error {}

const NUMBER = /^(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/;
const NAME = /^[A-Za-z_][A-Za-z0-9_]*/;
const REF = /^\{([a-z0-9][a-z0-9_-]{0,63})\.([a-z][A-Za-z0-9_]{0,63})\}/;

function tokenize(src: string): Token[] {
    const tokens: Token[] = [];
    let i = 0;
    while (i < src.length) {
        const rest = src.slice(i);
        const space = /^[ \t\r\n]+/.exec(rest);
        if (space) {
            i += space[0].length;
            continue;
        }
        const number = NUMBER.exec(rest);
        if (number) {
            tokens.push({ k: 'num', v: Number(number[0]), text: number[0] });
            i += number[0].length;
            continue;
        }
        const name = NAME.exec(rest);
        if (name) {
            tokens.push({ k: 'name', text: name[0] });
            i += name[0].length;
            continue;
        }
        const ref = REF.exec(rest);
        if (ref) {
            tokens.push({
                k: 'ref',
                asset: ref[1]!,
                metric: ref[2]!,
                text: ref[0],
            });
            i += ref[0].length;
            continue;
        }
        const op = OPERATORS.find((o) => rest.startsWith(o));
        if (!op) throw new ExpressionError(`unexpected "${rest[0]}"`);
        tokens.push({ k: 'op', text: op });
        i += op.length;
    }
    return tokens;
}

const LEVELS: string[][] = [
    ['||'],
    ['&&'],
    ['<', '<=', '>', '>=', '==', '!='],
    ['+', '-'],
    ['*', '/', '%'],
];

/** Parses an expression, or throws an ExpressionError saying what is wrong. */
export function parseExpression(src: string): Node {
    if (src.length > MAX_LENGTH)
        throw new ExpressionError(`longer than ${MAX_LENGTH} characters`);
    const tokens = tokenize(src);
    if (tokens.length === 0) throw new ExpressionError('empty expression');
    let pos = 0;

    const peek = (): Token | undefined => tokens[pos];
    const isOp = (text: string) => {
        const t = peek();
        return t?.k === 'op' && t.text === text;
    };
    const unexpected = (): never => {
        const t = peek();
        throw new ExpressionError(
            t ? `unexpected "${t.text}"` : 'unexpected end',
        );
    };
    const expect = (text: string) => {
        if (!isOp(text)) unexpected();
        pos++;
    };

    const ternary = (): Node => {
        const c = binary(0);
        if (!isOp('?')) return c;
        pos++;
        const a = ternary();
        expect(':');
        return { t: 'if', c, a, b: ternary() };
    };

    const binary = (level: number): Node => {
        if (level === LEVELS.length) return unary();
        let a = binary(level + 1);
        // Comparisons don't chain: `a < b < c` is a mistake, not a range.
        const chains = level !== 2;
        for (;;) {
            const t = peek();
            if (t?.k !== 'op' || !LEVELS[level]!.includes(t.text)) return a;
            pos++;
            a = {
                t: 'bin',
                op: t.text as BinaryOp,
                a,
                b: binary(level + 1),
            };
            if (!chains) return a;
        }
    };

    const unary = (): Node => {
        if (isOp('-')) {
            pos++;
            return { t: 'neg', a: unary() };
        }
        if (isOp('!')) {
            pos++;
            return { t: 'not', a: unary() };
        }
        return power();
    };

    const power = (): Node => {
        const a = primary();
        if (!isOp('^')) return a;
        pos++;
        // Right-associative, and binds tighter than a minus on its left.
        return { t: 'bin', op: '^', a, b: unary() };
    };

    const primary = (): Node => {
        const t = peek();
        if (t === undefined) return unexpected();
        if (t.k === 'num') {
            pos++;
            return { t: 'num', v: t.v };
        }
        if (t.k === 'ref') {
            pos++;
            return { t: 'ref', asset: t.asset, metric: t.metric };
        }
        if (t.k === 'name') {
            pos++;
            if (!isOp('(')) return { t: 'name', name: t.text };
            const arity = FUNCTIONS[t.text];
            if (arity === undefined)
                throw new ExpressionError(`unknown function ${t.text}`);
            pos++;
            const args: Node[] = [];
            if (!isOp(')')) {
                args.push(ternary());
                while (isOp(',')) {
                    pos++;
                    args.push(ternary());
                }
            }
            expect(')');
            if (arity === -1 ? args.length === 0 : args.length !== arity)
                throw new ExpressionError(
                    arity === -1
                        ? `${t.text} takes at least 1 argument`
                        : `${t.text} takes ${arity} argument${arity === 1 ? '' : 's'}`,
                );
            return { t: 'call', fn: t.text, args };
        }
        if (t.text === '(') {
            pos++;
            const inner = ternary();
            expect(')');
            return inner;
        }
        return unexpected();
    };

    const node = ternary();
    if (pos < tokens.length) unexpected();
    return node;
}

/** The problem with an expression, or null if it parses. */
export function expressionError(src: unknown): string | null {
    if (typeof src !== 'string') return 'must be text';
    try {
        parseExpression(src);
        return null;
    } catch (error) {
        return (error as ExpressionError).message;
    }
}

/** The bare names and `{asset.metric}` references an expression reads. */
export function namesIn(node: Node): {
    names: string[];
    refs: { asset: string; metric: string }[];
} {
    const names = new Set<string>();
    const refs: { asset: string; metric: string }[] = [];
    const walk = (n: Node): void => {
        switch (n.t) {
            case 'num':
                return;
            case 'name':
                names.add(n.name);
                return;
            case 'ref':
                refs.push({ asset: n.asset, metric: n.metric });
                return;
            case 'not':
            case 'neg':
                return walk(n.a);
            case 'bin':
                walk(n.a);
                return walk(n.b);
            case 'if':
                walk(n.c);
                walk(n.a);
                return walk(n.b);
            case 'call':
                return n.args.forEach(walk);
        }
    };
    walk(node);
    return { names: [...names], refs };
}

/** Looks up a bare name or a reference; undefined when there is no value. */
export interface Scope {
    name(name: string): number | undefined;
    ref(asset: string, metric: string): number | undefined;
}

class Missing extends Error {}

const truthy = (v: number) => v !== 0;
const bool = (v: boolean) => (v ? 1 : 0);

/**
 * Evaluates a parsed expression. Returns NaN when it reads something that has
 * no value, or when the arithmetic goes non-finite (dividing by zero, say), so
 * callers can leave whatever it would have set alone.
 */
export function evaluate(node: Node, scope: Scope): number {
    const run = (n: Node): number => {
        switch (n.t) {
            case 'num':
                return n.v;
            case 'name': {
                const v = scope.name(n.name);
                if (v === undefined) throw new Missing();
                return v;
            }
            case 'ref': {
                const v = scope.ref(n.asset, n.metric);
                if (v === undefined) throw new Missing();
                return v;
            }
            case 'neg':
                return -run(n.a);
            case 'not':
                return bool(!truthy(run(n.a)));
            case 'if':
                return truthy(run(n.c)) ? run(n.a) : run(n.b);
            case 'call': {
                const args = n.args.map(run);
                switch (n.fn) {
                    case 'abs':
                        return Math.abs(args[0]!);
                    case 'ceil':
                        return Math.ceil(args[0]!);
                    case 'floor':
                        return Math.floor(args[0]!);
                    case 'round':
                        return Math.floor(args[0]! + 0.5);
                    case 'sqrt':
                        return Math.sqrt(args[0]!);
                    case 'min':
                        return Math.min(...args);
                    case 'max':
                        return Math.max(...args);
                    default:
                        return Math.min(Math.max(args[0]!, args[1]!), args[2]!);
                }
            }
            case 'bin': {
                if (n.op === '&&')
                    return bool(truthy(run(n.a)) && truthy(run(n.b)));
                if (n.op === '||')
                    return bool(truthy(run(n.a)) || truthy(run(n.b)));
                const a = run(n.a);
                const b = run(n.b);
                switch (n.op) {
                    case '+':
                        return a + b;
                    case '-':
                        return a - b;
                    case '*':
                        return a * b;
                    case '/':
                        return a / b;
                    case '%':
                        return a % b;
                    case '^':
                        return a ** b;
                    case '<':
                        return bool(a < b);
                    case '<=':
                        return bool(a <= b);
                    case '>':
                        return bool(a > b);
                    case '>=':
                        return bool(a >= b);
                    case '==':
                        return bool(a === b);
                    default:
                        return bool(a !== b);
                }
            }
        }
    };
    try {
        const result = run(node);
        return Number.isFinite(result) ? result : NaN;
    } catch (error) {
        if (error instanceof Missing) return NaN;
        throw error;
    }
}
