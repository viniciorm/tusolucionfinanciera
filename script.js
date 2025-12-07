const form = document.getElementById('contactForm');
const result = document.getElementById('result');

form.addEventListener('submit', async (e) => {
  e.preventDefault();

  const formData = new FormData(form);

  // Convertir datos a JSON para Google Sheets
  const jsonData = {};
  formData.forEach((value, key) => jsonData[key] = value);

  // 1) Enviar a Web3Forms
  const web3Response = await fetch("https://api.web3forms.com/submit", {
    method: "POST",
    body: formData
  });

  // 2) Enviar paralelo a Google Sheets (Apps Script)
  fetch("https://script.google.com/macros/s/AKfycbzIF3PxQ_3v8v0jy0JDIscABDIgne8S_OyW4hxhc5dAnqcQOAUjoiXogQ6FM6Z44gZeUg/exec", {
    method: "POST",
    body: JSON.stringify(jsonData)
  });

  const web3Json = await web3Response.json();

  if (web3Json.success) {
    result.innerText = "Gracias, hemos recibido tu solicitud.";
    form.reset();
  } else {
    result.innerText = "Hubo un error al enviar el formulario. Intenta nuevamente.";
  }
});
