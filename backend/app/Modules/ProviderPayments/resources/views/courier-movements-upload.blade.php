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
        .progress { display: none; margin-top: 20px; }
        .progress.visible { display: block; }
        .progress-track { height: 10px; overflow: hidden; border-radius: 999px; background: #dcecec; }
        .progress-bar { width: 35%; height: 100%; border-radius: inherit; background: var(--turquoise); animation: loading 1.2s ease-in-out infinite; }
        @keyframes loading { from { transform: translateX(-110%); } to { transform: translateX(310%); } }
    </style>
</head>
<body>
    <header class="top"><img src="{{ asset('images/logo4n.jpg') }}" alt="4N Logística"></header>
    <main class="page">
        <p><a href="{{ route('provider-payments.dashboard') }}" style="color:#277d80">← Pago a Proveedores</a></p>
        <h1>Carga Movimientos Courier</h1>
        <div class="card">
            <p>Selecciona la base Geolize que se importará a <strong>movimientos_courier</strong>.</p>
            <form id="validation-form" action="{{ route('provider-payments.courier-movements.validate') }}" method="post" enctype="multipart/form-data">
                @csrf
                <label for="file">Archivo de movimientos</label>
                <div class="file">
                    <div>Busca un archivo Excel o CSV</div>
                    <input id="file" name="file" type="file" accept=".xlsx,.csv" required>
                </div>
                <p class="note">La carga validará seguimiento, fecha, peso y datos necesarios antes de incorporarlos.</p>
                <button id="validate-button" class="button" type="submit">Validar archivo</button>
            </form>
            <div id="progress" class="progress" role="status" aria-live="polite">
                <div class="progress-track"><div class="progress-bar"></div></div>
                <p>Subiendo y validando el archivo. Esto puede tomar unos momentos.</p>
            </div>
            @if ($errors->any())
                <div class="result visible error" role="alert">{{ $errors->first() }}</div>
            @endif
            @if (session('validation'))
                @php($validation = session('validation'))
                <div class="result visible" role="status">
                    <strong>Resultado de la validación</strong>
                    <div><b>Archivo:</b> {{ $validation['file_name'] }}</div>
                    <div><b>Registros detectados:</b> {{ number_format($validation['records'], 0, ',', '.') }}</div>
                    <div><b>Campos encontrados:</b> seguimiento {{ $validation['has_tracking'] ? 'sí' : 'no' }}, comerciante {{ $validation['has_merchant'] ? 'sí' : 'no' }}, peso {{ $validation['has_weight'] ? 'sí' : 'no' }}</div>
                    <div><b>Observaciones:</b> {{ $validation['missing_tracking'] }} sin seguimiento, {{ $validation['invalid_date'] }} con fecha no identificable y {{ $validation['missing_weight'] }} sin peso.</div>
                    <p>Revisa este resultado. El siguiente paso será decidir qué registros se incorporan o reemplazan.</p>
                </div>
            @endif
        </div>
    </main>
    <script>
        const validationForm = document.getElementById('validation-form');
        const progress = document.getElementById('progress');
        const validateButton = document.getElementById('validate-button');

        validationForm.addEventListener('submit', function () {
            if (validationForm.checkValidity()) {
                progress.classList.add('visible');
                validateButton.disabled = true;
                validateButton.textContent = 'Validando archivo...';
            }
        });
    </script>
</body>
</html>
