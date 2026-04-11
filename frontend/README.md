# Frontend — Casa Monarca

Frontend HTML/CSS/JS vanilla para el sistema de gestión de certificados.

## Estructura

```
frontend/
├── index.html       — Login
├── verificar.html   — Portal público de verificación (sin login)
├── dashboard.html   — Panel de administración
├── css/
│   └── styles.css   — Sistema de diseño completo
├── js/
│   └── api.js       — Capa de comunicación con el backend PHP
└── README.md
```

## Pantallas

| Pantalla | URL | Acceso |
|----------|-----|--------|
| Login | `index.html` | Público |
| Verificar certificado | `verificar.html?folio=ID` | Público |
| Dashboard admin | `dashboard.html` | Requiere sesión |

## Paleta de colores

| Variable | Valor | Uso |
|----------|-------|-----|
| `--primary` | `#1B3A6B` | Azul institucional |
| `--accent` | `#C8922A` | Dorado — badges, sellos |
| `--bg` | `#F8F6F2` | Fondo general |
| `--success` | `#1D6A4A` | Estado emitido/válido |
| `--error` | `#9B2D2D` | Estado revocado/error |

## Cómo usar

Servir desde el mismo Apache que el backend PHP (sin CORS):

```bash
# Copiar frontend al directorio web de PHP
cp -r frontend/* src/frontend/
# Acceder en: http://localhost:8080/frontend/
```

O levantar con Docker:
```bash
docker-compose up --build
```

## Integración con el backend

Todas las llamadas usan `credentials: 'include'` para enviar la cookie `PHPSESSID` automáticamente. Ver `js/api.js`.

## Desarrollado por

@N1K1R0M — Branch `frontend/N1K1R0M`
