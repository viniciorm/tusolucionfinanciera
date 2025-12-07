// Validación de email con regex
const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

// Validación de teléfono (debe tener 8 dígitos después de +569)
const phoneRegex = /^[0-9]{8}$/;

/**
 * Valida el formato del email
 * @param {string} email - Email a validar
 * @returns {boolean} - true si es válido, false si no
 */
function validateEmail(email) {
    return emailRegex.test(email);
}

/**
 * Valida el formato del teléfono
 * @param {string} phone - Teléfono a validar (solo los 8 dígitos)
 * @returns {boolean} - true si es válido, false si no
 */
function validatePhone(phone) {
    return phoneRegex.test(phone);
}

/**
 * Muestra un mensaje de error en un campo
 * @param {HTMLElement} field - Campo del formulario
 * @param {string} message - Mensaje de error
 */
function showError(field, message) {
    const errorSpan = field.parentElement.querySelector('.error-message');
    if (errorSpan) {
        errorSpan.textContent = message;
        errorSpan.classList.remove('hidden');
        field.style.borderColor = '#ef4444'; // Rojo para error
    }
}

/**
 * Oculta el mensaje de error de un campo
 * @param {HTMLElement} field - Campo del formulario
 */
function hideError(field) {
    const errorSpan = field.parentElement.querySelector('.error-message');
    if (errorSpan) {
        errorSpan.classList.add('hidden');
        field.style.borderColor = '#003A8C'; // Azul profundo normal
    }
}

/**
 * Valida todos los campos del formulario
 * @returns {boolean} - true si todos los campos son válidos
 */
function validateForm() {
    let isValid = true;
    
    // Validar nombre
    const nombre = document.getElementById('nombre');
    if (!nombre.value.trim()) {
        showError(nombre, 'El nombre es obligatorio');
        isValid = false;
    } else {
        hideError(nombre);
    }
    
    // Validar email
    const email = document.getElementById('email');
    if (!email.value.trim()) {
        showError(email, 'El email es obligatorio');
        isValid = false;
    } else if (!validateEmail(email.value)) {
        showError(email, 'Por favor ingresa un email válido');
        isValid = false;
    } else {
        hideError(email);
    }
    
    // Validar teléfono
    const telefono = document.getElementById('telefono');
    if (!telefono.value.trim()) {
        showError(telefono, 'El teléfono es obligatorio');
        isValid = false;
    } else if (!validatePhone(telefono.value)) {
        showError(telefono, 'El teléfono debe tener 8 dígitos');
        isValid = false;
    } else {
        hideError(telefono);
    }
    
    // Validar APV actual
    const apvActual = document.getElementById('apv-actual');
    if (!apvActual.value) {
        showError(apvActual, 'Por favor selecciona una opción');
        isValid = false;
    } else {
        hideError(apvActual);
    }
    
    // Validar optimizar
    const optimizar = document.getElementById('optimizar');
    if (!optimizar.value) {
        showError(optimizar, 'Por favor selecciona una opción');
        isValid = false;
    } else {
        hideError(optimizar);
    }
    
    return isValid;
}

/**
 * Verifica el campo honeypot para detectar spam
 * @returns {boolean} - true si es spam (honeypot lleno), false si no
 */
function checkHoneypot() {
    const honeypot = document.querySelector('input[name="website"]');
    return honeypot && honeypot.value.trim() !== '';
}

/**
 * Maneja el envío del formulario
 * @param {Event} e - Evento del formulario
 */
function handleSubmit(e) {
    e.preventDefault();
    
    // Verificar honeypot (protección anti-spam)
    if (checkHoneypot()) {
        console.log('Spam detectado - formulario no enviado');
        return;
    }
    
    // Validar todos los campos
    if (!validateForm()) {
        return;
    }
    
    // Si todo es válido, simular envío y mostrar mensaje de éxito
    const form = document.getElementById('contactForm');
    const successMessage = document.getElementById('successMessage');
    const submitButton = form.querySelector('button[type="submit"]');
    
    // Ocultar formulario y mostrar mensaje de éxito
    form.style.display = 'none';
    successMessage.classList.remove('hidden');
    
    // Scroll suave al mensaje de éxito
    successMessage.scrollIntoView({ behavior: 'smooth', block: 'center' });
    
    // Aquí se podría agregar la lógica para enviar los datos a un servidor
    // Por ejemplo: fetch('/api/contact', { method: 'POST', body: formData })
    
    console.log('Formulario enviado correctamente');
}

/**
 * Inicializa los event listeners cuando el DOM está listo
 */
function init() {
    // Event listener para el formulario
    const form = document.getElementById('contactForm');
    if (form) {
        form.addEventListener('submit', handleSubmit);
    }
    
    // Validación en tiempo real para email
    const email = document.getElementById('email');
    if (email) {
        email.addEventListener('blur', function() {
            if (this.value.trim() && !validateEmail(this.value)) {
                showError(this, 'Por favor ingresa un email válido');
            } else {
                hideError(this);
            }
        });
    }
    
    // Validación en tiempo real para teléfono
    const telefono = document.getElementById('telefono');
    if (telefono) {
        // Solo permitir números
        telefono.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
        
        telefono.addEventListener('blur', function() {
            if (this.value.trim() && !validatePhone(this.value)) {
                showError(this, 'El teléfono debe tener 8 dígitos');
            } else {
                hideError(this);
            }
        });
    }
    
    // Validación en tiempo real para nombre
    const nombre = document.getElementById('nombre');
    if (nombre) {
        nombre.addEventListener('blur', function() {
            if (!this.value.trim()) {
                showError(this, 'El nombre es obligatorio');
            } else {
                hideError(this);
            }
        });
    }
    
    // Validación en tiempo real para selects
    const selects = document.querySelectorAll('select[required]');
    selects.forEach(select => {
        select.addEventListener('change', function() {
            if (this.value) {
                hideError(this);
            }
        });
    });
}

// Inicializar cuando el DOM esté listo
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

