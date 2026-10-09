<form method="POST" enctype="multipart/form-data" action="{{ route('operations.loads.store', 'reception') }}" class="ope-form">
    @csrf
    <label>Archivo Excel .xlsx<input type="file" name="file" accept=".xlsx" required></label>
    <label>Hoja de datos<input name="sheet" value="{{ old('sheet', 'Hoja1') }}" maxlength="80" required></label>
    <label>Formato<select name="profile">
        <option value="legacy" @selected(old('profile', 'legacy') === 'legacy')>ProcesoRecepcionOperaciones actual</option>
        <option value="custom" @selected(old('profile') === 'custom')>Columnas configurables</option>
    </select></label>
    <p class="note ope-full">Formato actual: fecha A, cliente D, código completo E, peso F, operario H y referencia ESD I. Se contrastan los pesos de C, F y N. ESD se conserva como referencia; el número de guía cliente debe indicarse en su columna real.</p>
    @foreach(['date'=>['Fecha','A'],'tracking'=>['Código paquete','E'],'weight'=>['Peso volumétrico kg','F'],'operator'=>['Usuario operario','H'],'client'=>['Cliente','D'],'customer_guide'=>['Número guía cliente',''],'reference'=>['Referencia','I']] as $field=>$item)
        <label>{{ $item[0] }} · columna<input name="{{ $field }}" value="{{ old($field, $item[1]) }}" pattern="[A-Z]{1,2}" maxlength="2" placeholder="{{ $field === 'customer_guide' ? 'Sin columna: dejar vacío' : 'Letra de columna' }}"></label>
    @endforeach
    <p class="note ope-full">Las columnas configurables se utilizan al seleccionar ese formato. En el formato actual el cliente se toma de D y solo puedes indicar la columna de guía cliente. Los encabezados deben estar en la primera fila.</p>
    <div class="ope-full"><button type="submit">Cargar y validar Excel</button></div>
</form>
