/**
 * Tests del frontend Casa Monarca
 * Corre con: node tests/test_frontend.mjs
 */

import { readFileSync, existsSync } from 'fs';
import { createHash } from 'crypto';

let passed = 0;
let failed = 0;

function test(name, fn) {
  try {
    fn();
    console.log(`  ✅ ${name}`);
    passed++;
  } catch (e) {
    console.log(`  ❌ ${name}`);
    console.log(`     → ${e.message}`);
    failed++;
  }
}

function assert(condition, msg) {
  if (!condition) throw new Error(msg || 'Assertion failed');
}

// ─── Lógica de api.js (portada a Node) ───────────────────────────────────────

function calcularSHA256_node(text) {
  return createHash('sha256').update(text).digest('hex');
}

function consultarFolioMock(folio, db) {
  const doc = db.find(d => d.id == folio);
  if (!doc) return { estatus: 'no_encontrado' };
  if (doc.estado === 'emitido') return { estatus: 'valido' };
  if (doc.estado === 'revocado') return { estatus: 'revocado' };
  return { estatus: 'no_encontrado' };
}

function loginMock(email, password, usuarios) {
  const user = usuarios.find(u => u.email === email && u.activo);
  if (!user) return { ok: false, mensaje: 'Usuario no encontrado o inactivo' };
  if (user.password !== password) return { ok: false, mensaje: 'Contraseña incorrecta' };
  return { ok: true, mensaje: 'Login exitoso', rol_id: user.rol_id };
}

function revocarDocumentoMock(folio, db) {
  const doc = db.find(d => d.id == folio);
  if (!doc) return { ok: false, mensaje: 'Documento no encontrado' };
  if (doc.estado === 'revocado') return { ok: false, mensaje: 'Ya estaba revocado' };
  doc.estado = 'revocado';
  doc.updated_at = new Date().toISOString();
  return { ok: true };
}

function crearUsuarioMock(datos, db) {
  const { nombre, email, password, rol_id } = datos;
  if (!nombre || !email || !password || !rol_id) return { ok: false, mensaje: 'Campos incompletos' };
  if (db.find(u => u.email === email)) return { ok: false, mensaje: 'Email ya registrado' };
  const newUser = { id: db.length + 1, nombre, email, password, rol_id, activo: 1 };
  db.push(newUser);
  return { ok: true, usuario_id: newUser.id };
}

function filtrarDocumentos(docs, estado, texto) {
  let result = [...docs];
  if (estado) result = result.filter(d => d.estado === estado);
  if (texto) result = result.filter(d =>
    d.titulo.toLowerCase().includes(texto.toLowerCase()) ||
    String(d.id).includes(texto)
  );
  return result;
}

// ─── Datos mock ────────────────────────────────────────────────────────────

const mockDocs = [
  { id: 1, titulo: 'Certificado Taller Mariposa', estado: 'emitido', firmado: 1, hash_sha256: null },
  { id: 2, titulo: 'Constancia Voluntariado', estado: 'borrador', firmado: 0, hash_sha256: null },
  { id: 3, titulo: 'Diploma Reinserción Social', estado: 'revocado', firmado: 1, hash_sha256: null },
];

const mockUsuarios = [
  { id: 1, nombre: 'Admin', email: 'admin@casamonarca.org', password: 'admin123', rol_id: 1, activo: 1 },
  { id: 2, nombre: 'Operador', email: 'operador@casamonarca.org', password: 'oper456', rol_id: 2, activo: 1 },
  { id: 3, nombre: 'Inactivo', email: 'inactivo@casamonarca.org', password: 'pass', rol_id: 2, activo: 0 },
];

// ─── Suite 1: Archivos y estructura ──────────────────────────────────────────

console.log('\n📁 Suite 1: Archivos y estructura');

test('index.html existe', () => assert(existsSync('frontend/index.html')));
test('verificar.html existe', () => assert(existsSync('frontend/verificar.html')));
test('dashboard.html existe', () => assert(existsSync('frontend/dashboard.html')));
test('css/styles.css existe', () => assert(existsSync('frontend/css/styles.css')));
test('js/api.js existe', () => assert(existsSync('frontend/js/api.js')));
test('README.md existe', () => assert(existsSync('frontend/README.md')));

// ─── Suite 2: Contenido HTML ─────────────────────────────────────────────────

console.log('\n🧪 Suite 2: Contenido HTML');

test('index.html tiene formulario de login', () => {
  const html = readFileSync('frontend/index.html', 'utf8');
  assert(html.includes('<form id="login-form"'), 'No tiene form#login-form');
  assert(html.includes('type="email"'), 'No tiene input email');
  assert(html.includes('type="password"'), 'No tiene input password');
});

