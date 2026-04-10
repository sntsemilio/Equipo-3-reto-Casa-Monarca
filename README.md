# Gestor de Certificados, Documentos y Control de Accesos - Casa Monarca

Proyecto base para gestionar autenticacion, control de accesos por roles (RBAC),
emision de documentos y trazabilidad de acciones mediante bitacora.

Stack principal:
- PHP 8.2 nativo (Apache)
- MySQL 8.0
- Python 3.11 para scripts puntuales (hash y firma)

## Estructura

- `docker-compose.yml`: Orquestacion de contenedores web y base de datos
- `Dockerfile`: Imagen PHP 8.2 + Apache + PDO MySQL + Python
- `database/schema.sql`: Esquema inicial de tablas
- `src/config/db.php`: Conexion PDO
- `src/auth/login.php`: Login base con `password_verify`
- `src/auth/rbac.php`: Utilidades de control de acceso por rol
- `src/modules/bitacora.php`: Registro de eventos en bitacora
- `src/modules/emision_firma.py`: SHA-256 y firma RSA

## Levantar entorno local con Docker

1. Construir e iniciar servicios:

```bash
docker compose up -d --build
```

2. Aplicacion web:

```text
http://localhost:8080
```

3. MySQL:

- Host: `127.0.0.1`
- Puerto: `3307`
- Base de datos: `casa_monarca`
- Usuario: `casa_user`
- Password: `casa_pass`

4. Detener servicios:

```bash
docker compose down
```

## Nota

El script Python requiere la libreria `cryptography`, incluida en la imagen del
contenedor web. Para ejecutar manualmente:

```bash
python3.11 src/modules/emision_firma.py --text "Documento Casa Monarca"
```

## Pruebas

Pruebas PHP (sin framework adicional):

```bash
php tests/php/run_tests.php
```

Pruebas Python (unittest):

```bash
python3.11 -m unittest discover -s tests/python -p "test_emision_firma.py" -v
```

