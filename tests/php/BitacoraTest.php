<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../src/modules/bitacora.php';

final class FakeStatementBitacora
{
    public array $payload = [];

    public function execute(array $params): bool
    {
        $this->payload = $params;
        return true;
    }
}

final class FakePdoBitacora
{
    public ?string $sql = null;
    public FakeStatementBitacora $stmt;

    public function __construct()
    {
        $this->stmt = new FakeStatementBitacora();
    }

    public function prepare(string $sql): FakeStatementBitacora
    {
        $this->sql = $sql;
        return $this->stmt;
    }
}

function runBitacoraTests(): array
{
    $results = [];

    $results[] = runTest('bitacora_inserta_parametros', function (): void {
        $pdo = new FakePdoBitacora();

        $ok = registrarBitacora(
            'LOGIN',
            'AUTH',
            'Inicio de sesion exitoso',
            21,
            '127.0.0.1',
            $pdo
        );

        assertSame(true, $ok, 'registrarBitacora debe retornar true al ejecutar.');
        assertTrue(str_contains((string) $pdo->sql, 'INSERT INTO bitacora'), 'Debe generar SQL de insercion.');
        assertSame('LOGIN', $pdo->stmt->payload['accion'], 'La accion debe enviarse al execute.');
        assertSame('AUTH', $pdo->stmt->payload['modulo'], 'El modulo debe enviarse al execute.');
        assertSame(21, $pdo->stmt->payload['usuario_id'], 'El usuario_id debe enviarse al execute.');
    });

    return $results;
}