test('index.html enlaza a verificar.html', () => {
  const html = readFileSync('frontend/index.html', 'utf8');
  assert(html.includes('verificar.html'), 'No enlaza a verificar.html');
});

test('verificar.html tiene input de folio', () => {
  const html = readFileSync('frontend/verificar.html', 'utf8');
  assert(html.includes('id="folio-input"'), 'No tiene input#folio-input');
  assert(html.includes('id="btn-verificar"'), 'No tiene btn-verificar');
});

test('verificar.html soporta folio en URL (?folio=...)', () => {
  const html = readFileSync('frontend/verificar.html', 'utf8');
  assert(html.includes("params.get('folio')"), 'No lee folio de URL params');
});

test('dashboard.html tiene 4 secciones', () => {
  const html = readFileSync('frontend/dashboard.html', 'utf8');
  assert(html.includes('section-overview'), 'Falta section-overview');
  assert(html.includes('section-documentos'), 'Falta section-documentos');
  assert(html.includes('section-usuarios'), 'Falta section-usuarios');
  assert(html.includes('section-bitacora'), 'Falta section-bitacora');
});

test('dashboard.html tiene stat cards', () => {
  const html = readFileSync('frontend/dashboard.html', 'utf8');
  assert(html.includes('stat-total'), 'Falta stat-total');
  assert(html.includes('stat-emitidos'), 'Falta stat-emitidos');
});

test('css/styles.css define variables de color', () => {
  const css = readFileSync('frontend/css/styles.css', 'utf8');
  assert(css.includes('--primary: #1B3A6B'), 'Falta --primary');
  assert(css.includes('--accent: #C8922A'), 'Falta --accent');
  assert(css.includes('--success: #1D6A4A'), 'Falta --success');
  assert(css.includes('--error: #9B2D2D'), 'Falta --error');
});

test('css/styles.css tiene badge-emitido, badge-borrador, badge-revocado', () => {
  const css = readFileSync('frontend/css/styles.css', 'utf8');
  assert(css.includes('.badge-emitido'), 'Falta .badge-emitido');
  assert(css.includes('.badge-borrador'), 'Falta .badge-borrador');
  assert(css.includes('.badge-revocado'), 'Falta .badge-revocado');
});

test('js/api.js tiene credentials: include', () => {
  const js = readFileSync('frontend/js/api.js', 'utf8');
  assert(js.includes("credentials: 'include'"), 'Falta credentials: include');
});

test('js/api.js exporta login, consultarFolio y calcularSHA256', () => {
  const js = readFileSync('frontend/js/api.js', 'utf8');
  assert(js.includes('export const login'), 'Falta export login');
  assert(js.includes('export const consultarFolio'), 'Falta export consultarFolio');
  assert(js.includes('export const calcularSHA256'), 'Falta export calcularSHA256');
});

// ─── Suite 3: Lógica de negocio ───────────────────────────────────────────────

console.log('\n⚙️  Suite 3: Lógica de negocio');

