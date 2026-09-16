<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Carga Movimientos Courier</title>
    <style>
        :root { --ink: #201e1f; --turquoise: #5db8bc; --paper: #f6f8f8; }
        body { margin: 0; background: var(--paper); color: var(--ink); font-family: system-ui, sans-serif; }
        .top { padding: 14px 30px; border-bottom: 5px solid var(--turquoise); background: #fff; }
        .top img { width: 115px; }
        .page { max-width: 760px; margin: 45px auto; padding: 0 24px; }
        .card { padding: 30px; border-radius: 12px; background: #fff; box-shadow: 0 2px 10px #dce6e6; }
        label { display: block; margin: 22px 0 8px; font-weight: 700; }
        .file { padding: 32px; border: 2px dashed var(--turquoise); border-radius: 10px; text-align: center; }
        .file input { margin-top: 15px; }
        .button { margin-top: 24px; padding: 12px 20px; border: 0; border-radius: 7px; background: var(--turquoise); color: #fff; cursor: pointer; font-weight: 700; }
        .note { color: #60696b; font-size: 14px; }
        .result { display: none; margin-top: 22px; padding: 18px; border-radius: 8px; background: #eaf7f7; color: #245c5e; }
        .result.visible { display: block; }
        .result strong { display: block; margin-bottom: 6px; }
        .error { background: #fff0f1; color: #992d3a; }
    </style>
</head>
<body>
    <header class="top"><img src="{{ asset('images/logo4n.jpg') }}" alt="4N Logística"></header>
    <main class="page">
        <p><a href="{{ route('provider-payments.dashboard') }}" style="color:#277d80">← Pago a Proveedores</a></p>
        <h1>Carga Movimientos Courier</h1>
        <div class="card">
            <p>Selecciona la base Geolize que se importará a <strong>movimientos_courier</strong>.</p>
            <form id="upload-form">
                <label for="file">Archivo de movimientos</label>
                <div class="file">
                    <div>Busca un archivo Excel o CSV</div>
                    <input id="file" type="file" accept=".xlsx,.xls,.csv" required>
                </div>
                <p class="note">La carga validará seguimiento, fecha, peso y datos necesarios antes de incorporarlos.</p>
                <button class="button" type="submit">Preparar archivo</button>
            </form>
            <div id="result" class="result" role="status" aria-live="polite"></div>
        </div>
    </main>
    <script>
        const form = document.getElementById('upload-form');
        const input = document.getElementById('file');
        const result = document.getElementById('result');

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const file = input.files[0];

            if (!file) {
                result.className = 'result visible error';
                result.textContent = 'Primero selecciona el archivo que deseas preparar.';
                return;
            }

            const allowedExtensions = ['xlsx', 'xls', 'csv'];
            const extension = file.name.split('.').pop().toLowerCase();

            if (!allowedExtensions.includes(extension)) {
                result.className = 'result visible error';
                result.textContent = 'Selecciona un archivo Excel o CSV.';
                return;
            }

            const size = (file.size / 1024 / 1024).toFixed(2).replace('.', ',');
            result.className = 'result visible';
            result.innerHTML = '<strong>Archivo preparado para validar</strong>'
                + '<div><b>Nombre:</b> ' + file.name + '</div>'
                + '<div><b>Tipo:</b> ' + extension.toUpperCase() + ' · <b>Tamaño:</b> ' + size + ' MB</div>'
                + '<p>En el siguiente paso se revisarán los registros antes de cargarlos al sistema.</p>';
        });
    </script>
</body>
</html>