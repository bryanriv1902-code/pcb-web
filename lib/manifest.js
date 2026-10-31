/* =============================================================
   PCB Soluciones Digitales — datos de la marca
   Este es el ÚNICO archivo que hay que tocar para:
   - Poner los 4 videos de proyectos.
   - Añadir el número de WhatsApp y el correo de contacto.
   ============================================================= */
(function () {
  "use strict";
  window.__BRAND__ = {
    nombre: "PCB Soluciones Digitales",
    precio: "249.99",

    // Número de WhatsApp comercial con código de país, solo dígitos.
    // Ejemplo: "15714236789". Mientras esté vacío, los botones de WhatsApp llevan a la sección de contacto.
    whatsapp: "14016167029",
    whatsappMensaje: "Hola, quiero información sobre una página web para mi negocio.",

    // Enlace de tu página de Facebook. Ejemplo: "https://www.facebook.com/pcbsolucionesdigitales"
    facebook: "https://www.facebook.com/profile.php?id=61595861410132",

    // Correo de contacto visible (opcional).
    email: "",

    // Forma de los videos: "16/9" (grabación de pantalla de computadora), "16/10" o "9/16" (celular, vertical).
    formatoVideo: "16/9",

    // Los 4 espacios de video de la portada.
    // Para cada proyecto:
    //   titulo   → nombre del proyecto (ej. "Web para empresa de limpieza")
    //   sector   → tipo de negocio (ej. "Limpieza")
    //   vista    → video corto y liviano que se repite en la tarjeta (5–10 s, sin sonido). Ej. "assets/videos/proyecto-1-vista.mp4"
    //   completo → video completo que se abre al hacer clic. Ej. "assets/videos/proyecto-1.mp4"
    //   portada  → imagen de vista previa (opcional). Ej. "assets/videos/proyecto-1.webp"
    //   imagen   → si el proyecto es una captura en lugar de un video: imagen grande que se abre al hacer clic
    //              (la "portada" se usa en la tarjeta). Ej. "assets/videos/proyecto-2.webp"
    // Si un espacio queda vacío se muestra como "Próximamente". Usa solo proyectos reales.
    videos: [
      { titulo: "3 Plumas + Yamy Bakes", sector: "Eventos, catering y postres", vista: "assets/videos/proyecto-1-vista.mp4", completo: "assets/videos/proyecto-1.mp4", portada: "assets/videos/proyecto-1.webp" },
      { titulo: "High Quality Home Improvements", sector: "Construcción residencial", vista: "", completo: "", portada: "assets/videos/proyecto-2-tarjeta.webp", imagen: "assets/videos/proyecto-2.webp" },
      { titulo: "", sector: "", vista: "", completo: "", portada: "" },
      { titulo: "", sector: "", vista: "", completo: "", portada: "" }
    ]
  };
})();
