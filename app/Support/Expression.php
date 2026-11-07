<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\InvalidExpression;

/**
 * Parses the twin definition's expression language, to check expressions
 * before a twin is saved. Mirrors resources/js/twin/core/expr.ts message for
 * message; both are held to tests/fixtures/expression-cases.json. The viewer
 * and gateway evaluate them, so this side only parses.
 */
final class Expression
{
    public const int MAX_LENGTH = 500;

    /**
     * How many arguments each function takes; -1 is one or more.
     */
    private const array FUNCTIONS = [
        'abs' => 1, 'ceil' => 1, 'clamp' => 3, 'floor' => 1,
        'max' => -1, 'min' => -1, 'round' => 1, 'sqrt' => 1,
    ];

    private const array OPERATORS = [
        '&&', '||', '<=', '>=', '==', '!=', '<', '>', '+', '-', '*', '/', '%', '^', '!', '?', ':', '(', ')', ',',
    ];

    private const array LEVELS = [
        ['||'],
        ['&&'],
        ['<', '<=', '>', '>=', '==', '!='],
        ['+', '-'],
        ['*', '/', '%'],
    ];

    private int $position = 0;

    /**
     * @var array<string, true>
     */
    private array $names = [];

    /**
     * @var list<array{asset: string, metric: string}>
     */
    private array $refs = [];

    /**
     * @param  list<array{kind: string, text: string, asset?: string, metric?: string}>  $tokens
     */
    private function __construct(private readonly array $tokens) {}

    /**
     * What is wrong with an expression, or null if it parses.
     */
    public static function error(mixed $source): ?string
    {
        if (! is_string($source)) {
            return 'must be text';
        }

        try {
            self::parse($source);

            return null;
        } catch (InvalidExpression $invalidExpression) {
            return $invalidExpression->getMessage();
        }
    }

    /**
     * The bare names and {asset.metric} references a valid expression reads.
     *
     * @return array{names: list<string>, refs: list<array{asset: string, metric: string}>}
     */
    public static function references(string $source): array
    {
        $parser = self::parse($source);

        return ['names' => array_keys($parser->names), 'refs' => $parser->refs];
    }

    private static function parse(string $source): self
    {
        if (mb_strlen($source) > self::MAX_LENGTH) {
            throw new InvalidExpression(sprintf('longer than %d characters', self::MAX_LENGTH));
        }

        $tokens = self::tokenize($source);

        throw_if($tokens === [], InvalidExpression::class, 'empty expression');

        $parser = new self($tokens);
        $parser->ternary();

        if ($parser->position < count($tokens)) {
            $parser->unexpected();
        }

        return $parser;
    }

    /**
     * @return list<array{kind: string, text: string, asset?: string, metric?: string}>
     */
    private static function tokenize(string $source): array
    {
        $tokens = [];
        $offset = 0;
        $length = mb_strlen($source);

        while ($offset < $length) {
            $rest = mb_substr($source, $offset);

            if (preg_match('/^[ \t\r\n]+/', $rest, $match) === 1) {
                $offset += mb_strlen($match[0]);

                continue;
            }

            if (preg_match('/^(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/', $rest, $match) === 1) {
                $tokens[] = ['kind' => 'num', 'text' => $match[0]];
            } elseif (preg_match('/^[A-Za-z_][A-Za-z0-9_]*/', $rest, $match) === 1) {
                $tokens[] = ['kind' => 'name', 'text' => $match[0]];
            } elseif (preg_match('/^\{([a-z0-9][a-z0-9_-]{0,63})\.([a-z][A-Za-z0-9_]{0,63})\}/', $rest, $match) === 1) {
                $tokens[] = ['kind' => 'ref', 'text' => $match[0], 'asset' => $match[1], 'metric' => $match[2]];
            } else {
                $operator = array_find(self::OPERATORS, fn (string $operator): bool => str_starts_with($rest, $operator));

                if ($operator === null) {
                    throw new InvalidExpression(sprintf('unexpected "%s"', mb_substr($rest, 0, 1)));
                }

                $match = [$operator];
                $tokens[] = ['kind' => 'op', 'text' => $operator];
            }

            $offset += mb_strlen($match[0]);
        }

        return $tokens;
    }

    private function ternary(): void
    {
        $this->binary(0);

        if (! $this->isOperator('?')) {
            return;
        }

        $this->position++;
        $this->ternary();
        $this->expect(':');
        $this->ternary();
    }

    private function binary(int $level): void
    {
        $operators = self::LEVELS[$level] ?? null;

        if ($operators === null) {
            $this->unary();

            return;
        }

        $this->binary($level + 1);

        while ($this->isOperatorIn($operators)) {
            $this->position++;
            $this->binary($level + 1);

            // Comparisons don't chain: `a < b < c` is a mistake, not a range.
            if ($level === 2) {
                return;
            }
        }
    }

    private function unary(): void
    {
        if ($this->isOperator('-') || $this->isOperator('!')) {
            $this->position++;
            $this->unary();

            return;
        }

        $this->primary();

        if ($this->isOperator('^')) {
            $this->position++;
            $this->unary();
        }
    }

    private function primary(): void
    {
        $token = $this->tokens[$this->position] ?? $this->unexpected();

        if ($token['kind'] === 'num') {
            $this->position++;

            return;
        }

        if ($token['kind'] === 'ref') {
            $this->position++;
            $this->refs[] = ['asset' => $token['asset'] ?? '', 'metric' => $token['metric'] ?? ''];

            return;
        }

        if ($token['kind'] === 'name') {
            $this->position++;

            if ($this->isOperator('(')) {
                $this->call($token['text']);
            } else {
                $this->names[$token['text']] = true;
            }

            return;
        }

        if ($token['text'] !== '(') {
            $this->unexpected();
        }

        $this->position++;
        $this->ternary();
        $this->expect(')');
    }

    private function call(string $function): void
    {
        $arity = self::FUNCTIONS[$function] ?? throw new InvalidExpression('unknown function '.$function);
        $this->position++;
        $count = 0;

        if (! $this->isOperator(')')) {
            $this->ternary();
            $count++;

            while ($this->isOperator(',')) {
                $this->position++;
                $this->ternary();
                $count++;
            }
        }

        $this->expect(')');

        throw_if($arity === -1 && $count === 0, InvalidExpression::class, $function.' takes at least 1 argument');

        if ($arity !== -1 && $count !== $arity) {
            throw new InvalidExpression(sprintf('%s takes %d argument%s', $function, $arity, $arity === 1 ? '' : 's'));
        }
    }

    private function expect(string $operator): void
    {
        if (! $this->isOperator($operator)) {
            $this->unexpected();
        }

        $this->position++;
    }

    private function isOperator(string $operator): bool
    {
        return $this->isOperatorIn([$operator]);
    }

    /**
     * @param  list<string>  $operators
     */
    private function isOperatorIn(array $operators): bool
    {
        $token = $this->tokens[$this->position] ?? null;

        return $token !== null && $token['kind'] === 'op' && in_array($token['text'], $operators, true);
    }

    private function unexpected(): never
    {
        $token = $this->tokens[$this->position] ?? null;

        throw new InvalidExpression($token === null ? 'unexpected end' : sprintf('unexpected "%s"', $token['text']));
    }
}
