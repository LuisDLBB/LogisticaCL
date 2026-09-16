@extends('provider-payments::layout')
@section('title', 'Carga Movimientos Courier')
@push('styles')<style>.upload-wrap{max-width:820px}.file{padding:34px;border:2px dashed var(--turquoise);border-radius:11px;text-align:center}.file input{margin-top:15px}.result{margin-top:22px;padding:18px;border-radius:8px;background:var(--turquoise-soft);color:#245c5e}.result.error{background:#fff0f1;color:#992d3a}.progress{display:none;margin-top:20px}.progress.visible{display:block}label{display:block;margin:22px 0 8px;font-weight:750}</style>@endpush
@section('content')
<div class="upload-wrap"><a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a><p class="eyebrow">Gestión operacional</p><h1>Carga Movimientos Courier</h1><p class="intro">Selecciona la base Geolize que se incorporará al proceso de pago.</p>
<div class="card"><form id="validation-form" action="{{ route('provider-payments.courier-movements.validate') }}" method="post" enctype="multipart/form-data">@csrf<label for="file">Archivo de movimientos</label><div class="file"><div>Busca un archivo Excel o CSV</div><input id="file" name="file" type="file" accept=".xlsx,.csv" required></div><p class="note">Validaremos seguimiento, fecha, peso y los datos necesarios antes de incorporarlos.</p><button id="validate-button" type="submit">Validar archivo</button></form>
<div id="progress" class="progress" role="status"><div class="progress-track"><div class="progress-bar"></div></div><p>Subiendo y validando el archivo. Esto puede tomar unos momentos.</p></div>
@if ($errors->any())<div class="result error" role="alert">{{ $errors->first() }}</div>@endif
@if (isset($validation) || session('validation')) @php($validation = $validation ?? session('validation')) <div class="result"><strong>Resultado de la validación</strong><div><b>Archivo:</b> {{ $validation['file_name'] }}</div><div><b>Registros detectados:</b> {{ number_format($validation['records'], 0, ',', '.') }}</div></div>@endif
</div></div>
@endsection
@push('scripts')<script>const form=document.getElementById('validation-form'),progress=document.getElementById('progress'),button=document.getElementById('validate-button');form.addEventListener('submit',function(){if(form.checkValidity()){progress.classList.add('visible');button.disabled=true;button.textContent='Validando archivo...';}});</script>@endpush
