<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
require_once __DIR__ . '/../../src/auth/login.php';
ob_end_clean();

final class FakeStatementLogin
{
    private array|false $user;

    public function __construct(array|false $user)
    {
        $this->user = $user;
    }

    public function execute(array $params): bool
    {
        return isset($params['email']);
    }

    public function fetch(): array|false
    {
        return $this->user;
    }
}

final class FakePdoLogin
{
    private array|false $user;

    public function __construct(array|false $user)
    {
        $this->user = $user;
    }

    public function prepare(string $sql): FakeStatementLogin
    {
        assertTrue(str_contains($sql, 'FROM usuarios'), 'El SQL debe consultar la tabla usuarios.');
        return new FakeStatementLogin($this->user);
    }
}

function runLoginTests(): array
{
    $results = [];

    $results[] = runTest('login_falla_usuario_no_encontrado', function (): void {
        $_SESSION = [];
        $pdo = new FakePdoLogin(false);
        $response = authenticate('nadie@demo.com', '1234', $pdo);

        assertSame(false, $response['success'], 'Debe fallar cuando el usuario no existe.');
        assertSame('Usuario no encontrado.', $response['message'], 'Mensaje inesperado para usuario inexistente.');
    });

    $results[] = runTest('login_falla_usuario_inactivo', function (): void {
        $_SESSION = [];
        $pdo = new FakePdoLogin([
            'id' => 10,
            'nombre' => 'Inactivo',
            'email' => 'inactivo@demo.com',
            'password_hash' => password_hash('secreto', PASSWORD_DEFAULT),
            'activo' => 0,
            'role_name' => 'admin',
        ]);

        $response = authenticate('inactivo@demo.com', 'secreto', $pdo);

        assertSame(false, $response['success'], 'Debe fallar para usuarios inactivos.');
        assertSame('Usuario inactivo.', $response['message'], 'Mensaje inesperado para usuario inactivo.');
    });

    $results[] = runTest('login_falla_password_incorrecto', function (): void {
        $_SESSION = [];
        $pdo = new FakePdoLogin([
            'id' => 11,
            'nombre' => 'Usuario',
            'email' => 'usuario@demo.com',
            'password_hash' => password_hash('correcto', PASSWORD_DEFAULT),
            'activo' => 1,
            'role_name' => 'operador',
        ]);

        $response = authenticate('usuario@demo.com', 'incorrecto', $pdo);

        assertSame(false, $response['success'], 'Debe fallar con password invalido.');
        assertSame('Credenciales invalidas.', $response['message'], 'Mensaje inesperado para password invalido.');
    });

    $results[] = runTest('login_exitoso_asigna_sesion', function (): void {
        $_SESSION = [];
        $pdo = new FakePdoLogin([
            'id' => 12,
            'nombre' => 'Ana',
            'email' => 'ana@demo.com',
            'password_hash' => password_hash('mi_clave', PASSWORD_DEFAULT),
            'activo' => 1,
            'role_name' => 'admin',
        ]);

        $response = authenticate('ana@demo.com', 'mi_clave', $pdo);

        assertSame(true, $response['success'], 'Debe autenticar con credenciales correctas.');
        assertSame(12, $_SESSION['user_id'], 'Debe guardar user_id en sesion.');
        assertSame('admin', $_SESSION['user_role'], 'Debe guardar user_role en sesion.');
    });

    return $results;
}
