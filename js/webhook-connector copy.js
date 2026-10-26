```
/* ==========================================================================
   TECNIEMPACK S.A.S. - SCRIPT DE CONEXIÓN A WEBHOOK (n8n -&gt; TWENTY CRM)
   ========================================================================== */

const CONFIG_TECNIEMPACK = {
    // URL del Webhook oficial de producción en n8n
    N8N_WEBHOOK_URL: 'https://n8n.tecniempack.com/webhook/lead-b2b-v2',
    
    // Proxy de respaldo PHP en cPanel (en caso de fallo de red)
    PHP_PROXY_URL: '/api-procesar-lead.php',
    
    // Línea directa WhatsApp B2B Tecniempack (+57 316 5028834)
    WHATSAPP_NUMERO: '573165028834',
    
    // Tiempo límite de espera (8 segundos)
    TIMEOUT_MS: 8000
};

/**
 * Captura automática de parámetros UTM para atribución publicitaria (Google Ads/LinkedIn)
 */
function obtenerParametrosUTM() {
    const urlParams = new URLSearchParams(window.location.search);
    return {
        utm_source: urlParams.get('utm_source') || 'organico_directo',
        utm_medium: urlParams.get('utm_medium') || 'web_corporativa',
        utm_campaign: urlParams.get('utm_campaign') || 'posicionamiento_v2',
        utm_content: urlParams.get('utm_content') || 'formulario_requerimientos'
    };
}

/**
 * Enrutamiento inteligente según el avatar corporativo asignado
 */
function determinarAvatarAsignado(tipoEmpaque, mensaje) {
    const texto = (tipoEmpaque + " " + mensaje).toLowerCase();
    
    if (texto.includes('resina') || texto.includes('pcr') || texto.includes('verde') || texto.includes('1407')) {
        return { avatar: 'Elena', rol: 'Especialista en Línea Verde Eco &amp; Res. 1407' };
    } else if (texto.includes('micras') || texto.includes('calibre') || texto.includes('flexograf') || texto.includes('hdpe') || texto.includes('ldpe')) {
        return { avatar: 'Ing. Carlos', rol: 'Asesor Técnico de Ingeniería de Empaques' };
    } else {
        return { avatar: 'Sofia', rol: 'Customer Success &amp; Atención Inbound B2B' };
    }
}

/**
 * Manejador principal del formulario con Timeout y Fallback
 */
async function handleFormSubmit(event) {
    event.preventDefault();

    const form = document.getElementById('contact-form');
    const submitBtn = form.querySelector('button[type="submit"]');
    const successMsg = document.getElementById('contact-success');
    
    // Estado de Carga UX
    const textoOriginalBtn = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = `<i></i> PROCESANDO REQUERIMIENTOS...`;

    // Recolección de Datos
    const nombre = document.getElementById('nombre').value.trim();
    const empresa = document.getElementById('empresa').value.trim();
    const email = document.getElementById('email').value.trim();
    const telefono = document.getElementById('telefono').value.trim();
    const tipoEmpaque = document.getElementById('tipo_empaque').value;
    const mensaje = document.getElementById('mensaje').value.trim();
    const utm = obtenerParametrosUTM();
    const avatarInfo = determinarAvatarAsignado(tipoEmpaque, mensaje);

    // Estructura del Payload para n8n -&gt; Twenty CRM
    const payloadLead = {
        origen: "Portal Web Corporativo Tecniempack v2.0",
        fecha_registro: new Date().toISOString(),
        prospecto: {
            nombre_completo: nombre,
            empresa_razon_social: empresa,
            email_corporativo: email,
            telefono_whatsapp: telefono
        },
        requerimiento_tecnico: {
            linea_producto: tipoEmpaque,
            especificaciones_detalle: mensaje,
            avatar_sugerido: avatarInfo.avatar,
            rol_avatar: avatarInfo.rol
        },
        trazabilidad_marketing: utm
    };

    // 1. Registro de evento en DataLayer (Google Tag Manager / GA4)
    if (window.dataLayer) {
        window.dataLayer.push({
            'event': 'generate_lead',
            'lead_type': 'Diagnostico B2B',
            'empresa': empresa,
            'tipo_empaque': tipoEmpaque,
            'avatar_asignado': avatarInfo.avatar
        });
    }

    // 2. Envío HTTP POST al Webhook con AbortController
    const controller = new AbortController();
    const timeoutId = setTimeout(() =&gt; controller.abort(), CONFIG_TECNIEMPACK.TIMEOUT_MS);

    try {
        const response = await fetch(CONFIG_TECNIEMPACK.N8N_WEBHOOK_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Tecniempack-Source': 'Web-Form-v2'
            },
            body: JSON.stringify(payloadLead),
            signal: controller.signal
        });

        clearTimeout(timeoutId);

        if (response.ok) {
            mostrarExito(form, successMsg, avatarInfo, empresa);
        } else {
            await enviarProxyPHP(payloadLead, form, successMsg, avatarInfo, empresa);
        }
    } catch (error) {
        clearTimeout(timeoutId);
        // Fallback secundario vía PHP o contingencia directa a WhatsApp
        try {
            await enviarProxyPHP(payloadLead, form, successMsg, avatarInfo, empresa);
        } catch (proxyError) {
            activarFallbackWhatsApp(payloadLead, form, successMsg, avatarInfo);
        }
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = textoOriginalBtn;
    }
}

async function enviarProxyPHP(payload, form, successMsg, avatarInfo, empresa) {
    const response = await fetch(CONFIG_TECNIEMPACK.PHP_PROXY_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    if (response.ok) {
        mostrarExito(form, successMsg, avatarInfo, empresa);
    } else {
        throw new Error('Proxy PHP no disponible');
    }
}

function mostrarExito(form, successMsg, avatarInfo, empresa) {
    form.reset();
    form.classList.add('hidden');
    
    successMsg.innerHTML = `
        <div>
            <div>
                <div>
                    ${avatarInfo.avatar.charAt(0)}
                </div>
                <div>
                    <h4>
                        ¡Solicitud Recibida Exitosamente!
                    </h4>
                    <p>
                        Hemos registrado los requerimientos de tu empresa. Tu caso ha sido asignado a <strong>${avatarInfo.avatar}</strong> (${avatarInfo.rol}), quien evaluará las especificaciones técnicas y se pondrá en contacto en menos de <strong>15 minutos</strong>.
                    </p>
                    <a href="https://wa.me/${CONFIG_TECNIEMPACK.WHATSAPP_NUMERO}?text=${encodeURIComponent('Hola ' + avatarInfo.avatar + ', acabo de enviar el formulario web con los requerimientos de ' + empresa)}" target="_blank">
                        <i></i>
                        <span>Acelerar atención con ${avatarInfo.avatar} vía WhatsApp</span>
                    </a>
                </div>
            </div>
        </div>
    `;
    successMsg.classList.remove('hidden');
}

function activarFallbackWhatsApp(payload, form, successMsg, avatarInfo) {
    const msgWhatsApp = `*NUEVO LEAD B2B - PORTAL WEB TECNIEMPACK*\n\n` +
        `🏢 *Empresa:* ${payload.prospecto.empresa_razon_social}\n` +
        `👤 *Contacto:* ${payload.prospecto.nombre_completo}\n` +
        `📧 *Email:* ${payload.prospecto.email_corporativo}\n` +
        `📱 *Teléfono:* ${payload.prospecto.telefono_whatsapp}\n` +
        `📦 *Línea:* ${payload.requerimiento_tecnico.linea_producto}\n` +
        `📝 *Especificaciones:* ${payload.requerimiento_tecnico.especificaciones_detalle}\n\n` +
        `🎯 *Avatar Asignado:* ${avatarInfo.avatar}`;

    const urlWA = `https://wa.me/${CONFIG_TECNIEMPACK.WHATSAPP_NUMERO}?text=${encodeURIComponent(msgWhatsApp)}`;
    
    mostrarExito(form, successMsg, avatarInfo, payload.prospecto.empresa_razon_social);
    window.open(urlWA, '_blank');
}

```