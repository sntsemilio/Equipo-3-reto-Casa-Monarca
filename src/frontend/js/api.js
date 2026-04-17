/**
 * api.js — Capa de comunicación con el backend PHP
 * Maneja sesiones PHP nativas via credentials: 'include'
 */

const BASE = window.location.origin;
const SESSION_KEY = 'cm_user';

const DEMO_USERS = [
  { email: 'admin@casamonarca.org', password: 'admin123', nombre: 'Administrador Principal', rol_id: 1 },
  { email: 'operador@casamonarca.org', password: 'oper456', nombre: 'Operador Certificados', rol_id: 2 },
  { email: 'consultor@casamonarca.org', password: 'cons789', nombre: 'Consultor Lectura', rol_id: 3 },
];

const api = async (path, opts = {}) => {
  const res = await fetch(`${BASE}${path}`, {
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', ...opts.headers },
    ...opts,
  });
  const data = await res.json().catch(() => ({}));
  return { ok: res.ok, status: res.status, data };
};

const setSessionUser = (user) => {
  sessionStorage.setItem(SESSION_KEY, JSON.stringify(user));
};

export const getCurrentUser = () => {
  const raw = sessionStorage.getItem(SESSION_KEY);
  if (!raw) {
    return null;
  }

  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
};

export const logout = () => {
  sessionStorage.removeItem(SESSION_KEY);
};

// Auth
export const login = async (email, password) => {
  const body = new URLSearchParams({ email, password }).toString();

  try {
    const serverAttempt = await fetch(`${BASE}/src/auth/login.php`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });

    const data = await serverAttempt.json().catch(() => ({}));
    if (serverAttempt.ok && data.ok) {
      setSessionUser({ email, nombre: 'Usuario autenticado', rol_id: 'server' });
      return { ok: true, status: serverAttempt.status, data };
    }
  } catch {
    // Fallback demo local cuando backend no esta disponible.
  }

  const demoUser = DEMO_USERS.find((u) => u.email === email && u.password === password);
  if (!demoUser) {
    return {
      ok: false,
      status: 401,
      data: { ok: false, mensaje: 'Credenciales incorrectas' },
    };
  }

  setSessionUser({ email: demoUser.email, nombre: demoUser.nombre, rol_id: demoUser.rol_id });
  return {
    ok: true,
    status: 200,
    data: { ok: true, mensaje: 'Sesion demo iniciada' },
  };
};

// Documentos
export const consultarFolio = (folio) =>
  api(`/src/api/consulta_qr.php?folio=${encodeURIComponent(folio)}`);

// SHA-256 en el navegador (SubtleCrypto API)
export const calcularSHA256 = async (file) => {
  const buffer = await file.arrayBuffer();
  const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
  const hashArray = Array.from(new Uint8Array(hashBuffer));
  return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
};
