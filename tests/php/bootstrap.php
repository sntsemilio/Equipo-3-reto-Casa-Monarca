<?php
declare(strict_types=1);

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' | Esperado: ' . var_export($expected, true) . ' | Actual: ' . var_export($actual, true)
        );
    }
}

function runTest(string $name, callable $test): array
{
    try {
        $test();
        return ['name' => $name, 'ok' => true, 'error' => null];
    } catch (Throwable $e) {
        return ['name' => $name, 'ok' => false, 'error' => $e->getMessage()];
    }
}
