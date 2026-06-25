(function () {
  "use strict";

  // ─── COORDENADAS DINÁMICAS ─────────────────────────────────────────────────
  function getCoords() {
    var c = window.cvPdfCoords || {};
    return {
      firmaPage: typeof c.firma_page === "number" ? c.firma_page : 0,
      checkPage: typeof c.check_page === "number" ? c.check_page : 0,
      firma5Page: typeof c.firma5_page === "number" ? c.firma5_page : 4,
      firmaP1X: typeof c.firma_p1_x === "number" ? c.firma_p1_x : 65,
      firmaP1Y: typeof c.firma_p1_y === "number" ? c.firma_p1_y : 110,
      firmaP1W: typeof c.firma_p1_w === "number" ? c.firma_p1_w : 240,
      firmaP1H: typeof c.firma_p1_h === "number" ? c.firma_p1_h : 65,
      check1X: typeof c.check1_x === "number" ? c.check1_x : 53,
      check1Y: typeof c.check1_y === "number" ? c.check1_y : 247,
      check2X: typeof c.check2_x === "number" ? c.check2_x : 53,
      check2Y: typeof c.check2_y === "number" ? c.check2_y : 219,
      check3X: typeof c.check3_x === "number" ? c.check3_x : 53,
      check3Y: typeof c.check3_y === "number" ? c.check3_y : 191,
      firmaP5X: typeof c.firma_p5_x === "number" ? c.firma_p5_x : 65,
      firmaP5Y: typeof c.firma_p5_y === "number" ? c.firma_p5_y : 195,
      firmaP5W: typeof c.firma_p5_w === "number" ? c.firma_p5_w : 240,
      firmaP5H: typeof c.firma_p5_h === "number" ? c.firma_p5_h : 65,
      checkP5X: typeof c.check_p5_x === "number" ? c.check_p5_x : 53,
      checkP5Y: typeof c.check_p5_y === "number" ? c.check_p5_y : 311,
      fechaP5X: typeof c.fecha_p5_x === "number" ? c.fecha_p5_x : 355,
      fechaP5Y: typeof c.fecha_p5_y === "number" ? c.fecha_p5_y : 164,
    };
  }

  // ─── LÍMITE DE PUESTOS ─────────────────────────────────────────────────────
  function initJobLimits() {
    var lists = document.querySelectorAll(
      ".cv-checkbox-list[data-max-options]",
    );
    for (var i = 0; i < lists.length; i += 1) {
      setupList(lists[i]);
    }
  }

  function setupList(list) {
    var max = parseInt(list.getAttribute("data-max-options"), 10);
    var checkboxes = list.querySelectorAll('input[type="checkbox"]');
    var message = list.parentNode.querySelector(".cv-limit-message");

    function updateState(changedCheckbox) {
      var selected = 0;
      for (var i = 0; i < checkboxes.length; i += 1) {
        if (checkboxes[i].checked) selected += 1;
      }
      if (selected > max && changedCheckbox) {
        changedCheckbox.checked = false;
        selected -= 1;
      }
      for (var j = 0; j < checkboxes.length; j += 1) {
        checkboxes[j].disabled = !checkboxes[j].checked && selected >= max;
      }
      if (message) {
        message.textContent =
          selected >= max
            ? "Has seleccionado el máximo de " + max + " puestos."
            : "";
      }
    }

    for (var k = 0; k < checkboxes.length; k += 1) {
      checkboxes[k].addEventListener("change", function () {
        updateState(this);
      });
    }
    updateState();
  }

  function clearJobLimitDisabling(form) {
    var checkboxes = form.querySelectorAll(
      '.cv-checkbox-list[data-max-options] input[type="checkbox"]',
    );
    for (var i = 0; i < checkboxes.length; i += 1) {
      checkboxes[i].disabled = false;
    }
    var messages = form.querySelectorAll(".cv-limit-message");
    for (var j = 0; j < messages.length; j += 1) {
      messages[j].textContent = "";
    }
  }

  function restoreSubmitButton(submitButton, originalLabel) {
    if (submitButton) {
      submitButton.disabled = false;
      submitButton.textContent = originalLabel;
    }
  }

  // ─── FIRMA PDF ─────────────────────────────────────────────────────────────
  function initPdfSigner() {
    var openBtn = document.getElementById("cv_open_pdf_signer");
    var closeBtn = document.getElementById("cv_close_pdf_modal");
    var modal = document.getElementById("cv_pdf_modal");
    var canvas = document.getElementById("cv_pdf_canvas_firma");
    var clearBtn = document.getElementById("cv_clear_pdf_canvas");
    var saveBtn = document.getElementById("cv_save_pdf_signed");
    var privacyCheckbox = document.getElementById("cv_privacidad");
    var statusMsg = document.getElementById("cv_firmado_ok_msg");
    var hiddenInput = document.getElementById("cv_firma_pdf_base64");
    var downloadBtn = document.getElementById("cv_download_pdf_signed");

    if (!openBtn || !canvas || !modal) return;

    var ctx = canvas.getContext("2d");
    var isDrawing = false;
    var hasSigned = false;
    var finalPdfBlob = null;

    function resizeCanvas() {
      canvas.width = canvas.offsetWidth || canvas.clientWidth || 300;
      canvas.height = canvas.offsetHeight || canvas.clientHeight || 150;
      ctx.strokeStyle = "#0b1a30";
      ctx.lineWidth = 3;
      ctx.lineCap = "round";
      ctx.lineJoin = "round";
    }

    openBtn.addEventListener("click", function () {
      modal.style.display = "flex";
      setTimeout(resizeCanvas, 60);
    });

    closeBtn.addEventListener("click", function () {
      modal.style.display = "none";
    });

    function getPos(e) {
      var rect = canvas.getBoundingClientRect();
      var clientX =
        e.touches && e.touches.length > 0 ? e.touches[0].clientX : e.clientX;
      var clientY =
        e.touches && e.touches.length > 0 ? e.touches[0].clientY : e.clientY;
      return {
        x: (clientX - rect.left) * (canvas.width / rect.width),
        y: (clientY - rect.top) * (canvas.height / rect.height),
      };
    }

    function start(e) {
      isDrawing = true;
      var pos = getPos(e);
      ctx.beginPath();
      ctx.moveTo(pos.x, pos.y);
      if (e.cancelable) e.preventDefault();
    }

    function move(e) {
      if (!isDrawing) return;
      var pos = getPos(e);
      ctx.lineTo(pos.x, pos.y);
      ctx.stroke();
      hasSigned = true;
      if (e.cancelable) e.preventDefault();
    }

    function stop() {
      isDrawing = false;
    }

    canvas.addEventListener("mousedown", start);
    canvas.addEventListener("mousemove", move);
    window.addEventListener("mouseup", stop);
    canvas.addEventListener("touchstart", start, { passive: false });
    canvas.addEventListener("touchmove", move, { passive: false });
    window.addEventListener("touchend", stop);

    clearBtn.addEventListener("click", function () {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      hasSigned = false;
    });

    saveBtn.addEventListener("click", async function () {
      if (!hasSigned) {
        showModalDialog(
          "error",
          "Firma vacía",
          "Por favor, dibuje su firma en el recuadro antes de guardar.",
        );
        return;
      }
      if (typeof PDFLib === "undefined") {
        showModalDialog(
          "error",
          "Error del Sistema",
          "La librería de procesamiento PDF aún se está cargando. Por favor, espere 3 segundos e inténtelo de nuevo.",
        );
        return;
      }

      var pdfUrl =
        window.cvPdfTemplateUrl ||
        "/modules/mod_formulario_cv/media/documento_base.pdf";

      try {
        saveBtn.disabled = true;
        saveBtn.innerText = "Procesando...";
        if (statusMsg) {
          statusMsg.style.color = "#c9931b";
          statusMsg.textContent = "Procesando e incrustando firma en el PDF...";
        }

        var firmaImgBase64 = canvas.toDataURL("image/png");
        modal.style.display = "none";

        var response = await fetch(pdfUrl);
        if (!response.ok) {
          throw new Error(
            "El servidor respondió con código " +
              response.status +
              ". Verifica que el archivo exista en la ruta correcta.",
          );
        }
        var contentType = response.headers.get("content-type");
        if (contentType && contentType.indexOf("application/pdf") === -1) {
          throw new Error(
            "El archivo no es un PDF válido. El servidor devolvió: " +
              contentType,
          );
        }

        var existingPdfBytes = await response.arrayBuffer();
        var pdfDoc = await PDFLib.PDFDocument.load(existingPdfBytes);
        var pages = pdfDoc.getPages();
        var co = getCoords();

        var firmaPage = pages[co.firmaPage] || pages[0];
        var checkPage = pages[co.checkPage] || pages[0];
        var firma5Page = pages[co.firma5Page] || pages[pages.length - 1];

        var pngImage = await pdfDoc.embedPng(firmaImgBase64);

        firmaPage.drawImage(pngImage, {
          x: co.firmaP1X,
          y: co.firmaP1Y,
          width: co.firmaP1W,
          height: co.firmaP1H,
        });

        var check1 = document.getElementById("modal_consent_1");
        var check2 = document.getElementById("modal_consent_2");
        var check3 = document.getElementById("modal_consent_3");
        if (check1 && check1.checked) {
          checkPage.drawText("X", { x: co.check1X, y: co.check1Y, size: 12 });
        }
        if (check2 && check2.checked) {
          checkPage.drawText("X", { x: co.check2X, y: co.check2Y, size: 12 });
        }
        if (check3 && check3.checked) {
          checkPage.drawText("X", { x: co.check3X, y: co.check3Y, size: 12 });
        }

        firma5Page.drawImage(pngImage, {
          x: co.firmaP5X,
          y: co.firmaP5Y,
          width: co.firmaP5W,
          height: co.firmaP5H,
        });

        var consentCv = document.getElementById("modal_consent_cv");
        if (!consentCv || !consentCv.checked) {
          showModalDialog(
            "error",
            "Consentimiento obligatorio",
            "Debe aceptar la cláusula de selección para enviar su CV.",
          );
          saveBtn.disabled = false;
          saveBtn.innerText = "Aceptar y Firmar PDF";
          modal.style.display = "flex";
          return;
        }
        firma5Page.drawText("X", { x: co.checkP5X, y: co.checkP5Y, size: 12 });

        var fechaHoy = new Date().toLocaleDateString("es-ES");
        firma5Page.drawText(fechaHoy, {
          x: co.fechaP5X,
          y: co.fechaP5Y,
          size: 11,
        });

        var pdfBytes = await pdfDoc.save();
        finalPdfBlob = new Blob([pdfBytes], { type: "application/pdf" });

        var binary = "";
        var bytes = new Uint8Array(pdfBytes);
        for (var b = 0; b < bytes.byteLength; b++) {
          binary += String.fromCharCode(bytes[b]);
        }
        var base64String = btoa(binary);

        if (hiddenInput) {
          hiddenInput.value = "data:application/pdf;base64," + base64String;
        }
        if (privacyCheckbox) {
          privacyCheckbox.disabled = false;
          privacyCheckbox.checked = true;
        }
        if (statusMsg) {
          statusMsg.style.color = "#28a745";
          statusMsg.textContent =
            "✅ PDF generado y firmado correctamente en el sistema.";
        }
        openBtn.textContent = "📄 Ver Documento Firmado";
        if (downloadBtn) {
          downloadBtn.style.display = "inline-block";
        }
      } catch (err) {
        console.error(err);
        if (statusMsg) {
          statusMsg.style.color = "#dc3545";
          statusMsg.textContent = "❌ Error al procesar el documento PDF.";
        }
        showModalDialog(
          "error",
          "Error de Procesamiento",
          "Detalle: " + err.message,
        );
      } finally {
        saveBtn.disabled = false;
        saveBtn.innerText = "Aceptar y Firmar PDF";
      }
    });

    if (downloadBtn) {
      downloadBtn.addEventListener("click", function () {
        if (!finalPdfBlob) {
          showModalDialog(
            "error",
            "Acción denegada",
            "Primero debe firmar el documento para poder descargarlo.",
          );
          return;
        }
        var downloadUrl = URL.createObjectURL(finalPdfBlob);
        var tempAnchor = document.createElement("a");
        tempAnchor.href = downloadUrl;
        tempAnchor.download = "LOPD_Firmado_Candidato.pdf";
        document.body.appendChild(tempAnchor);
        tempAnchor.click();
        document.body.removeChild(tempAnchor);
        URL.revokeObjectURL(downloadUrl);
      });
    }
  }

  // ─── DIÁLOGO MODAL ─────────────────────────────────────────────────────────
  function ensureThanksStyles() {
    if (document.getElementById("cv-thanks-styles")) return;
    var style = document.createElement("style");
    style.id = "cv-thanks-styles";
    style.textContent =
      ".cv-thanks-overlay{position:fixed;inset:0;background:rgba(11,26,48,0.7);display:flex;align-items:center;justify-content:center;z-index:999999;padding:24px;animation:cv-thanks-fade .15s ease-out;}" +
      ".cv-thanks-dialog{background:#ffffff;border-radius:12px;max-width:460px;width:100%;padding:40px 36px;text-align:center;box-shadow:0 24px 60px rgba(11,26,48,0.4);font-family:inherit;border:1px solid #d3dae3;}" +
      ".cv-thanks-icon{width:68px;height:68px;margin:0 auto 20px;border-radius:50%;background:rgba(201,147,27,0.15);color:#a47506;display:flex;align-items:center;justify-content:center;}" +
      ".cv-thanks-icon.cv-modal-error{background:rgba(220,53,69,0.15);color:#dc3545;}" +
      ".cv-thanks-title{margin:0 0 12px;font-size:2.75rem;font-weight:700;color:#0b1a30;line-height:1.3;}" +
      ".cv-thanks-text{margin:0 0 28px;font-size:1.5rem;line-height:1.6;color:#3a4b61;font-weight:400;}" +
      ".cv-thanks-button{appearance:none;border:1px solid #c9931b;background:#c9931b;color:#fff;font-size:1.3rem;font-weight:700;padding:14px 40px;border-radius:8px;cursor:pointer;transition:all 0.2s;display:inline-block;width:100%;max-width:200px;text-transform:uppercase;letter-spacing:0.5px;}" +
      ".cv-thanks-button:hover{background:#a47506;border-color:#a47506;transform:translateY(-1px);}" +
      "@keyframes cv-thanks-fade{from{opacity:0;}to{opacity:1;}}";
    document.head.appendChild(style);
  }

  function showModalDialog(type, titleText, bodyText) {
    ensureThanksStyles();
    var overlay = document.createElement("div");
    overlay.className = "cv-thanks-overlay";
    var dialog = document.createElement("div");
    dialog.className = "cv-thanks-dialog";
    var icon = document.createElement("div");

    if (type === "error") {
      icon.className = "cv-thanks-icon cv-modal-error";
      icon.innerHTML =
        '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
    } else {
      icon.className = "cv-thanks-icon";
      icon.innerHTML =
        '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }

    var title = document.createElement("h3");
    title.className = "cv-thanks-title";
    title.textContent = titleText;
    var text = document.createElement("p");
    text.className = "cv-thanks-text";
    text.textContent = bodyText;
    var button = document.createElement("button");
    button.type = "button";
    button.className = "cv-thanks-button";
    button.textContent = "Aceptar";

    dialog.appendChild(icon);
    dialog.appendChild(title);
    dialog.appendChild(text);
    dialog.appendChild(button);
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);

    var previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    function close() {
      document.body.style.overflow = previousOverflow;
      overlay.remove();
    }
    button.addEventListener("click", close);
    overlay.addEventListener("click", function (e) {
      if (e.target === overlay) close();
    });
    button.focus();
  }

  // ─── AJAX SUBMIT ───────────────────────────────────────────────────────────
  function attachAjaxSubmit(form) {
    if (!window.fetch || !window.FormData || form.dataset.cvBound === "1")
      return;
    form.dataset.cvBound = "1";
    form.dataset.cvSubmitting = "0";

    form.addEventListener("submit", function (event) {
      event.preventDefault();

      var hiddenInput = document.getElementById("cv_firma_pdf_base64");
      if (!hiddenInput || hiddenInput.value.trim() === "") {
        showModalDialog(
          "error",
          "Firma Obligatoria",
          "Debes abrir el visor, leer el documento y realizar tu firma digital en el PDF antes de poder tramitar la candidatura.",
        );
        return;
      }

      if (typeof grecaptcha !== "undefined") {
        var captchaResponse = grecaptcha.getResponse();
        if (captchaResponse.length === 0) {
          showModalDialog(
            "error",
            "Verificación Necesaria",
            'Por favor, completa la casilla "No soy un robot" antes de realizar el envío.',
          );
          return;
        }
      }

      if (form.dataset.cvSubmitting === "1") return;
      form.dataset.cvSubmitting = "1";

      var submitButton = form.querySelector('button[type="submit"]');
      var originalLabel = submitButton ? submitButton.textContent : "";

      // Capturar PDF ANTES de crear FormData (form.reset() lo borraría)
      var pdfB64 = hiddenInput ? hiddenInput.value : "";
      var formData = new FormData(form);
      var ajaxUrl = form.dataset.cvAjaxUrl || form.action;

      // Quitar el PDF del FormData principal para no superar post_max_size
      formData.delete("cv_firma_pdf_base64");

      if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = "Enviando...";
      }

      fetch(ajaxUrl, {
        method: "POST",
        body: formData,
        credentials: "same-origin",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          Accept: "application/json",
        },
      })
        .then(function (response) {
          return response.text().then(function (text) {
            var data = null;
            try {
              data = JSON.parse(text);
            } catch (e) {}
            return { ok: response.ok, data: data };
          });
        })
        .then(function (result) {
          var isSuccess = false;
          var text = "";
          if (result.data) {
            if (result.data.status === "success") isSuccess = true;
            text = result.data.message || "";
          } else if (result.ok) {
            isSuccess = true;
          }

          if (isSuccess) {
            var boxes = form.parentNode.querySelectorAll(".cv-form-message");
            for (var i = 0; i < boxes.length; i++) boxes[i].remove();

            // ── Petición secundaria: subir PDF firmado ──────────────────────
            var candidatoClave =
              result.data && result.data.clave ? result.data.clave : "";
            if (pdfB64 && candidatoClave) {
              var fd2 = new FormData();
              fd2.append("action", "upload_pdf_firmado");
              fd2.append("clave", candidatoClave);
              fd2.append("pdf_base64", pdfB64);
              fetch(ajaxUrl, {
                method: "POST",
                body: fd2,
                credentials: "same-origin",
                headers: {
                  "X-Requested-With": "XMLHttpRequest",
                  Accept: "application/json",
                },
              })
                .then(function (r) {
                  return r.json();
                })
                .then(function (d) {
                  console.log("[LOPD firmado]", d.status);
                })
                .catch(function (e) {
                  console.warn("[LOPD firmado] Error:", e);
                });
            }
            // ───────────────────────────────────────────────────────────────

            showModalDialog(
              "success",
              "Candidatura Registrada",
              text || "Tu candidatura se ha registrado con éxito.",
            );

            form.reset();
            if (hiddenInput) hiddenInput.value = "";

            var privacyCheckbox = document.getElementById("cv_privacidad");
            if (privacyCheckbox) privacyCheckbox.disabled = true;

            var statusMsg = document.getElementById("cv_firmado_ok_msg");
            if (statusMsg) {
              statusMsg.style.color = "#c9931b";
              statusMsg.textContent =
                "⚠️ Debes abrir y firmar el documento para habilitar el formulario.";
            }

            var openBtn = document.getElementById("cv_open_pdf_signer");
            if (openBtn) openBtn.textContent = "📄 Leer y Firmar Documento PDF";

            var downloadBtn = document.getElementById("cv_download_pdf_signed");
            if (downloadBtn) downloadBtn.style.display = "none";

            if (typeof grecaptcha !== "undefined") grecaptcha.reset();
            clearJobLimitDisabling(form);
          } else {
            showModalDialog(
              "error",
              "Error en el Envío",
              text || "Hubo un problema al procesar la solicitud.",
            );
            if (typeof grecaptcha !== "undefined") grecaptcha.reset();
          }
        })
        .catch(function () {
          showModalDialog(
            "error",
            "Error de Red",
            "No se pudo enviar el formulario. Revisa la conexión de internet.",
          );
          if (typeof grecaptcha !== "undefined") grecaptcha.reset();
        })
        .then(function () {
          form.dataset.cvSubmitting = "0";
          restoreSubmitButton(submitButton, originalLabel);
        });
    });
  }

  function initAjaxSubmit() {
    var forms = document.querySelectorAll('form.cv-form[data-cv-ajax="1"]');
    for (var i = 0; i < forms.length; i += 1) {
      attachAjaxSubmit(forms[i]);
    }
  }

  function init() {
    initJobLimits();
    initPdfSigner();
    initAjaxSubmit();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
