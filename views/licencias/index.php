<section id="license-section" class="license-section" hidden aria-label="Licencia del sistema">
    <details id="license-details">
        <summary>Licencia y activación <span id="license-badge" class="badge">Consultando…</span></summary>
        <div class="license-content">
            <div>
                <h2 id="license-title">Licencia de esta instalación</h2>
                <p id="license-description" role="status" aria-live="polite"></p>
                <dl class="license-facts">
                    <div><dt>Plan</dt><dd id="license-plan">—</dd></div>
                    <div><dt>Negocio de la licencia</dt><dd id="license-business">—</dd></div>
                    <div><dt>Vencimiento (Ciudad de México)</dt><dd id="license-expires">—</dd></div>
                    <div id="license-grace-row" hidden><dt>Fin del periodo de gracia</dt><dd id="license-grace">—</dd></div>
                </dl>
                <label for="license-installation">Código de instalación para solicitar tu clave</label>
                <input id="license-installation" readonly spellcheck="false" autocomplete="off">
                <div class="form-actions"><button id="license-copy" class="secondary" type="button">Copiar código</button><button id="license-refresh" class="secondary" type="button">Actualizar licencia</button></div>
                <p class="hint">La licencia cubre este servidor y las cajas conectadas a él. Una renovación conserva tus ventas e inventario.</p>
            </div>
            <div>
                <div id="license-admin" hidden>
                    <h2>Activar o renovar</h2>
                    <p>Solicita una clave mensual de 30 días o anual de 365 días y pégala completa aquí.</p>
                    <form id="license-form">
                        <label for="license-key">Clave de licencia</label>
                        <textarea id="license-key" rows="4" maxlength="4096" required spellcheck="false" autocomplete="off" placeholder="PV1.…"></textarea>
                        <button id="license-activate" class="primary" type="submit">Activar clave</button>
                    </form>
                    <div id="license-trial-area" hidden>
                        <h3>Prueba el sistema durante 15 días</h3>
                        <p class="hint">Empieza al pulsar el botón y se ofrece una sola vez por instalación. No requiere internet ni datos de pago.</p>
                        <button id="license-trial" class="secondary" type="button">Iniciar prueba de 15 días</button>
                    </div>
                </div>
                <p id="license-operator-note" class="hint" hidden>Solicita al administrador del negocio que active la prueba o introduzca la clave de renovación.</p>
                <p class="hint">Al vencer la vigencia y la gracia aplicable, puedes consultar datos, hacer respaldos, descartar pendientes y cerrar una caja abierta. Para registrar operaciones nuevas necesitas renovar.</p>
                <p id="license-message" class="message" role="status" aria-live="polite"></p>
            </div>
        </div>
    </details>
</section>
