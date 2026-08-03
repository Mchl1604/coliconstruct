/**
 * Thumbnails for whatever is sitting in a file input, so an upload can be
 * checked before it is sent.
 *
 * Opt in from the markup:
 *   <input type="file" data-image-input data-image-preview-target="#someRow">
 *
 * Delegated from the document, so inputs inside a modal that Blade rendered
 * per task are all covered by this one listener.
 */
document.addEventListener("change", function (event) {
    const input = event.target.closest("[data-image-input]");

    if (!input) {
        return;
    }

    const container = document.querySelector(
        input.getAttribute("data-image-preview-target"),
    );

    if (!container) {
        return;
    }

    container.innerHTML = "";

    Array.prototype.forEach.call(input.files || [], function (file) {
        if (!file.type.startsWith("image/")) {
            return;
        }

        const column = document.createElement("div");
        column.className = "col-4 col-md-3";

        const image = document.createElement("img");
        image.className = "img-fluid rounded border";
        image.alt = file.name;
        image.style.height = "110px";
        image.style.width = "100%";
        image.style.objectFit = "cover";
        image.src = URL.createObjectURL(file);
        image.addEventListener("load", function () {
            URL.revokeObjectURL(image.src);
        });

        column.appendChild(image);
        container.appendChild(column);
    });
});
