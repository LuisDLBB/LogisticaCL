@extends('fleet::layout')
@section('title', 'Flota')
@section('header-description', 'Arrastra dentro de una columna para ordenar tu prioridad; hacia otro estado para cambiar su disponibilidad. Agendados: azul habitual; amarillo con mantención por programar; naranjo con mantención ya agendada.')
@push('styles')
<style>
.workspace-header{flex-wrap:wrap;gap:12px;padding:12px 24px}.fleet-header-filters{flex:1 0 100%;display:grid;grid-template-columns:repeat(3,minmax(100px,1fr)) minmax(140px,1.3fr) auto auto;gap:10px;align-items:end;padding-top:10px;border-top:1px solid #e9edef}.fleet-header-filters label{font-size:12px;margin:0}.fleet-header-filters input,.fleet-header-filters select{display:block;width:100%;margin-top:4px;min-height:36px;padding:7px 9px}.fleet-header-filters .check{display:flex;align-items:center;gap:6px;min-height:36px}.fleet-header-filters .check input{width:auto;margin:0;min-height:0}.fleet-header-filters button{min-height:36px;padding:8px 14px}.fleet-top{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px}.fleet-top h1{font-size:24px;margin:0}.fleet-back{display:inline-flex;align-items:center;gap:7px;padding:9px 13px;border:1px solid #c7dddf;border-radius:9px;background:#fff;color:#087f83;text-decoration:none;font-size:13px;font-weight:700}.fleet-summary{display:flex;align-items:flex-start;flex-wrap:wrap;gap:10px;margin:14px 0 20px}.fleet-summary .fleet-stat{width:160px;border:1px solid #dde7e9;border-radius:13px;background:#fff;padding:12px 15px;box-sizing:border-box}.fleet-stat>span,.fleet-stat summary>span{font-size:12px;color:#60757a}.fleet-stat strong{display:block;font-size:32px;line-height:1.15;margin-top:5px}.fleet-stat summary{cursor:pointer;list-style:none}.fleet-stat summary::-webkit-details-marker{display:none}.fleet-stat summary small{display:block;font-size:11px;color:#a7353e;margin-top:5px}.fleet-stat[open]{width:min(400px,100%)}.fleet-unavailable-list{list-style:none;padding:0;margin:10px 0 0;border-top:1px solid #e3e9eb}.fleet-unavailable-list li{padding:9px 0;border-bottom:1px solid #edf1f2}.fleet-unavailable-list a{font-size:12px;font-weight:700}.fleet-unavailable-list small{display:block;font-size:12px;color:#a7353e;margin-top:3px}.fleet-section-heading{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin:0 0 12px}.fleet-section-heading h2{font-size:18px;margin:0}.fleet-sub{font-size:12px;color:#60757a}.fleet-legend{display:flex;gap:10px;flex-wrap:wrap;font-size:11px;color:#60757a}.fleet-legend span:before{content:'';display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--state);margin-right:4px}.fleet-vehicle-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(205px,1fr));gap:12px}.fleet-vehicle-card{--state:#17875b;--tint:#eaf8f0;display:flex;flex-direction:column;position:relative;padding:13px;border:1px solid #dde7e9;border-top:4px solid var(--state);border-radius:13px;background:#fff;text-decoration:none;color:#12333a;transition:box-shadow .15s,transform .15s}.fleet-vehicle-card:hover{box-shadow:0 5px 18px #12333a18;transform:translateY(-2px)}.fleet-vehicle-card:focus-visible,.fleet-back:focus-visible{outline:3px solid #029b9e;outline-offset:3px}.fleet-vehicle-card.booked{--state:#2278c5;--tint:#ebf4ff}.fleet-vehicle-card.warning{--state:#a36d05;--tint:#fff6da}.fleet-vehicle-card.unavailable{--state:#c93545;--tint:#fff0f2}.fleet-card-heading{display:flex;justify-content:space-between;align-items:center;gap:6px}.fleet-card-heading strong{font-size:19px;letter-spacing:.04em}.fleet-owner{font-size:11px;background:#eff4f5;padding:4px 7px;border-radius:6px}.fleet-vehicle-drawing{width:100%;height:82px;color:var(--state);margin:5px 0}.fleet-state{background:var(--tint);color:var(--state);font-size:12px;font-weight:750;padding:5px 8px;border-radius:7px;align-self:flex-start}.fleet-vehicle-card p{font-size:12px;line-height:1.4;margin:8px 0 0}.fleet-vehicle-card small{font-size:11px;color:#60757a;margin-top:3px}.fleet-reason{color:var(--state)}.fleet-card-footer{font-size:11px;color:#087f83;margin-top:10px}.fleet-board{padding:16px!important;margin:0!important}.ret-modal{width:min(1640px,96vw);max-width:none}.ret-embedded .fleet-back{display:none}@media(max-width:1100px){.fleet-header-filters{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:600px){.workspace-header{padding:12px 16px}.fleet-header-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.fleet-summary .fleet-stat{width:calc((100% - 20px)/3);padding:10px}.fleet-stat strong{font-size:29px}.fleet-stat[open]{width:100%}.fleet-top{flex-wrap:wrap}.fleet-vehicle-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.fleet-vehicle-card{padding:10px}.fleet-card-heading{flex-wrap:wrap}.fleet-card-heading strong{font-size:16px}.fleet-owner{font-size:10px}.fleet-board{padding:10px!important}}@media(max-width:350px){.fleet-vehicle-grid{grid-template-columns:1fr}}

.fleet-unavailable-stat{position:relative}.fleet-stat-trigger{padding:0!important;border:0!important;background:transparent!important;color:inherit!important;text-align:left;width:100%;box-shadow:none!important}.fleet-stat-trigger>span{font-size:12px;color:#60757a}.fleet-stat-trigger small{display:block;color:#a7353e;font-size:11px;margin-top:4px}.fleet-reason-tooltip{display:none;position:absolute;top:calc(100% + 5px);right:0;width:260px;max-width:calc(100vw - 45px);padding:14px;background:#fff;border:1px solid #dce7e9;border-radius:12px;box-shadow:0 8px 25px #12333a24;z-index:20}.fleet-unavailable-stat:hover .fleet-reason-tooltip,.fleet-unavailable-stat:focus-within .fleet-reason-tooltip,.fleet-unavailable-stat.tooltip-open .fleet-reason-tooltip{display:block}.fleet-reason-tooltip>strong{font-size:13px;margin:0}.fleet-reason-tooltip ul{list-style:none;padding:0;margin:8px 0 0}.fleet-reason-tooltip li{display:flex;justify-content:space-between;gap:12px;padding:6px 0;font-size:12px}.fleet-state-group{--state:#17875b;padding:14px 0;margin-top:10px;border-top:2px solid #e7efec}.fleet-state-group.booked{--state:#2278c5}.fleet-state-group.warning{--state:#a36d05}.fleet-state-group.unavailable{--state:#c93545}.fleet-state-group h3{display:flex;align-items:center;gap:8px;color:var(--state);font-size:15px;margin:0 0 12px}.fleet-state-group h3 span{background:#eff4f5;padding:3px 8px;border-radius:8px;font-size:12px}.fleet-state-group.drop-over{background:#eaf6f6;outline:2px dashed #07979a;outline-offset:3px;border-radius:10px}.fleet-card-wrap{min-width:0;display:flex;flex-direction:column}.fleet-card-wrap .fleet-vehicle-card{flex:1}.fleet-change-state{align-self:flex-start;font-size:11px;padding:5px 8px;margin-top:5px;background:#fff;color:#087f83;border:1px solid #dae7e8;border-radius:7px}.fleet-capacities{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:5px;margin-top:10px}.fleet-capacities span{background:#f3f7f8;border-radius:7px;padding:5px;text-align:center;min-width:0}.fleet-capacities b{display:block;font-size:11px;overflow-wrap:anywhere}.fleet-capacities small{display:block;font-size:10px}.fleet-vehicle-card.is-dragging{opacity:.45}.fleet-state-dialog{width:min(480px,92vw);border:1px solid #dce7e9;border-radius:16px;padding:20px;max-height:90vh;overflow:auto}.fleet-state-dialog::backdrop{background:#12333a66}.fleet-state-dialog h2{font-size:20px;margin:0 0 12px}.fleet-state-dialog label{display:block;margin:12px 0;font-size:13px}.fleet-state-dialog select,.fleet-state-dialog textarea,.fleet-state-dialog input[type=date]{display:block;width:100%;margin-top:5px}.fleet-dialog-heading,.fleet-dialog-actions{display:flex;justify-content:space-between;align-items:center;gap:10px}.fleet-dialog-heading button{padding:4px 9px}.fleet-assigned-warning{padding:12px;background:#fff6da;border:1px solid #e0c274;border-radius:8px}.fleet-state-dialog [hidden]{display:none!important}.fleet-stat[open]{width:160px}

/* Tarjetas compactas del tablero. */
.fleet-vehicle-grid{grid-template-columns:repeat(auto-fill,minmax(215px,1fr));gap:10px}.fleet-card-wrap{position:relative}.fleet-vehicle-card{padding:11px 12px;gap:0;border-top-width:3px;border-radius:11px}.fleet-card-heading strong{font-size:19px}.fleet-vehicle-card .fleet-model{font-size:11px;margin:5px 0 0;color:#60757a;line-height:1.35}.fleet-vehicle-drawing{height:58px;margin:7px auto 2px;max-width:190px}.fleet-vehicle-card .fleet-capacity-line{font-size:11px;line-height:1.5;margin:3px 0 9px;white-space:normal;color:#36565d}.fleet-capacity-line b{font-weight:650}.fleet-state{font-size:11px;padding:4px 7px;margin-right:30px}.fleet-vehicle-card .fleet-reason{font-size:11px;padding-right:30px;margin-top:6px}.fleet-card-menu{position:absolute;right:10px;bottom:10px;z-index:2}.fleet-card-menu summary{list-style:none;cursor:pointer;width:28px;height:26px;display:flex;align-items:center;justify-content:center;border-radius:6px;color:#46636a;background:#f1f6f7;font-size:23px;line-height:1}.fleet-card-menu summary::-webkit-details-marker{display:none}.fleet-card-menu summary:hover{background:#dcecee}.fleet-card-menu summary:focus-visible{outline:2px solid #029b9e;outline-offset:2px}.fleet-card-menu[open]{z-index:5}.fleet-card-menu>div{position:absolute;right:0;bottom:32px;min-width:145px;padding:5px;border:1px solid #dce7e9;background:#fff;border-radius:9px;box-shadow:0 5px 18px #12333a25}.fleet-card-menu button{font-size:12px;background:#fff;color:#087f83;padding:8px;border:0;width:100%;text-align:left;border-radius:5px}.fleet-card-menu button:hover{background:#eaf6f6}.fleet-state-group{padding:10px 0}.fleet-state-group h3{margin-bottom:9px}@media(max-width:600px){.fleet-vehicle-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.fleet-vehicle-card{padding:9px}.fleet-card-heading strong{font-size:16px}.fleet-vehicle-drawing{height:48px}.fleet-vehicle-card .fleet-capacity-line{font-size:10px}.fleet-card-menu{right:7px;bottom:8px}.fleet-card-menu>div{min-width:130px}}@media(max-width:350px){.fleet-vehicle-grid{grid-template-columns:1fr}}

.fleet-drop-toolbar{position:sticky;top:0;z-index:8;background:#fff;padding:10px 0 12px;border-bottom:1px solid #dce7e9;box-shadow:0 5px 8px #fff;margin-bottom:10px}.fleet-drop-toolbar>div:first-child>small{display:block;margin-top:4px;font-size:11px;color:#60757a}.fleet-drop-targets{display:flex;flex-wrap:wrap;gap:7px}.fleet-drop-target{--state:#17875b;--tint:#eaf8f0;color:var(--state);background:var(--tint);border:1px solid var(--state);border-radius:9px;min-height:40px;padding:9px 12px;font-size:12px;font-weight:750;transition:background .15s,box-shadow .15s}.fleet-drop-target.booked{--state:#2278c5;--tint:#ebf4ff}.fleet-drop-target.warning{--state:#a36d05;--tint:#fff6da}.fleet-drop-target.unavailable{--state:#c93545;--tint:#fff0f2}.fleet-drop-target:before{content:'';display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--state);margin-right:6px}.fleet-drop-target.drop-over{background:var(--state);color:#fff;box-shadow:0 0 0 3px var(--tint);outline:2px dashed var(--state);outline-offset:3px}.fleet-drop-target.drop-over:before{background:#fff}.fleet-drop-target:focus-visible{outline:3px solid #029b9e;outline-offset:3px}.fleet-state-group{scroll-margin-top:145px}@media(max-width:600px){.fleet-drop-toolbar{gap:8px}.fleet-drop-targets{width:100%;gap:5px}.fleet-drop-target{flex:1 1 auto;min-height:38px;padding:8px;font-size:11px}.fleet-drop-toolbar h2{font-size:16px}.fleet-state-group{scroll-margin-top:180px}}

.fleet-drop-toolbar{justify-content:flex-start;padding:8px 0 10px}.fleet-drop-targets{width:100%}.fleet-state-count{display:inline-flex;align-items:center;justify-content:center;min-width:23px;height:23px;padding:0 5px;margin-left:6px;border-radius:7px;background:#fff9;color:inherit;font-size:13px}.fleet-drop-target:before{display:none}.fleet-drop-wrap{display:flex}.fleet-drop-wrap>.fleet-drop-target{width:100%}.fleet-state-group.maintenance{--state:#c93545}.fleet-state-group{scroll-margin-top:90px}@media(max-width:600px){.fleet-drop-wrap{flex:1 1 auto}.fleet-state-count{font-size:12px;min-width:20px;height:20px;margin-left:4px}.fleet-state-group{scroll-margin-top:140px}}
.fleet-drop-target[data-drop-reason="other"]{--state:#79283d;--tint:#f8e9ee;background:var(--state);color:#fff}.fleet-drop-target[data-drop-reason="other"] .fleet-state-count{background:#ffffff26;color:#fff}.fleet-drop-target[data-drop-reason="other"]:hover{background:#632033}.fleet-drop-target[data-drop-reason="other"].drop-over{background:#632033;box-shadow:0 0 0 3px var(--tint);outline-color:var(--state)}
.fleet-board-columns{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;align-items:start}.fleet-board-column{min-width:0}.fleet-board-column .fleet-state-group{margin-top:0;border-top:0;padding-top:6px;min-height:100px}.fleet-board-column .fleet-state-group+ .fleet-state-group{margin-top:16px;border-top:1px solid #e7efec;padding-top:12px}.fleet-board-column .fleet-vehicle-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.fleet-board-column .fleet-card-heading strong{font-size:16px}.fleet-board-column .fleet-vehicle-card{padding:9px}.fleet-board-column .fleet-vehicle-drawing{height:48px}.fleet-board-column .fleet-model,.fleet-board-column .fleet-capacity-line{font-size:10px}.fleet-reason-memory{position:relative;display:block;margin:6px 0}.fleet-reason-memory summary{cursor:pointer;display:flex;justify-content:space-between;gap:10px;font-size:12px;padding:8px 10px;background:#f8e9ee;color:#79283d;border-radius:7px;list-style:none}.fleet-reason-memory summary::-webkit-details-marker{display:none}.fleet-memory-plates{display:none;padding:8px 10px;background:#fff;border:1px solid #ead1d9;border-radius:7px;font-size:12px;line-height:1.8;box-shadow:0 3px 10px #12333a12}.fleet-memory-plates a{display:inline-block;margin-right:10px}.fleet-reason-memory:hover>.fleet-memory-plates,.fleet-reason-memory:focus-within>.fleet-memory-plates,.fleet-reason-memory[open]>.fleet-memory-plates{display:block}.fleet-reason-tooltip .fleet-reason-memory{margin:4px 0}.fleet-reason-tooltip .fleet-reason-memory summary{padding:6px}.fleet-reason-tooltip .fleet-memory-plates{box-shadow:none}.fleet-memory-help{font-size:11px;color:#60757a;margin:6px 0 10px}@media(max-width:1100px){.fleet-board-column .fleet-vehicle-grid{grid-template-columns:1fr}}@media(max-width:650px){.fleet-board-columns{display:flex;overflow-x:auto;gap:12px;scroll-snap-type:x proximity;padding-bottom:12px}.fleet-board-column{flex:0 0 85%;scroll-snap-align:start}.fleet-board-column .fleet-vehicle-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:400px){.fleet-board-column .fleet-vehicle-grid{grid-template-columns:1fr}}

.workspace-header.fleet-page-header{position:sticky;top:0;z-index:20}
.fleet-page-header .fleet-drop-toolbar{position:static;flex:1 0 100%;margin:0;padding:10px 0 0;background:transparent;border-top:1px solid #34575b;border-bottom:0;box-shadow:none}
.fleet-page-header .fleet-drop-target{background:var(--tint);color:var(--state);border:1px solid var(--state);padding:8px 11px;font-size:12px;min-height:38px}
.fleet-page-header .fleet-drop-target[data-drop-reason="other"]{background:#79283d;color:#fff}
.fleet-page-header .fleet-drop-target.drop-over{background:var(--state);color:#fff}
.fleet-page-header .fleet-reason-tooltip{color:#17343b}

.fleet-board-columns{align-items:stretch;gap:14px}
.fleet-board-column{--lane-border:#dce5e7;--lane-tint:#f8fafb;border:1px solid var(--lane-border);border-radius:13px;background:var(--lane-tint);padding:11px;min-height:320px}
.fleet-board-column.fleet-lane-available{--lane-border:#c5e2d3;--lane-tint:#f4faf6}
.fleet-board-column.fleet-lane-booked{--lane-border:#cbdff1;--lane-tint:#f3f8fd}
.fleet-board-column.fleet-lane-warning{--lane-border:#e8dfcb;--lane-tint:#fcfaf5}
.fleet-board-column .fleet-state-group{padding:0;border:0;min-height:105px}
.fleet-board-column .fleet-state-group h3{padding-bottom:9px;border-bottom:1px solid var(--lane-border);margin-bottom:10px}
.fleet-board-column .fleet-state-group+.fleet-state-group{margin-top:14px;padding-top:14px;border-top:1px solid #e4d7d7}
.fleet-board-column .fleet-state-group.maintenance h3{border-bottom-color:#ecd2d5}
.fleet-board-column .fleet-vehicle-grid>.fleet-sub{grid-column:1/-1;display:flex;align-items:center;justify-content:center;min-height:78px;margin:0;padding:12px;border:1px dashed var(--lane-border);border-radius:9px;background:#ffffff80;text-align:center;color:#627d88;font-size:12px}
.fleet-board-column .fleet-state-group.drop-over{background:#e5f5f4;outline:2px dashed #07979a;outline-offset:4px}
@media(max-width:650px){.fleet-board-columns{align-items:stretch}.fleet-board-column{padding:9px;min-height:240px}}
.fleet-km-margin{font-size:10px;color:#6c570c;margin:5px 0 0}.fleet-page-header .fleet-km-weekly{font-size:12px;background:#1bc7c6;color:#042529;padding:8px 12px}.fleet-card-menu a{display:block;font-size:12px;padding:8px;text-decoration:none}
.fleet-card-wrap.priority-before:before,.fleet-card-wrap.priority-after:after{content:"";position:absolute;left:0;right:0;height:4px;background:#008f92;border-radius:4px;z-index:5}.fleet-card-wrap.priority-before:before{top:-6px}.fleet-card-wrap.priority-after:after{bottom:-6px}
.fleet-card-main{display:contents;color:inherit;text-decoration:none}.fleet-card-main:focus-visible .fleet-card-heading{outline:2px solid #029b9e;outline-offset:3px}.fleet-plan-maintenance{text-decoration:none;cursor:pointer}.fleet-plan-maintenance:hover{text-decoration:underline}.fleet-plan-maintenance:focus-visible{outline:2px solid #029b9e;outline-offset:3px}
.fleet-vehicle-card.papers,.fleet-state-group.papers,.fleet-drop-target.papers{--state:#81283f;--tint:#fff0f2}.fleet-paper-alert{font-size:11px;margin:5px 0;color:#81283f}.fleet-state-group.papers{border:1px solid #e6bec9;border-radius:9px;padding:12px;background:#fff7f9}
</style>
<style>.fleet-state-dialog.fleet-state-closing{width:min(680px,94vw)}.fleet-closing-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 12px}.fleet-closing-grid label{min-width:0;margin:4px 0}.fleet-closing-grid input,.fleet-closing-grid select{width:100%;min-width:0;margin-top:5px}@media(max-width:580px){.fleet-closing-grid{grid-template-columns:1fr}}</style><style>.fleet-agenda-preview{position:relative}.fleet-agenda-popover{display:none;position:absolute;bottom:calc(100% + 6px);left:0;width:320px;max-width:80vw;max-height:320px;overflow:auto;background:white;border:1px solid #bad4e9;border-radius:10px;padding:12px;color:#172129;box-shadow:0 8px 25px #0003;z-index:20;font-size:12px}.fleet-agenda-popover p{border-top:1px solid #e5edf3;padding-top:8px;line-height:1.5}.fleet-agenda-preview:hover .fleet-agenda-popover,.fleet-agenda-preview:focus-within .fleet-agenda-popover,.fleet-agenda-preview.open .fleet-agenda-popover{display:block}.fleet-vehicle-card.booked.maintenance-reminder{--state:#bf8000;--tint:#fff4d8;border-color:#e3b64d;background:#fffaf0}.fleet-vehicle-card.booked.maintenance-scheduled,.fleet-vehicle-card.warning.maintenance-scheduled{--state:#c75f00;--tint:#ffead5;border-color:#e99443;background:#fff5eb}.fleet-vehicle-card.maintenance-scheduled .fleet-maintenance-reminder{color:#a74f00}.fleet-maintenance-reminder{display:block;margin:5px 0;font-size:11px;color:#966200}.fleet-maintenance-reminder a{color:inherit;font-weight:700}</style><style>
.fleet-board-column .fleet-workshop-compact{padding:7px 9px;border-top-width:2px}
.fleet-workshop-compact .fleet-vehicle-drawing,.fleet-workshop-compact .fleet-capacity-line,.fleet-workshop-compact .fleet-km-margin{display:none}
.fleet-board-column .fleet-workshop-compact .fleet-card-heading strong{font-size:14px}
.fleet-board-column .fleet-workshop-compact .fleet-model{margin:3px 0 5px;font-size:10px}
.fleet-workshop-compact .fleet-reason{font-size:10px;line-height:1.35;margin:4px 0 0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.fleet-workshop-compact .fleet-state{font-size:10px;padding:3px 6px}
</style><style>
/* Muestra reversible: fichas compactas con capacidad visible. */
.fleet-board-column .fleet-summary-compact{padding:7px 9px;border-top-width:2px}
.fleet-summary-compact .fleet-vehicle-drawing{display:none}
.fleet-board-column .fleet-summary-compact .fleet-card-heading strong{font-size:14px}
.fleet-summary-compact .fleet-owner{font-size:10px;padding:3px 5px}
.fleet-board-column .fleet-summary-compact .fleet-model{margin:3px 0 5px;font-size:10px;line-height:1.3}
.fleet-board-column .fleet-summary-compact .fleet-capacity-line{display:block;font-size:10px;line-height:1.4;margin:0 0 6px;padding:4px 5px;background:#f3f7f8;border-radius:5px}
.fleet-summary-compact .fleet-state{font-size:10px;padding:3px 6px}
.fleet-summary-compact .fleet-km-margin,.fleet-summary-compact .fleet-paper-alert,.fleet-summary-compact .fleet-maintenance-reminder{font-size:10px;line-height:1.35;margin:4px 0 0;padding-right:28px}
.fleet-summary-compact .fleet-reason{font-size:10px;line-height:1.35;margin:4px 0 0;padding-right:28px}
</style>@endpush
@php($maintenanceRows = $rows->where('display_state', 'unavailable')->where('unavailability_category', 'En mantención'))
@php($otherUnavailableRows = $rows->where('display_state', 'unavailable')->reject(fn ($row) => $row['unavailability_category'] === 'En mantención'))
@php($unavailableGroups = $otherUnavailableRows->groupBy(fn ($row) => match (mb_strtolower(trim($row['vehicle']->operational_status ?? ''))) { 'vendido', 'sold' => 'Vendidos', 'robado', 'stolen' => 'Robados', default => $row['unavailability_category'] }))
@section('header-filters')
<a class="button fleet-km-weekly" href="{{ route('fleet.kilometers.index', ['captura' => 'all']) }}" data-ret-modal title="Ingresar kilometrajes semanales" aria-label="KM: ingresar kilometrajes de todas las patentes">KM</a>
<a class="button-quiet" href="{{ route('fleet.vehicles.upcoming-documents.export', request()->only(['empresa', 'estado', 'tipo', 'patente', 'pool'])) }}" title="Exportar Excel: revisión técnica, gases y permiso de circulación desde hoy hasta siete días más, ambas fechas incluidas">Documentos próximos a vencer · Excel</a>
<div class="fleet-section-heading fleet-drop-toolbar">
        <div class="fleet-drop-targets" aria-label="Destinos de arrastre y cantidades de vehículos">
            <button type="button" class="fleet-drop-target available" data-drop-state="available">Disponibles <b class="fleet-state-count" data-count-state="available">{{ $rows->where('display_state', 'available')->count() }}</b></button>
            <button type="button" class="fleet-drop-target booked" data-drop-state="booked">Agendados <b class="fleet-state-count" data-count-state="booked">{{ $rows->where('display_state', 'booked')->count() }}</b></button>
            <button type="button" class="fleet-drop-target warning" data-drop-state="warning">Programar mantención <b class="fleet-state-count" data-count-state="warning">{{ $rows->where('display_state', 'warning')->count() }}</b></button>
            <button type="button" class="fleet-drop-target papers" data-open-papers="all">Papeles vencidos <b class="fleet-state-count">{{ $rows->where('display_state', 'papers')->count() }}</b></button>
            <button type="button" class="fleet-drop-target unavailable" data-drop-state="unavailable" data-drop-reason="maintenance">En mantención <b class="fleet-state-count" data-count-state="maintenance">{{ $maintenanceRows->count() }}</b></button>
            <div class="fleet-unavailable-stat fleet-drop-wrap">
                <button type="button" class="fleet-drop-target unavailable" data-drop-state="unavailable" data-drop-reason="other" aria-describedby="fleet-reason-tooltip" aria-expanded="false">No disponibles <b class="fleet-state-count" data-count-state="unavailable">{{ $otherUnavailableRows->count() }}</b></button>
                <div id="fleet-reason-tooltip" class="fleet-reason-tooltip"><strong>Resumen por motivo</strong>
                    @forelse($unavailableGroups as $reason => $reasonRows)
                    <details class="fleet-reason-memory"><summary>{{ $reason }} <b>{{ $reasonRows->count() }}</b></summary><div class="fleet-memory-plates">@foreach($reasonRows as $reasonRow)<a href="{{ route('fleet.vehicles.show', $reasonRow['vehicle']) }}" data-ret-modal>{{ $reasonRow['vehicle']->plate }}</a>@endforeach</div></details>
                    @empty<p class="fleet-sub">No hay vehículos no disponibles.</p>@endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
@section('content')
<section class="card fleet-board">
    @if($rows->isEmpty())<p class="fleet-sub">No hay vehículos para mostrar.</p>@endif
    <div class="fleet-board-columns">
    @foreach(['available' => 'Disponibles', 'warning' => 'Programar mantención', 'maintenance' => 'En mantención', 'papers' => 'Papeles vencidos', 'unavailable' => 'No disponibles', 'booked' => 'Agendados / ocupados hoy'] as $boardState => $boardTitle)
    @php($groupRows = $boardState === 'maintenance' ? $maintenanceRows : ($boardState === 'unavailable' ? $otherUnavailableRows : $rows->where('display_state', $boardState)))
    @if(in_array($boardState, ['available', 'booked', 'warning']))<div class="fleet-board-column fleet-lane-{{ $boardState }}">@endif

    <section class="fleet-state-group {{ $boardState }}" data-drop-state="{{ $boardState === 'maintenance' ? 'unavailable' : $boardState }}" @if(in_array($boardState, ['maintenance', 'unavailable'])) data-drop-reason="{{ $boardState === 'maintenance' ? 'maintenance' : 'other' }}" @endif data-priority-state="{{ $boardState }}" aria-label="{{ $boardTitle }}">
    <h3>{{ $boardTitle }} <span>{{ $groupRows->count() }}</span></h3>
    @if($boardState === 'unavailable')
        @foreach($unavailableGroups->only(['Vendidos', 'Robados']) as $reason => $reasonRows)
        <details class="fleet-reason-memory"><summary>{{ $reason }} <b>{{ $reasonRows->count() }}</b></summary><div class="fleet-memory-plates">@foreach($reasonRows as $reasonRow)<a href="{{ route('fleet.vehicles.show', $reasonRow['vehicle']) }}" data-ret-modal>{{ $reasonRow['vehicle']->plate }}</a>@endforeach</div></details>
        @endforeach
        @php($groupRows = $groupRows->reject(fn ($row) => in_array(mb_strtolower(trim($row['vehicle']->operational_status ?? '')), ['vendido', 'sold', 'robado', 'stolen'], true)))
    @endif
    <div class="fleet-vehicle-grid">
    @forelse($groupRows as $row)
        @php($vehicle = $row['vehicle'])
        @php($type = mb_strtolower($vehicle->vehicle_type ?? ''))
        <div class="fleet-card-wrap">
        <div draggable="{{ $row['can_move'] ? 'true' : 'false' }}" data-vehicle-id="{{ $vehicle->id }}" data-plate="{{ $vehicle->plate }}" data-current-state="{{ $row['display_state'] }}" data-current-status="{{ $vehicle->operational_status }}" data-revision="{{ $vehicle->updated_at->toISOString() }}" data-assigned-count="{{ $row['assigned_count'] }}" data-status-url="{{ route('fleet.vehicles.status', $vehicle) }}" class="fleet-vehicle-card {{ $row['display_state'] }} {{ in_array($row['display_state'], ['available', 'warning', 'booked', 'papers']) ? 'fleet-summary-compact' : '' }} {{ in_array($row['unavailability_category'], ['En mantención', 'En reparación']) && $row['display_state'] === 'unavailable' ? 'fleet-workshop-compact' : '' }} {{ $row['display_state'] === 'booked' && $row['maintenance_reminder'] ? 'maintenance-reminder' : '' }} {{ in_array($row['display_state'], ['booked', 'warning']) && $row['maintenance_scheduled'] ? 'maintenance-scheduled' : '' }}">
        <a class="fleet-card-main" href="{{ route('fleet.vehicles.show', $vehicle) }}" data-ret-modal aria-label="Ver ficha de {{ $vehicle->plate }}: {{ $row['display_label'] }}">
            <div class="fleet-card-heading"><strong>{{ $vehicle->plate }}</strong><span class="fleet-owner">{{ $row['owner']?->code ?? 'Por verificar' }}</span></div>
            <p class="fleet-model">{{ trim($vehicle->brand.' '.$vehicle->model) ?: 'Modelo sin informar' }} · {{ $vehicle->vehicle_type }}</p>
            <svg class="fleet-vehicle-drawing" viewBox="0 0 240 100" fill="none" aria-hidden="true">
                <path d="M12 86H228" stroke="#dce7e9" stroke-width="2"/>
                @if(str_contains($type, 'rampla') || str_contains($type, 'remolque'))
                    <rect x="17" y="20" width="194" height="48" rx="5" fill="currentColor" opacity=".13" stroke="currentColor" stroke-width="3"/><path d="M18 69H221M202 70V82M27 29H200" stroke="currentColor" stroke-width="3"/><circle cx="48" cy="77" r="10" fill="#314e56"/><circle cx="74" cy="77" r="10" fill="#314e56"/><circle cx="100" cy="77" r="10" fill="#314e56"/>
                @elseif(str_contains($type, 'camión') || str_contains($type, 'camion') && ! str_contains($type, 'camioneta') || str_contains($type, 'tracto'))
                    <rect x="16" y="15" width="135" height="56" rx="5" fill="currentColor" opacity=".15" stroke="currentColor" stroke-width="3"/><path d="M154 35H186L209 56V76H154Z" fill="currentColor" opacity=".2"/><path d="M154 35H186L209 56V76H154V35ZM165 42H182L197 57H165Z" stroke="currentColor" stroke-width="3"/><circle cx="47" cy="77" r="11" fill="#314e56"/><circle cx="75" cy="77" r="11" fill="#314e56"/><circle cx="184" cy="77" r="11" fill="#314e56"/>
                @else
                    <path d="M39 71V37Q39 27 51 27H142L168 49H190Q201 49 203 62V74H39Z" fill="currentColor" opacity=".15"/><path d="M39 71V37Q39 27 51 27H142L168 49H190Q201 49 203 62V74H39ZM119 34H139L157 49H119Z" stroke="currentColor" stroke-width="3"/><rect x="53" y="35" width="54" height="22" rx="3" stroke="currentColor" stroke-width="2"/><circle cx="73" cy="75" r="11" fill="#314e56"/><circle cx="171" cy="75" r="11" fill="#314e56"/>
                @endif
            </svg>
            <p class="fleet-capacity-line" aria-label="Capacidad de carga">
                <span title="Carga útil máxima">Carga: <b>{{ $vehicle->payload_capacity_kg ? number_format($vehicle->payload_capacity_kg, 0, ',', '.') . ' kg' : '— kg' }}</b></span><span aria-hidden="true"> · </span><span title="Capacidad de pallets"><b>{{ $vehicle->max_pallets ? $vehicle->max_pallets . ($vehicle->max_pallets == 1 ? ' pallet' : ' pallets') : '— pallets' }}</b></span><span aria-hidden="true"> · </span><span title="Volumen de carga"><b>{{ ($vehicle->capacity_m3 ?: ($vehicle->max_volume_liters ? $vehicle->max_volume_liters / 1000 : null)) ? number_format($vehicle->capacity_m3 ?: $vehicle->max_volume_liters / 1000, 1, ',', '.') . ' m³' : '— m³' }}</b></span>
            </p>
            </a>
            @if($row['display_state'] === 'warning' && $row['maintenance_scheduled'])
            @php($scheduledJob = $row['open_maintenances']->filter(fn ($job) => $job->status === 'scheduled' && $job->scheduled_at && ($job->execution_type === 'internal' ? filled($job->responsible_user_id) : filled($job->provider_id)))->sortBy('scheduled_at')->first())
            @if($scheduledJob)<a class="fleet-state" href="{{ route('fleet.maintenance.show', $scheduledJob) }}" data-ret-modal>Mantención agendada · {{ $scheduledJob->scheduled_at->format('d/m/Y') }}</a>@else<span class="fleet-state">Mantención agendada</span>@endif
            @elseif($row['display_state'] === 'warning' && $row['km'] && $row['km']['can_plan'])
            <a class="fleet-state fleet-plan-maintenance" href="{{ route('fleet.maintenance.create', ['vehicle_id' => $vehicle->id]) }}" data-ret-modal title="Programar mantención; el vehículo sigue disponible para servicios">Programar mantención</a>
            @elseif($row['display_state'] === 'booked')
            <div class="fleet-agenda-preview"><button type="button" class="fleet-state" data-agenda-preview aria-expanded="false" aria-controls="agenda-{{ $vehicle->id }}" style="min-height:0;padding:4px 7px;border:0">Agendado hoy · ver servicios</button>
            <div class="fleet-agenda-popover" id="agenda-{{ $vehicle->id }}" role="region" aria-label="Servicios de hoy · {{ $vehicle->plate }}"><strong>{{ $vehicle->plate }} · Servicios de hoy</strong>
            @forelse($row['today_services'] as $service)<p><b>{{ $service->ret_code }} · {{ $service->client_name_snapshot }}</b><br>{{ substr($service->window_start ?? '',0,5) ?: 'Sin horario' }} – {{ substr($service->window_end ?? '',0,5) ?: 'Sin horario' }} · {{ $service->serviceType?->name ?: 'Servicio' }}<br>{{ $service->point_name_snapshot }} · {{ $service->packages ?? '—' }} bultos<br>Conductor: {{ $service->driver?->name ?: 'Sin asignar' }}</p>@empty<p>No hay detalle de servicios visible para esta empresa.</p>@endforelse
            </div></div>
            @elseif($row['display_state'] === 'papers')
            <button type="button" class="fleet-state" data-open-papers="{{ $vehicle->id }}">Papeles vencidos · ver detalle</button>
            @elseif($row['can_move'] && $row['can_close_maintenance'] && in_array($row['unavailability_category'], ['En mantención', 'En reparación']))
            <button type="button" class="fleet-state" data-complete-vehicle="{{ $vehicle->id }}" title="Cerrar mantención y dejar disponible" style="min-height:0;padding:4px 7px;border:0">No disponible</button>
            @else
            <span class="fleet-state">{{ $row['display_label'] }}</span>
            @endif
            @if($row['display_state'] === 'booked' && $row['maintenance_reminder'])
            @php($reminderJob = $row['maintenance_scheduled'] ? $row['open_maintenances']->filter(fn ($job) => $job->status === 'scheduled' && $job->scheduled_at && ($job->execution_type === 'internal' ? filled($job->responsible_user_id) : filled($job->provider_id)))->sortBy('scheduled_at')->first() : $row['open_maintenances']->whereIn('status', ['pending', 'scheduled'])->sortBy('scheduled_at')->first())
            <p class="fleet-maintenance-reminder"><strong>Ojo: mantención {{ $row['maintenance_scheduled'] ? 'agendada' : 'por programar' }}</strong>@if($reminderJob?->scheduled_at) · {{ $reminderJob->scheduled_at->format('d/m/Y') }}@endif
            @if($row['km'] && $row['km']['can_plan'])<br><a href="{{ $reminderJob ? route('fleet.maintenance.show', $reminderJob) : route('fleet.maintenance.create', ['vehicle_id' => $vehicle->id]) }}" data-ret-modal>{{ $reminderJob ? 'Ver mantención' : 'Programar mantención' }}</a>@endif</p>
            @endif
            @if($row['km'] && $row['km']['remaining'] !== null)<p class="fleet-km-margin">{{ number_format($row['km']['remaining'], 0, ',', '.') }} km para mantención</p>@endif
            @foreach($row['paperwork_alerts'] as $paper)<p class="fleet-paper-alert">{{ $paper['expired'] ? 'Vencido' : 'Por vencer' }} · {{ $paper['label'] }} · {{ $paper['display'] }}</p>@endforeach
            @if($row['unavailability_reason'])<p class="fleet-reason">{{ $row['unavailability_reason'] }}</p>@endif

        </div>
        @if($row['can_move'])
        <details class="fleet-card-menu"><summary aria-label="Opciones de {{ $vehicle->plate }}">⋯</summary><div>@if($row['km'] && $row['km']['can_edit'])<button type="button" data-open-km="{{ $vehicle->id }}">Ingresar kilometraje</button>@endif
@if($row['display_state'] === 'warning' && $row['km'] && $row['km']['can_plan'])<a href="{{ route('fleet.maintenance.create', ['vehicle_id' => $vehicle->id]) }}" data-ret-modal>Programar mantención</a>@endif
<button type="button" data-change-vehicle="{{ $vehicle->id }}">Cambiar estado</button></div></details>
        @endif
        </div>
    @empty
        @if($boardState !== 'unavailable' || $unavailableGroups->only(['Vendidos', 'Robados'])->isEmpty())<p class="fleet-sub">Sin vehículos en este estado.</p>@endif
    @endforelse
    </div>
    </section>
    @if(in_array($boardState, ['available', 'booked', 'unavailable']))</div>@endif
    @endforeach
    </div>

</section>
<p id="fleet-priority-status" class="fleet-sub" role="status" aria-live="polite"></p>
<dialog id="fleet-papers-dialog" class="fleet-state-dialog" style="width:min(820px,94vw)" aria-labelledby="fleet-papers-title">
<div class="fleet-dialog-heading"><h2 id="fleet-papers-title">Papeles vencidos</h2><button type="button" data-close-papers aria-label="Cerrar">×</button></div>
<p><a class="button" id="fleet-papers-export" href="{{ route('fleet.vehicles.expired-documents.export', request()->only(['empresa', 'estado', 'tipo', 'patente', 'pool'])) }}" data-export-base="{{ route('fleet.vehicles.expired-documents.export', request()->only(['empresa', 'estado', 'tipo', 'patente', 'pool'])) }}">Exportar detalle a Excel</a></p>
<p class="fleet-sub">Abre la ficha para revisar el PDF y adjuntar el documento renovado. Se muestran revisión técnica, gases y permiso de circulación.</p>
@if($canManageDocumentAlerts)<p><a href="{{ route('fleet.maintenance.settings') }}" data-ret-modal data-paper-fiche>Configurar avisos y correos destinatarios</a></p>@endif
<div style="overflow:auto"><table style="width:100%;font-size:13px"><thead><tr><th>Patente</th><th>Documento vencido</th><th>Vencimiento</th><th>Gestión</th></tr></thead><tbody>
@foreach($rows->where('display_state', 'papers') as $paperRow)
@foreach(collect($paperRow['paperwork_alerts'])->where('expired', true) as $paper)
<tr data-paper-vehicle="{{ $paperRow['vehicle']->id }}"><td><strong>{{ $paperRow['vehicle']->plate }}</strong>@if($paperRow['unavailability_category'] === 'En mantención' || $paperRow['unavailability_category'] === 'En reparación')<small style="display:block">En taller</small>@endif</td><td>{{ $paper['label'] }}</td><td>{{ $paper['display'] }}</td><td><a href="{{ route('fleet.vehicles.show', $paperRow['vehicle']) }}" data-ret-modal data-paper-fiche>Abrir ficha · adjuntar PDF</a></td></tr>
@endforeach
@endforeach
</tbody></table></div>
@if($rows->where('display_state', 'papers')->isEmpty())<p>Sin vehículos con papeles vencidos comprobados.</p>@endif
</dialog>
<script>
const fleetPapersDialog=document.getElementById('fleet-papers-dialog');
document.querySelectorAll('[data-open-papers]').forEach(button=>button.addEventListener('click',()=>{const id=button.dataset.openPapers;const exportLink=document.getElementById('fleet-papers-export');const exportUrl=new URL(exportLink.dataset.exportBase,location.href);if(id!=='all')exportUrl.searchParams.set('vehicle',id);exportLink.href=exportUrl.toString();fleetPapersDialog.querySelectorAll('[data-paper-vehicle]').forEach(row=>row.hidden=id!=='all'&&row.dataset.paperVehicle!==id);fleetPapersDialog.showModal();}));
document.querySelector('[data-close-papers]').addEventListener('click',()=>fleetPapersDialog.close());
document.querySelectorAll('[data-paper-fiche]').forEach(link=>link.addEventListener('click',()=>fleetPapersDialog.close()));
</script>
<dialog id="fleet-km-dialog" class="fleet-state-dialog" aria-labelledby="fleet-km-title"><form method="post" action="{{ route('fleet.kilometers.store') }}">@csrf<input type="hidden" name="return_to" value="fleet"><input type="hidden" name="semana" value="{{ $kmWeek->toDateString() }}"><div class="fleet-dialog-heading"><h2 id="fleet-km-title">Ingresar kilometraje</h2><button type="button" data-close-km aria-label="Cerrar">×</button></div><p>La alerta de mantención se actualizará al guardar.</p><input type="hidden" id="fleet-km-id"><label>Última mantención<strong id="fleet-km-baseline" style="display:block"></strong></label><label>Actualizar kilometraje<input type="number" min="0" max="2000000000" required id="fleet-km-value"></label><label>Próxima mantención<strong id="fleet-km-due" style="display:block"></strong></label><input type="hidden" id="fleet-km-notes"><button>Guardar kilometraje</button></form></dialog>
<script>

const fleetKmData = @json($fleetKmData);
const fleetKmDialog=document.getElementById('fleet-km-dialog');
document.querySelectorAll('[data-open-km]').forEach(button=>button.addEventListener('click',()=>{const id=button.dataset.openKm,data=fleetKmData[id];document.getElementById('fleet-km-title').textContent='Ingresar kilometraje · '+data.plate;const num=v=>v===null?'Sin registro':Number(v).toLocaleString('es-CL')+' km';document.getElementById('fleet-km-baseline').textContent=num(data.baseline);document.getElementById('fleet-km-due').textContent=num(data.due);const input=document.getElementById('fleet-km-value');input.name='readings['+id+'][odometer_km]';input.value=data.current??'';const key=document.getElementById('fleet-km-id');key.name='readings['+id+'][vehicle_id]';key.value=id;const notes=document.getElementById('fleet-km-notes');notes.name='readings['+id+'][notes]';notes.value=data.notes??'';fleetKmDialog.showModal();input.focus();}));
document.querySelector('[data-close-km]').addEventListener('click',()=>fleetKmDialog.close());
</script>
<dialog id="fleet-state-dialog" class="fleet-state-dialog" aria-labelledby="fleet-state-title">
    <form method="post" id="fleet-state-form">@csrf @method('patch')
        <div class="fleet-dialog-heading"><h2 id="fleet-state-title">Cambiar estado</h2><button type="button" data-close-state aria-label="Cerrar">×</button></div>
        <input type="hidden" name="expected_status"><input type="hidden" name="expected_revision">
        <label>Estado de destino<select name="target_state" required><option value="available">Disponible</option><option value="warning">Programar mantención</option><option value="unavailable">No disponible</option></select></label>
        <label id="fleet-reason-code">Motivo<select name="reason_code"><option value="maintenance">Mantención</option><option value="repair">Reparación</option><option value="stolen">Robado</option><option value="sold">Vendido</option><option value="other">Otro</option></select></label>
        <label id="fleet-maintenance-date">Fecha de mantención próxima<input type="date" name="next_maintenance_at" min="{{ today('America/Santiago')->toDateString() }}" max="{{ today('America/Santiago')->addDays(30)->toDateString() }}"></label>
        <fieldset id="fleet-maintenance-closing" hidden style="border:1px solid #dce8e9;border-radius:8px;padding:12px;margin:12px 0"><legend>Cierre de mantención</legend>
            <input type="hidden" name="close_maintenance" value="1" disabled>
            <label>Mantención<select name="closing[maintenance_id]" data-closing-job disabled></select></label>
            <div class="fleet-closing-grid">
            <label>Tipo de ejecución<select name="closing[execution_type]" data-closing-execution disabled><option value="internal">Interna</option><option value="external">Externa</option></select></label>
            <label>Proveedor que realizó el trabajo<select name="closing[provider_id]" data-closing-provider disabled><option value="">Sin proveedor / interna</option></select></label>
            <label>Número de presupuesto / cotización<input name="closing[quotation_number]" maxlength="100" data-closing-quote disabled placeholder="Opcional"></label>
            <label>Monto estimado ($)<input name="closing[estimated_cost]" type="number" min="0" step="0.01" data-closing-estimate disabled></label>
            <label>Monto final ($)<input name="closing[actual_cost]" type="number" min="0" step="0.01" data-closing-cost disabled></label>
            <label>Número de factura<input name="closing[document_number]" maxlength="100" disabled placeholder="Si aún no existe, explícalo en el detalle"></label>
            <label>Kilometraje de cierre<input name="closing[closed_odometer_km]" type="number" min="0" data-closing-km disabled></label>
            </div><p class="fleet-sub">La cotización y la factura quedarán asociadas a esta mantención. El cierre y el cambio a disponible se guardan juntos.</p>
        </fieldset>
        <label>Detalle / confirmación<textarea name="reason" rows="3" maxlength="1000" required placeholder="Indica el motivo o cómo se confirmó la habilitación."></textarea></label>
        <p id="fleet-enable-note" class="fleet-sub">Al habilitarlo se retirará la fecha de alerta manual. Las mantenciones pendientes o en curso deben revisarse primero.</p>
        <label id="fleet-assigned-warning" class="fleet-assigned-warning"><input type="checkbox" name="assigned_acknowledged" value="1"><span></span></label>
        <p class="fleet-sub">El cambio quedará registrado con tu nombre, fecha y motivo.</p>
        <div class="fleet-dialog-actions"><button type="button" data-close-state class="button-quiet">Cancelar</button><button type="submit">Confirmar cambio</button></div>
    </form>
</dialog>
<dialog id="fleet-booked-dialog" class="fleet-state-dialog" aria-labelledby="fleet-booked-title"><h2 id="fleet-booked-title">Agendar vehículo</h2><p>El estado azul se obtiene al asignar el vehículo a un retiro agendado para hoy.</p>@if(app(\App\Fleet\FleetAccess::class)->allows(auth()->user(), $serviceTenant, 'operations.scheduling'))<a class="button" href="{{ route('fleet.page.operations-scheduling') }}">Ir a Programación</a>@endif<button type="button" data-close-booked class="button-quiet">Cerrar</button></dialog>
<script>
(() => {
    document.querySelectorAll('[data-agenda-preview]').forEach(button=>{button.addEventListener('click',()=>{const open=button.closest('.fleet-agenda-preview').classList.toggle('open');button.setAttribute('aria-expanded',String(open));});button.addEventListener('keydown',event=>{if(event.key==='Escape'){button.closest('.fleet-agenda-preview').classList.remove('open');button.setAttribute('aria-expanded','false');button.blur();}});});
    const tooltipCard = document.querySelector('.fleet-unavailable-stat');
    const tooltipTrigger = tooltipCard.querySelector('button');
    tooltipTrigger.addEventListener('click', () => { const open = !tooltipCard.classList.contains('tooltip-open'); tooltipCard.classList.toggle('tooltip-open', open); tooltipTrigger.setAttribute('aria-expanded', String(open)); if (!open) tooltipTrigger.blur(); });
    document.addEventListener('pointerdown', event => { if (!tooltipCard.contains(event.target)) { tooltipCard.classList.remove('tooltip-open'); tooltipTrigger.setAttribute('aria-expanded', 'false'); } });
    tooltipCard.addEventListener('keydown', event => { if (event.key === 'Escape') { tooltipCard.classList.remove('tooltip-open'); tooltipTrigger.setAttribute('aria-expanded', 'false'); tooltipTrigger.blur(); } });
    const dialog = document.getElementById('fleet-state-dialog');
    const bookedDialog = document.getElementById('fleet-booked-dialog');
    const form = document.getElementById('fleet-state-form');
    let selected = null, dragged = null, suppressClickUntil = 0;
    const closingData = @json($fleetClosingData);
    const closingFields = document.getElementById('fleet-maintenance-closing');
    const closingJob = form.querySelector('[data-closing-job]');
    let closingVehicle = null;
    function fillClosingJob(){
        const job=closingVehicle?.jobs.find(job=>String(job.id)===closingJob.value);
        form.querySelector('[data-closing-execution]').value=job?.execution_type || 'internal';
        form.querySelector('[data-closing-provider]').value=job?.provider_id || '';
        form.querySelector('[data-closing-quote]').value=job?.quotation_number || '';
        const estimate=form.querySelector('[data-closing-estimate]');estimate.value=job?.estimated_cost ?? '';estimate.readOnly=!!job;
        form.querySelector('[data-closing-km]').value=closingVehicle?.odometer ?? job?.reported_odometer_km ?? '';
        updateClosingProvider();
    }
    function updateClosingProvider(){const provider=form.querySelector('[data-closing-provider]');provider.required=!closingFields.hidden&&form.querySelector('[data-closing-execution]').value==='external';}
    closingJob.addEventListener('change',fillClosingJob);
    form.querySelector('[data-closing-execution]').addEventListener('change',updateClosingProvider);

    function updateFields() {
        const state = form.elements.target_state.value;
        const needsClosing=state==='available'&&closingVehicle?.workshop;
        closingFields.hidden=!needsClosing;
        dialog.classList.toggle('fleet-state-closing',!!needsClosing);
        closingFields.querySelectorAll('input,select').forEach(input=>input.disabled=!needsClosing);
        form.querySelector('[data-closing-cost]').required=!!needsClosing;
        form.querySelector('[data-closing-km]').required=!!needsClosing;
        updateClosingProvider();
        const unavailable = state === 'unavailable', warning = state === 'warning';
        document.getElementById('fleet-reason-code').hidden = !unavailable;
        form.elements.reason_code.disabled = !unavailable;
        form.elements.reason_code.required = unavailable;
        document.getElementById('fleet-maintenance-date').hidden = !warning;
        form.elements.next_maintenance_at.disabled = !warning;
        form.elements.next_maintenance_at.required = warning;
        document.getElementById('fleet-enable-note').hidden = state !== 'available';
        const count = Number(selected?.dataset.assignedCount || 0);
        const assignmentWarning = document.getElementById('fleet-assigned-warning');
        assignmentWarning.hidden = !unavailable || count === 0;
        assignmentWarning.querySelector('span').textContent = `Tiene ${count} retiros asignados. Confirmo que coordinaré su reasignación; los retiros se conservarán.`;
        form.elements.assigned_acknowledged.disabled = !unavailable || count === 0;
        form.elements.assigned_acknowledged.required = unavailable && count > 0;
    }
    function openState(card, state, reasonCode) {
        if (card.draggable !== true) return;
        if (state === 'papers') { priorityStatus.textContent = 'Papeles vencidos se actualiza al adjuntar los documentos renovados en la ficha.'; return; }
        if (state === 'booked') { bookedDialog.showModal(); return; }
        selected = card; form.reset(); form.action = card.dataset.statusUrl;
        closingVehicle=closingData[card.dataset.vehicleId];
        closingJob.replaceChildren();
        (closingVehicle?.jobs || []).forEach(job=>closingJob.add(new Option(job.maintenance_type+' · #'+job.id,job.id)));
        if(!closingJob.options.length)closingJob.add(new Option('Registrar cierre del trabajo en taller',''));
        const providers=form.querySelector('[data-closing-provider]');providers.replaceChildren(new Option('Sin proveedor / interna',''));
        (closingVehicle?.providers || []).forEach(provider=>providers.add(new Option(provider.name,provider.id)));
        fillClosingJob();
        form.elements.expected_status.value = card.dataset.currentStatus;
        form.elements.expected_revision.value = card.dataset.revision;
        form.elements.target_state.value = state || (['booked', 'papers'].includes(card.dataset.currentState) ? 'unavailable' : card.dataset.currentState);
        document.getElementById('fleet-state-title').textContent = `Cambiar estado · ${card.dataset.plate}`;
        if (reasonCode) form.elements.reason_code.value = reasonCode;
        updateFields(); dialog.showModal(); form.elements.target_state.focus();
    }
    document.querySelectorAll('[data-complete-vehicle]').forEach(button=>button.addEventListener('click',()=>openState(document.querySelector(`[data-vehicle-id="${button.dataset.completeVehicle}"]`),'available')));
    form.elements.target_state.addEventListener('change', updateFields);
    document.querySelectorAll('[data-close-state]').forEach(button => button.addEventListener('click', () => dialog.close()));
    document.querySelector('[data-close-booked]').addEventListener('click', () => bookedDialog.close());
    document.querySelectorAll('[data-change-vehicle]').forEach(button => button.addEventListener('click', () => { button.closest('details').open = false; openState(document.querySelector(`[data-vehicle-id="${button.dataset.changeVehicle}"]`)); }));
    document.addEventListener('pointerdown', event => { document.querySelectorAll('.fleet-card-menu[open]').forEach(menu => { if (!menu.contains(event.target)) menu.open = false; }); });
    document.querySelectorAll('[data-vehicle-id]').forEach(card => {
        card.addEventListener('dragstart', event => { if (!card.draggable || prioritySaving) { event.preventDefault(); return; } dragged = card; event.dataTransfer.setData('text/plain', card.dataset.vehicleId); event.dataTransfer.effectAllowed = 'move'; card.classList.add('is-dragging'); });
        card.addEventListener('dragend', () => { card.classList.remove('is-dragging'); dragged = null; clearPriorityMarkers(); suppressClickUntil = Date.now() + 400; document.querySelectorAll('.drop-over').forEach(group => group.classList.remove('drop-over')); });
    });
    let prioritySaving = false;
    const priorityStatus = document.getElementById('fleet-priority-status');
    function clearPriorityMarkers() { document.querySelectorAll('.priority-before,.priority-after').forEach(element => element.classList.remove('priority-before','priority-after')); }
    function insertionTarget(group, event) {
        const wrap = event.target.closest('.fleet-card-wrap');
        if (!wrap || !group.contains(wrap) || wrap === dragged?.closest('.fleet-card-wrap')) return null;
        const rect = wrap.getBoundingClientRect();
        return {wrap, after: event.clientY > rect.top + rect.height / 2};
    }
    async function reorderWithin(group, event, card) {
        const grid = group.querySelector('.fleet-vehicle-grid'), moved = card.closest('.fleet-card-wrap');
        const previous = [...grid.children], target = insertionTarget(group, event);
        if (event.target.closest('.fleet-card-wrap') === moved) return;
        if (target) grid.insertBefore(moved, target.after ? target.wrap.nextSibling : target.wrap);
        else grid.appendChild(moved);
        const ids = [...grid.querySelectorAll('[data-vehicle-id]')].map(element => Number(element.dataset.vehicleId));
        prioritySaving = true; priorityStatus.textContent = 'Guardando tu orden de prioridad…';
        try {
            const response = await fetch(@json(route('fleet.vehicles.priorities')), {method:'PATCH', credentials:'same-origin', headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':form.querySelector('input[name="_token"]').value}, body:JSON.stringify({state:group.dataset.priorityState, vehicle_ids:ids})});
            const result = await response.json();
            if (!response.ok) throw new Error(result.errors?.state?.[0] || result.message || 'No se pudo guardar el orden.');
            priorityStatus.textContent = result.message;
        } catch (error) { grid.replaceChildren(...previous); priorityStatus.textContent = error instanceof SyntaxError ? 'No se pudo guardar el orden. Se mantuvo tu orden anterior.' : error.message; }
        finally { prioritySaving = false; }
    }
    document.querySelectorAll('[data-drop-state]').forEach(group => {
        group.addEventListener('dragover', event => {
            if (!dragged) return;
            event.preventDefault(); event.dataTransfer.dropEffect = 'move'; group.classList.add('drop-over');
            clearPriorityMarkers();
            if (dragged.closest('.fleet-state-group') === group) {
                const target = insertionTarget(group, event);
                target?.wrap.classList.add(target.after ? 'priority-after' : 'priority-before');
            }
        });
        group.addEventListener('dragleave', event => { if (!group.contains(event.relatedTarget)) { group.classList.remove('drop-over'); clearPriorityMarkers(); } });
        group.addEventListener('drop', event => {
            if (!dragged) return;
            event.preventDefault(); event.stopPropagation(); group.classList.remove('drop-over'); clearPriorityMarkers();
            const card = dragged;
            if (card.closest('.fleet-state-group') === group) { reorderWithin(group, event, card); return; }
            if (card.dataset.currentState !== group.dataset.dropState || group.dataset.dropReason) openState(card, group.dataset.dropState, group.dataset.dropReason);
        });
    });
    document.querySelectorAll('.fleet-drop-target').forEach(button => button.addEventListener('click', () => { if (Date.now() < suppressClickUntil) return; const reasonSelector = button.dataset.dropReason ? `[data-drop-reason="${button.dataset.dropReason}"]` : ''; const section = document.querySelector(`.fleet-state-group[data-drop-state="${button.dataset.dropState}"]${reasonSelector}`); section?.scrollIntoView({behavior:'smooth', block:'start'}); }));
    document.addEventListener('click', event => { if (Date.now() < suppressClickUntil && event.target.closest('[data-vehicle-id]')) { event.preventDefault(); event.stopImmediatePropagation(); } }, true);
})();
</script>
@endsection
