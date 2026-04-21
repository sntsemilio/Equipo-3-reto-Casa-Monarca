/**
 * api.js - Capa de comunicacion con backend PHP.
 * Usa sesiones PHP por cookie con credentials: include.
 */

const BASE = window.location.origin;
const SESSION_KEY = 'cm_user';

const ENDPOINTS = {
  login: ['/auth/login.php', '/src/auth/login.php'],
  logout: ['/auth/logout.php', '/src/auth/logout.php'],
  register: ['/auth/register.php', '/src/auth/register.php'],
  session: ['/auth/session.php', '/src/auth/session.php'],
  consultaQr: ['/api/consulta_qr.php', '/src/api/consulta_qr.php'],
  documentosList: ['/api/documentos-list.php', '/src/api/documentos-list.php'],
  documentosCreate: ['/api/documentos-create.php', '/src/api/documentos-create.php'],
  documentosUpdate: ['/api/documentos-update.php', '/src/api/documentos-update.php'],
  documentosDelete: ['/api/documentos-delete.php', '/src/api/documentos-delete.php'],
  documentosEmitir: ['/api/documentos-emitir.php', '/src/api/documentos-emitir.php'],
  documentosRevocar: ['/api/documentos-revocar.php', '/src/api/documentos-revocar.php'],
  bitacoraList: ['/api/bitacora-list.php', '/src/api/bitacora-list.php'],
  usuariosList: ['/api/usuarios-list.php', '/src/api/usuarios-list.php'],
  usuariosDesactivar: ['/api/usuarios-desactivar.php', '/src/api/usuarios-desactivar.php'],
  usuariosCambiarRol: ['/api/usuarios-cambiar-rol.php', '/src/api/usuarios-cambiar-rol.php'],
};

const buildCandidatesWithQuery = (candidates, queryParams = {}) => {
  const query = new URLSearchParams(queryParams).toString();
  if (!query) {
    return candidates;
  }

  return candidates.map((path) => `${path}?${query}`);
};

const normalizeEnvelope = (payload, fallbackMessage = 'No fue posible procesar la respuesta.') => {
  if (payload && typeof payload === 'object') {
    const status = payload.status;
    if (status === 'success' || status === 'error') {
      const message = payload.message || payload.mensaje || fallbackMessage;
      return {
        status,
        ok: status === 'success',
        message,
        mensaje: message,
        data: payload.data && typeof payload.data === 'object' ? payload.data : {},
      };
    }

    if (typeof payload.ok === 'boolean') {
      const message = payload.message || payload.mensaje || fallbackMessage;
      return {
        status: payload.ok ? 'success' : 'error',
        ok: payload.ok,
        message,
        mensaje: message,
        data: payload.data && typeof payload.data === 'object' ? payload.data : {},
      };
    }
  }

  return {
    status: 'error',
    ok: false,
    message: fallbackMessage,
    mensaje: fallbackMessage,
    data: {},
  };
};

const requestCandidates = async (candidates, options = {}) => {
  let lastError = null;

  for (let i = 0; i < candidates.length; i += 1) {
    const path = candidates[i];

    try {
      const response = await fetch(`${BASE}${path}`, {
        credentials: 'include',
        ...options,
      });

      const payload = await response.json().catch(() => ({}));
      const envelope = normalizeEnvelope(payload);

      if (response.status === 404 && i < candidates.length - 1) {
        continue;
      }

      return {
        ok: response.ok && envelope.status === 'success',
        status: response.status,
        data: envelope,
        path,
      };
    } catch (error) {
      lastError = error;
    }
  }

  const message =
    lastError instanceof Error
      ? lastError.message
      : 'No se pudo establecer conexion con el backend.';

  return {
    ok: false,
    status: 0,
    data: {
      status: 'error',
      ok: false,
      message,
      mensaje: message,
      data: {},
    },
  };
};

const getJson = (candidates, query = {}) =>
  requestCandidates(buildCandidatesWithQuery(candidates, query), {
    method: 'GET',
  });

const postJson = (candidates, body = {}) =>
  requestCandidates(candidates, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(body),
  });

const setSessionUser = (user) => {
  if (!user || typeof user !== 'object') {
    sessionStorage.removeItem(SESSION_KEY);
    return;
  }

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

export const fetchSession = async () => {
  const response = await getJson(ENDPOINTS.session);
  const envelope = response.data || {};

  if (response.ok && envelope.status === 'success') {
    const user = envelope.data?.user || null;
    setSessionUser(user);
    return response;
  }

  setSessionUser(null);
  return response;
};

export const login = async (email, password) => {
  const response = await postJson(ENDPOINTS.login, { email, password });
  const envelope = response.data || {};

  if (response.ok && envelope.status === 'success') {
    const user = envelope.data?.user || null;
    setSessionUser(user);
  } else {
    setSessionUser(null);
  }

  return response;
};

export const register = async (payload) => {
  return postJson(ENDPOINTS.register, payload);
};

export const logout = async () => {
  await postJson(ENDPOINTS.logout, {});
  setSessionUser(null);
};

export const listDocumentos = async (filters = {}) => {
  const query = {};

  if (filters.q) {
    query.q = filters.q;
  }
  if (filters.estado) {
    query.estado = filters.estado;
  }
  if (filters.limit) {
    query.limit = String(filters.limit);
  }
  if (filters.offset) {
    query.offset = String(filters.offset);
  }

  return getJson(ENDPOINTS.documentosList, query);
};

export const createDocumento = async (payload) => {
  return postJson(ENDPOINTS.documentosCreate, payload);
};

export const updateDocumento = async (payload) => {
  return postJson(ENDPOINTS.documentosUpdate, payload);
};

export const deleteDocumento = async (id) => {
  return postJson(ENDPOINTS.documentosDelete, { id });
};

export const emitirDocumento = async (id) => {
  return postJson(ENDPOINTS.documentosEmitir, { id });
};

export const revocarDocumento = async (id, motivo = '') => {
  return postJson(ENDPOINTS.documentosRevocar, { id, motivo });
};

export const listBitacora = async (filters = {}) => {
  const query = {};

  if (filters.limit) {
    query.limit = String(filters.limit);
  }
  if (filters.documento_id) {
    query.documento_id = String(filters.documento_id);
  }

  return getJson(ENDPOINTS.bitacoraList, query);
};

export const listUsuarios = async () => {
  return getJson(ENDPOINTS.usuariosList);
};

export const desactivarUsuario = async (id) => {
  return postJson(ENDPOINTS.usuariosDesactivar, { id });
};

export const cambiarRolUsuario = async (id, rol) => {
  return postJson(ENDPOINTS.usuariosCambiarRol, { id, rol });
};

export const consultarDocumentoPublico = async (identificador) => {
  const clean = String(identificador || '').trim();
  if (!clean) {
    return {
      ok: false,
      status: 400,
      data: {
        status: 'error',
        ok: false,
        message: 'Debe proporcionar un identificador.',
        mensaje: 'Debe proporcionar un identificador.',
        data: {},
      },
    };
  }

  return getJson(ENDPOINTS.consultaQr, { token: clean });
};

export const consultarFolio = async (folio) => {
  return consultarDocumentoPublico(folio);
};

export const calcularSHA256 = async (file) => {
  const buffer = await file.arrayBuffer();
  const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
  const hashArray = Array.from(new Uint8Array(hashBuffer));
  return hashArray.map((b) => b.toString(16).padStart(2, '0')).join('');
};
