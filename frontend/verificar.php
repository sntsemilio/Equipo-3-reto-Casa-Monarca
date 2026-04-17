<?php
declare(strict_types=1);
?><!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Casa Monarca | Verificación Pública</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="css/styles.css" />
</head>
<body class="verify-body">
  <main class="verify-layout">
    <section class="verify-card">
      <p class="verify-kicker">Verificación pública</p>
      <h1>Consultar autenticidad documental</h1>
      <p class="muted-text">Ingresa el token QR, folio o identificador para validar la firma institucional.</p>

      <div class="verify-form-row">
        <input id="verify-input" class="form-control" type="text" placeholder="Ejemplo: CM-000123 o token QR" />
        <button id="btn-verify" class="btn btn-primary" type="button">Validar</button>
      </div>

      <div id="verify-result" class="verify-result hidden">
        <div class="verify-status-line">
          <span id="verify-state-pill" class="badge">Estado</span>
          <span id="verify-auth-pill" class="badge">Autenticidad</span>
        </div>

        <dl class="verify-grid">
          <div>
            <dt>Folio</dt>
            <dd id="result-folio">-</dd>
          </div>
          <div>
            <dt>Firma válida</dt>
            <dd id="result-signature">-</dd>
          </div>
          <div>
            <dt>Fecha de emisión</dt>
            <dd id="result-issued">-</dd>
          </div>
          <div>
            <dt>Fecha de revocación</dt>
            <dd id="result-revoked">-</dd>
          </div>
        </dl>

        <p id="result-message" class="verify-message"></p>
      </div>

      <div class="verify-links">
        <a href="index.html">Volver a inicio</a>
        <a href="dashboard.html">Ir al panel</a>
      </div>
    </section>
  </main>

  <script type="module">
    import { consultarDocumentoPublico } from './js/api.js';

    const input = document.getElementById('verify-input');
    const button = document.getElementById('btn-verify');
    const resultBox = document.getElementById('verify-result');
    const statePill = document.getElementById('verify-state-pill');
    const authPill = document.getElementById('verify-auth-pill');
    const folioEl = document.getElementById('result-folio');
    const signatureEl = document.getElementById('result-signature');
    const issuedEl = document.getElementById('result-issued');
    const revokedEl = document.getElementById('result-revoked');
    const messageEl = document.getElementById('result-message');

    const formatDate = (value) => {
      if (!value) return 'No aplica';
      const parsed = new Date(value);
      if (Number.isNaN(parsed.getTime())) return 'No aplica';
      return parsed.toLocaleString('es-MX', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
      });
    };

    const mapState = (state) => {
      if (state === 'emitido') {
        return { label: 'Emitido', className: 'badge-emitido' };
      }
      if (state === 'revocado') {
        return { label: 'Revocado', className: 'badge-revocado' };
      }
      return { label: 'No encontrado', className: 'badge-borrador' };
    };

    const mapAuth = (isAuthentic) => {
      if (isAuthentic) {
        return { label: 'Auténtico', className: 'badge-emitido' };
      }
      return { label: 'No verificable', className: 'badge-revocado' };
    };

    const setBusy = (busy) => {
      if (busy) {
        button.classList.add('is-loading');
        button.disabled = true;
      } else {
        button.classList.remove('is-loading');
        button.disabled = false;
        button.textContent = 'Validar';
      }
    };

    const renderResult = (payload, found) => {
      const stateMap = mapState(String(payload.estado || 'no_encontrado'));
      const authMap = mapAuth(Boolean(payload.es_autentico));

      statePill.className = `badge ${stateMap.className}`;
      statePill.textContent = stateMap.label;

      authPill.className = `badge ${authMap.className}`;
      authPill.textContent = authMap.label;

      folioEl.textContent = payload.folio || 'No disponible';
      signatureEl.textContent = payload.firma_valida ? 'Sí' : 'No';
      issuedEl.textContent = formatDate(payload.fecha_emision);
      revokedEl.textContent = formatDate(payload.fecha_revocacion);

      if (!found) {
        messageEl.textContent = 'No se encontró un documento asociado con el identificador proporcionado.';
      } else if (payload.estado === 'revocado') {
        messageEl.textContent = 'El documento fue revocado. Su firma puede ser técnicamente válida pero ya no tiene vigencia operativa.';
      } else if (payload.es_autentico) {
        messageEl.textContent = 'Documento emitido y firma institucional verificada correctamente.';
      } else {
        messageEl.textContent = 'No fue posible comprobar integridad criptográfica del documento.';
      }

      resultBox.classList.remove('hidden');
    };

    const runVerify = async () => {
      const identifier = input.value.trim();
      if (!identifier) {
        messageEl.textContent = 'Debes ingresar un identificador válido.';
        resultBox.classList.remove('hidden');
        return;
      }

      setBusy(true);
      const response = await consultarDocumentoPublico(identifier);
      const envelope = response.data || {};
      setBusy(false);

      if (!response.ok && response.status !== 404) {
        messageEl.textContent = envelope.message || 'No fue posible consultar el servicio de verificación.';
        resultBox.classList.remove('hidden');
        return;
      }

      const payload = envelope.data || {};
      renderResult(payload, Boolean(payload.encontrado));
    };

    button.addEventListener('click', runVerify);
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        runVerify();
      }
    });

    const params = new URLSearchParams(window.location.search);
    const preset = params.get('token') || params.get('folio') || params.get('id');
    if (preset) {
      input.value = preset;
      runVerify();
    }
  </script>
</body>
</html>
