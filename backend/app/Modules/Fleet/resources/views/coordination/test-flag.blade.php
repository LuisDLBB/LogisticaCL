@if(app()->environment(['local','testing']) && config('fleet.test_tools_enabled'))
@if(config('fleet.auto_test_mode'))
<p><strong>Dato de prueba automático</strong><small>Este registro se guardará como prueba.</small></p>
@else
<p><label><input type="checkbox" name="is_test" value="1" @checked(old('is_test'))> Identificar este registro como dato de prueba</label><small>Solo los registros marcados podrán eliminarse con Limpiar pruebas.</small></p>
@endif
@endif