test('SHA-256 de texto vacío es correcto', () => {
  const hash = calcularSHA256_node('');
  assert(hash === 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', `Hash incorrecto: ${hash}`);
});

test('SHA-256 de "Casa Monarca" es consistente', () => {
  const h1 = calcularSHA256_node('Casa Monarca');
  const h2 = calcularSHA256_node('Casa Monarca');
  assert(h1 === h2, 'Hash no es determinístico');
  assert(h1.length === 64, `Longitud incorrecta: ${h1.length}`);
});

test('SHA-256 distingue mayúsculas', () => {
  const h1 = calcularSHA256_node('monarca');
  const h2 = calcularSHA256_node('MONARCA');
  assert(h1 !== h2, 'No distingue mayúsculas/minúsculas');
});

test('consultarFolio: documento emitido → valido', () => {
  const result = consultarFolioMock(1, mockDocs);
  assert(result.estatus === 'valido', `Esperado 'valido', obtenido '${result.estatus}'`);
});

test('consultarFolio: documento revocado → revocado', () => {
  const result = consultarFolioMock(3, mockDocs);
  assert(result.estatus === 'revocado', `Esperado 'revocado', obtenido '${result.estatus}'`);
});

test('consultarFolio: folio inexistente → no_encontrado', () => {
  const result = consultarFolioMock(999, mockDocs);
  assert(result.estatus === 'no_encontrado', `Esperado 'no_encontrado', obtenido '${result.estatus}'`);
});

test('consultarFolio: documento borrador → no_encontrado', () => {
  const result = consultarFolioMock(2, mockDocs);
  assert(result.estatus === 'no_encontrado', `Borrador no debería ser válido`);
});

// ─── Suite 4: Autenticación ───────────────────────────────────────────────────

console.log('\n🔐 Suite 4: Autenticación');

test('login exitoso con credenciales correctas', () => {
  const result = loginMock('admin@casamonarca.org', 'admin123', mockUsuarios);
  assert(result.ok === true, `Login falló: ${result.mensaje}`);
  assert(result.rol_id === 1, `Rol incorrecto: ${result.rol_id}`);
});

test('login falla con contraseña incorrecta', () => {
  const result = loginMock('admin@casamonarca.org', 'wrongpass', mockUsuarios);
  assert(result.ok === false, 'Login debería fallar');
  assert(result.mensaje.toLowerCase().includes('contraseña'), `Mensaje inesperado: ${result.mensaje}`);
});

test('login falla con usuario inexistente', () => {
  const result = loginMock('noexiste@test.com', '1234', mockUsuarios);
  assert(result.ok === false, 'Login debería fallar');
});

test('login falla con usuario inactivo', () => {
  const result = loginMock('inactivo@casamonarca.org', 'pass', mockUsuarios);
  assert(result.ok === false, 'Usuario inactivo no debería poder hacer login');
});

// ─── Suite 5: Documentos ─────────────────────────────────────────────────────

console.log('\n📄 Suite 5: Documentos');

test('revocar documento emitido funciona', () => {
  const db = JSON.parse(JSON.stringify(mockDocs));
  const result = revocarDocumentoMock(1, db);
  assert(result.ok === true, `Revocar falló: ${result.mensaje}`);
  assert(db.find(d => d.id === 1).estado === 'revocado', 'Estado no cambió a revocado');
});

test('revocar documento ya revocado falla', () => {
  const db = JSON.parse(JSON.stringify(mockDocs));
  const result = revocarDocumentoMock(3, db);
  assert(result.ok === false, 'Debería fallar al revocar documento ya revocado');
});

test('revocar documento inexistente falla', () => {
  const db = JSON.parse(JSON.stringify(mockDocs));
  const result = revocarDocumentoMock(999, db);
  assert(result.ok === false, 'Debería fallar con folio inexistente');
});

test('filtrar documentos por estado emitido', () => {
  const result = filtrarDocumentos(mockDocs, 'emitido', '');
  assert(result.length === 1, `Esperado 1, obtenido ${result.length}`);
  assert(result[0].id === 1, 'Documento incorrecto');
});

test('filtrar documentos por texto', () => {
  const result = filtrarDocumentos(mockDocs, '', 'voluntariado');
  assert(result.length === 1, `Esperado 1, obtenido ${result.length}`);
  assert(result[0].titulo.toLowerCase().includes('voluntariado'), 'Título incorrecto');
});

test('filtrar documentos: estado + texto combinados', () => {
  const result = filtrarDocumentos(mockDocs, 'emitido', 'mariposa');
  assert(result.length === 1, `Esperado 1, obtenido ${result.length}`);
});

test('filtrar documentos sin filtros devuelve todos', () => {
  const result = filtrarDocumentos(mockDocs, '', '');
  assert(result.length === 3, `Esperado 3, obtenido ${result.length}`);
});

// ─── Suite 6: Usuarios ────────────────────────────────────────────────────────

console.log('\n👥 Suite 6: Usuarios');

test('crear usuario con datos completos', () => {
  const db = JSON.parse(JSON.stringify(mockUsuarios));
  const result = crearUsuarioMock({ nombre: 'Nuevo', email: 'nuevo@test.com', password: 'pass123', rol_id: 2 }, db);
  assert(result.ok === true, `Crear usuario falló: ${result.mensaje}`);
  assert(db.length === 4, `Esperado 4 usuarios, hay ${db.length}`);
});

test('crear usuario con email duplicado falla', () => {
  const db = JSON.parse(JSON.stringify(mockUsuarios));
  const result = crearUsuarioMock({ nombre: 'Dup', email: 'admin@casamonarca.org', password: 'x', rol_id: 1 }, db);
  assert(result.ok === false, 'Debería fallar con email duplicado');
  assert(result.mensaje.toLowerCase().includes('email'), `Mensaje inesperado: ${result.mensaje}`);
});

test('crear usuario con campos incompletos falla', () => {
  const db = JSON.parse(JSON.stringify(mockUsuarios));
  const result = crearUsuarioMock({ nombre: 'Sin email', email: '', password: 'pass', rol_id: 1 }, db);
  assert(result.ok === false, 'Debería fallar con campos incompletos');
});

// ─── Resumen ──────────────────────────────────────────────────────────────────

console.log('\n' + '─'.repeat(50));
console.log(`  Total:   ${passed + failed} tests`);
console.log(`  ✅ OK:   ${passed}`);
console.log(`  ❌ FAIL: ${failed}`);
console.log('─'.repeat(50) + '\n');

process.exit(failed > 0 ? 1 : 0);
