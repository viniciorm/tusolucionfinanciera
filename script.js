const form = document.getElementById('contactForm');
const result = document.getElementById('result');

form.addEventListener('submit', async (e) => {
  e.preventDefault();

  const formData = new FormData(form);

  const response = await fetch(form.action, {
    method: "POST",
    body: formData
  });

  const data = await response.json();

  if (data.success) {
    result.innerText = "Gracias, hemos recibido tu solicitud.";
    form.reset();
  } else {
    result.innerText = "Hubo un error al enviar el formulario. Intenta nuevamente.";
  }
});
