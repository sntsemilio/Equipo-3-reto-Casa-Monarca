/**
 * api.js — Capa de comunicación con el backend PHP
 * Maneja sesiones PHP nativas via credentials: 'include'
 */

const BASE = window.location.origin;

const api = async (path, opts = {}) => {
  const res = await fetch(`${BASE}${path}`, {
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', ...opts.headers },
    ...opts,
  });
  const data = await res.json().catch(() => ({}));
  return { ok: res.ok, status: res.status, data };
};

// Auth
export const login = (email, password) =>
  api('/src/auth/login.php', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  });

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
