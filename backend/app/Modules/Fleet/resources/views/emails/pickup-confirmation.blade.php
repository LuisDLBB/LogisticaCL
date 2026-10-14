<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $retCode }} · 4N Logística</title>
<style>
@media only screen and (max-width:600px) {
    .email-pad { padding-left:16px !important; padding-right:16px !important; }
    .summary-cell { display:block !important; width:auto !important; border-right:0 !important; border-bottom:1px solid #dceaea !important; }
    .transport-cell { display:block !important; width:auto !important; }
    .email-code { font-size:17px !important; }
}
</style>
</head>
<body style="margin:0;padding:0;background:#f2f5f6;color:#16363c;font-family:Arial,Helvetica,sans-serif;">
@php
    $compactSections = array_map(fn ($section) => str_replace('<p>', '<p style="margin:0;">', $section), $sections);
@endphp
<div style="display:none;max-height:0;overflow:hidden;">Tu retiro está agendado. Fecha, horario y transporte en un solo resumen.</div>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f2f5f6;"><tr><td align="center" style="padding:16px 8px;">
<!--[if mso]><table role="presentation" width="800" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:800px;background:#ffffff;border:1px solid #e0e8ea;border-radius:14px;overflow:hidden;">
<tr><td class="email-pad" style="background:#082b30;padding:18px 24px;border-top:4px solid #18c7c9;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr>
<td valign="middle"><span style="display:inline-block;background:#fff;border-radius:6px;padding:4px 7px"><img src="{{ asset('images/logo4n.png') }}" alt="4N Logística" width="76" height="37" style="display:block;border:0"></span></td>
<td align="right" valign="middle"><span style="display:inline-block;background:#18575c;color:#8aeee0;border:1px solid #39868a;border-radius:20px;padding:6px 12px;font-size:11px;font-weight:700;">● AGENDADO</span></td>
</tr></table>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td style="padding-top:10px;">
<h1 style="margin:0 0 4px;font-size:21px;line-height:1.3;color:#ffffff;">Confirmación de retiro</h1>
<p class="email-code" style="margin:0;color:#72dce0;font-size:19px;font-weight:700;letter-spacing:0.5px;">{{ $retCode }}</p>
</td></tr></table>
</td></tr>
<tr><td class="email-pad" style="padding:14px 24px;font-size:13px;line-height:1.5;">{!! $compactSections[0] ?? '' !!}<div style="padding-top:4px;">{!! $compactSections[1] ?? '' !!}</div></td></tr>
<tr><td class="email-pad" style="padding:0 24px 14px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f0f9f9;border:1px solid #d4e8e8;border-radius:10px;"><tr>
<td class="summary-cell" width="34%" valign="top" style="padding:12px 14px;border-right:1px solid #d4e8e8;">
<p style="margin:0 0 5px;color:#527279;font-size:10px;font-weight:700;letter-spacing:1px;">FECHA DEL RETIRO</p>
<p style="margin:0;font-size:13px;font-weight:700;line-height:1.5;">{{ $details['Fecha'] ?? 'Sin informar' }}</p>
</td>
<td class="summary-cell" width="28%" valign="top" style="padding:12px 14px;border-right:1px solid #d4e8e8;">
<p style="margin:0 0 5px;color:#527279;font-size:10px;font-weight:700;letter-spacing:1px;">HORARIO</p>
<p style="margin:0;font-size:15px;font-weight:700;line-height:1.5;">{{ $details['Jornada/horario'] ?? 'Sin informar' }}</p>
</td>
<td class="summary-cell" width="38%" valign="top" style="padding:12px 14px;background:#e6f8f3;">
<p style="margin:0 0 5px;color:#237268;font-size:10px;font-weight:700;letter-spacing:1px;">CARGA PROGRAMADA</p>
<div style="font-size:13px;line-height:1.5;">{!! $compactSections[2] ?? '' !!}</div>
</td>
</tr></table>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
<tr><td colspan="2" style="padding:12px 0 10px;border-bottom:1px solid #e5ecee;">
<p style="margin:0 0 4px;color:#527279;font-size:10px;font-weight:700;letter-spacing:1px;">DIRECCIÓN DE RETIRO</p>
<p style="margin:0;font-size:13px;font-weight:700;line-height:1.5;">{{ $details['Dirección'] ?? 'Sin informar' }}</p>
</td></tr>
<tr>
<td class="transport-cell" width="65%" valign="top" style="padding:10px 12px 0 0;">
<p style="margin:0 0 4px;color:#527279;font-size:10px;font-weight:700;letter-spacing:1px;">CONDUCTOR</p>
<p style="margin:0;font-size:13px;font-weight:700;line-height:1.5;">{{ $details['Conductor'] ?? 'Sin informar' }}</p>
</td>
<td class="transport-cell" width="35%" valign="top" style="padding:10px 0 0;">
<p style="margin:0 0 4px;color:#527279;font-size:10px;font-weight:700;letter-spacing:1px;">PATENTE</p>
<p style="margin:0;font-size:13px;font-weight:700;line-height:1.5;">{{ $details['Patente'] ?? 'Sin informar' }}</p>
</td>
</tr>
@foreach($details as $label => $value)
@if(! in_array($label, ['Fecha', 'Jornada/horario', 'Dirección', 'Conductor', 'Patente'], true))
<tr><td colspan="2" style="padding-top:10px;font-size:13px;line-height:1.5;"><strong>{{ $label }}:</strong> {{ $value }}</td></tr>
@endif
@endforeach
</table></td></tr>
<tr><td class="email-pad" style="padding:0 24px 12px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td style="padding:11px 14px;background:#fff7e4;border-left:3px solid #eab454;border-radius:6px;">
<p style="margin:0 0 5px;font-size:10px;font-weight:700;letter-spacing:0.7px;color:#92631c;">¿NECESITAS INFORMAR UN CAMBIO?</p>
<div style="font-size:12px;line-height:1.5;">{!! $compactSections[3] ?? '' !!}</div>
</td></tr></table>
</td></tr>
<tr><td class="email-pad" style="padding:0 24px 14px;font-size:12px;line-height:1.5;color:#466169;">
<h2 style="margin:0 0 8px;font-size:13px;color:#16363c;">Indicaciones para tu retiro</h2>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
@foreach(count($compactSections) > 9 ? [4, 5, 6, 7] : [4, 5, 6] as $index)
<tr><td width="15" valign="top" style="padding:0 6px 7px 0;color:#00969d;font-weight:700;">•</td><td valign="top" style="padding-bottom:7px;font-size:12px;line-height:1.5;">{!! $compactSections[$index] ?? '' !!}</td></tr>
@endforeach
</table>
</td></tr>
<tr><td class="email-pad" style="padding:11px 24px;background:#f4f7f8;border-top:1px solid #e4ecee;font-size:12px;line-height:1.5;color:#16363c;">{!! $compactSections[count($compactSections) > 9 ? 8 : 7] ?? '' !!}{!! $compactSections[count($compactSections) > 9 ? 9 : 8] ?? '' !!}</td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table>
</body></html>
