# Landing Page - APV Régimen B

Landing page profesional y moderna para **Tu Solución Financiera**, enfocada en la estrategia financiera para maximizar el beneficio tributario 2025 mediante el APV (Ahorro Previsional Voluntario) en Régimen B.

## 🎨 Características

- **Diseño moderno y responsive**: Optimizado para móvil, tablet y desktop
- **Manual de marca aplicado**: Colores y tipografías según la identidad de Tu Solución Financiera
- **Header con logo**: Header sticky con logo centrado y fondo azul profundo
- **Hero con imagen ejecutiva**: Layout de dos columnas en desktop con imagen profesional
- **Formulario integrado**: Con Web3Forms y webhook a Google Sheets
- **Protección anti-spam**: Honeypot invisible para prevenir bots
- **Scroll suave**: Navegación fluida entre secciones
- **Íconos SVG inline**: Sin dependencias externas para íconos

## 🎨 Paleta de Colores

- **Azul Profundo**: `#003A8C`
- **Dorado Suave**: `#D4A857`
- **Azul Royal**: `#0057D9`
- **Dorado Profundo**: `#B38A3C`
- **Gris Claro**: `#F5F6F7`

## 📋 Estructura

La landing page incluye las siguientes secciones:

1. **Header**: Logo centrado con fondo azul profundo, sticky al hacer scroll
2. **Hero**: Pantalla principal con layout de dos columnas (texto + imagen ejecutiva) y CTA destacado
3. **Beneficios APV**: Tres beneficios principales del APV en Régimen B
4. **Fecha Clave**: Urgencia del beneficio (hasta 26 de diciembre)
5. **Por qué con nosotros**: Ventajas del servicio
6. **Formulario de Contacto**: Integrado con Web3Forms y Google Sheets
7. **Footer**: Información de contacto y enlaces legales

## 🛠️ Tecnologías

- **HTML5**: Estructura semántica
- **TailwindCSS**: Framework CSS via CDN
- **JavaScript Vanilla**: Manejo del formulario y envío a Web3Forms
- **Google Fonts**: Tipografía Inter (Bold, SemiBold, Regular)
- **Web3Forms**: Servicio de formularios sin backend
- **Google Sheets**: Integración via webhook para gestión de leads

## 📁 Archivos

### Páginas principales
- `APV-regimen-b.html`: Página principal de la landing
- `politica-privacidad.html`: Página de política de privacidad
- `terminos.html`: Página de términos y condiciones

### Scripts
- `script.js`: Manejo del formulario y envío a Web3Forms

### Assets
- `assets/logo.png`: Logo de Tu Solución Financiera
- `assets/ejecutiva.png`: Imagen ejecutiva para el hero
- `assets/favicon.png`: Favicon del sitio

## 🚀 Uso

1. Abre `APV-regimen-b.html` en tu navegador
2. La página funciona completamente sin servidor (archivos estáticos)
3. El formulario está integrado con Web3Forms y envía datos automáticamente a Google Sheets

## 📝 Formulario de Contacto

### Integración Web3Forms

El formulario está completamente integrado con:
- **Web3Forms**: Servicio de gestión de formularios
- **Access Key**: `8b570fa8-b326-4965-8648-75715212ee50`
- **Google Sheets Webhook**: Los datos se envían automáticamente a una hoja de cálculo

### Campos del Formulario

- **Nombre**: Campo de texto obligatorio
- **Email**: Validación de formato de email
- **Teléfono**: Formato chileno (+569) seguido de 8 dígitos
- **¿Tienes APV actualmente?**: Select (Sí/No)
- **¿Deseas optimizar tu base tributable?**: Select (Sí/No)

### Validaciones

- **Email**: Validación HTML5 con tipo `email`
- **Teléfono**: Patrón `^(\+569)(\d{8})$` para formato chileno
- **Campos obligatorios**: Todos los campos son requeridos
- **Honeypot**: Campo invisible para protección anti-spam
- **Mensaje de resultado**: Feedback visual al enviar (éxito/error)

## 📱 Responsive

La página está completamente optimizada para:
- 📱 Móviles (320px+)
- 📱 Tablets (768px+)
- 💻 Desktop (1024px+)

### Comportamiento Responsive

- **Header**: Logo centrado en todas las pantallas
- **Hero**: 
  - Móvil: Imagen arriba, texto abajo
  - Desktop: Dos columnas (texto izquierda, imagen derecha)
- **Formulario**: Adaptado a diferentes tamaños de pantalla
- **Footer**: Layout flexible según el tamaño de pantalla

## 🔒 Seguridad

- **Honeypot**: Campo invisible para detectar bots
- **Validación del lado del cliente**: Previene envíos inválidos
- **Web3Forms**: Manejo seguro de datos del formulario

## 📝 Notas

- El formulario está completamente funcional y envía datos a Web3Forms y Google Sheets
- Los enlaces de "Política de privacidad" y "Términos y condiciones" están activos
- Las imágenes deben estar en la carpeta `assets/` para que se muestren correctamente

## 📧 Contacto

Para más información: contacto@tusolucionfinanciera.cl

---

© 2025 Tu Solución Financiera. Todos los derechos reservados.
