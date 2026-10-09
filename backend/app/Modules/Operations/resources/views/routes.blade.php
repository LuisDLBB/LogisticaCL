@extends('operations::layout')
@section('title','Programación de Rutas')
@section('content')
<style>
.route-intro{max-width:940px}.route-overview{display:flex;flex-wrap:wrap;gap:10px;margin:18px 0 24px}.route-overview a{display:inline-flex;gap:8px;align-items:center;padding:9px 12px;border:1px solid var(--line);border-radius:99px;background:#fff;text-decoration:none;font-size:13px;font-weight:700}.route-overview a:hover{background:var(--turquoise-soft)}
.route-trunk{margin:16px 0;border:1px solid var(--line);border-radius:15px;background:#fff;box-shadow:0 2px 8px rgb(6 31 32 / 4%);scroll-margin-top:90px}.route-trunk>summary{display:flex;align-items:center;gap:13px;padding:18px 21px;cursor:pointer;list-style:none}.route-trunk>summary::-webkit-details-marker,.route-agency>summary::-webkit-details-marker{display:none}.route-trunk>summary::after,.route-agency>summary::after{content:'⌄';margin-left:auto;color:var(--turquoise-dark);font-size:19px}.route-trunk[open]>summary::after,.route-agency[open]>summary::after{content:'⌃'}.route-icon{display:grid;place-items:center;flex:none;width:45px;height:45px;border-radius:12px;background:#e7f8f7;font-size:23px}.route-trunk-title{display:grid;gap:3px}.route-trunk-title strong{font-size:17px}.route-trunk-title small{color:var(--muted)}.route-count{padding:5px 9px;border-radius:99px;background:#f2f6f6;color:#345055;font-size:12px;white-space:nowrap}
.route-agencies{padding:0 19px 19px}.route-agency{margin:11px 0;border:1px solid var(--line);border-radius:11px;background:#fbfdfd;scroll-margin-top:90px}.route-agency>summary{display:flex;align-items:center;gap:10px;padding:14px 16px;cursor:pointer;list-style:none}.route-agency>summary strong{min-width:135px}.route-agency>summary span{color:var(--muted);font-size:13px}.route-detail{padding:0 16px 17px}.route-path{display:grid;gap:0;max-width:920px;margin:12px 0}.route-stage{display:grid;grid-template-columns:50px minmax(0,1fr);gap:10px;position:relative}.route-stage:not(:last-child)::before{content:'';position:absolute;top:49px;bottom:-7px;left:24px;width:2px;background:#80d9d5}.route-stage-icon{position:relative;z-index:1;display:grid;place-items:center;width:48px;height:48px;border-radius:50%;background:#007f82;color:#fff;font-size:23px}.route-stage.air .route-stage-icon{background:#1262a3}.route-stage-card{margin-bottom:17px;padding:13px 16px;border:1px solid var(--line);border-radius:11px;background:#fff}.route-stage.air .route-stage-card{border-color:#c7dff0;background:#f5faff}.route-stage-head{display:flex;flex-wrap:wrap;align-items:baseline;gap:9px}.route-stage-head strong{font-size:15px}.route-stage-head small{color:var(--muted)}.route-locations{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:10px;margin-top:10px;font-size:13px}.route-locations span{min-width:0;overflow-wrap:anywhere}.route-locations b{color:var(--turquoise-dark)}.route-data{margin:9px 0 0;font-size:12px;color:#365357}.route-metric{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:10px}.route-metric strong{padding:5px 8px;border-radius:6px;background:#ddf6f3;color:#006e70;font-size:13px}.route-metric small{color:var(--muted)}.route-metric.missing strong{background:#fff2d8;color:#805016}.route-return-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:11px;max-width:920px}.route-return{padding:14px;border:1px dashed #b7d0d0;border-radius:10px;background:#f8fcfc}.route-return h3{margin:0 0 7px;font-size:14px}.route-return p{margin:3px 0;font-size:12px;color:var(--muted)}.route-estimate-edit{margin-top:10px;border-top:1px solid #e5eeee;padding-top:8px}.route-estimate-edit>summary{cursor:pointer;color:var(--turquoise-dark);font-size:12px;font-weight:750}.route-estimate-edit form{display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin-top:10px}.route-estimate-edit label{display:grid;gap:4px;font-size:12px}.route-estimate-edit input{width:110px;padding:7px;border:1px solid var(--line);border-radius:6px}.route-estimate-edit button{min-height:35px}.route-estimate-edit .route-manual{display:flex;flex-wrap:wrap;gap:8px;align-items:end}.route-map-button{background:#e8f4fa;color:#155c84}.route-map-button:hover{background:#d8ebf7}.route-empty{padding:12px 20px;color:var(--muted)}@media(max-width:720px){.route-trunk>summary{flex-wrap:wrap}.route-agency>summary{flex-wrap:wrap}.route-locations{grid-template-columns:1fr}.route-locations b{transform:rotate(90deg);width:16px}.route-agencies{padding:0 10px 12px}.route-detail{padding:0 10px 14px}}
.route-air-hub{border-color:#bfdde8}.route-air-hub>summary{background:#f2f9fc;border-radius:15px}.route-common-leg{margin:0 19px 17px;padding:16px;border:1px solid #c7dfe8;border-radius:12px;background:#f7fbfd}.route-common-leg h2{display:flex;align-items:center;gap:7px;margin:0 0 5px;font-size:16px}.route-common-leg p{margin:4px 0 10px;color:var(--muted);font-size:13px}.route-air-branches-title{margin:0 19px 8px;font-size:15px}.route-air-branch{border-left:3px solid #88c4d9}.route-air-branch>summary span{color:#1c6580}.route-stage-icon svg{width:28px;height:28px}.route-icon{border-radius:50%;background:#007f82;color:#fff}.route-air-hub .route-icon{background:#1262a3}.route-icon svg{width:27px;height:27px}.route-inline-icon{display:inline-grid;place-items:center;flex:none;width:26px;height:26px;border-radius:50%;background:#007f82;color:#fff}.route-inline-icon svg{width:18px;height:18px}.route-map-link{display:inline-flex;margin-top:9px;font-size:12px;font-weight:700}.route-estimate-edit .route-manual .route-map-input{width:min(420px,80vw)}@media(max-width:720px){.route-common-leg{margin:0 10px 12px}.route-air-branches-title{margin:0 10px 8px}}
.route-visual-board{margin:23px 0;padding:20px;border:1px solid var(--line);border-radius:15px;background:#fff}.route-visual-board h2{margin:0 0 5px}.route-visual-board>p{margin:0 0 18px;color:var(--muted);font-size:13px}.route-visual-scroll{overflow-x:auto;padding-bottom:8px}.route-air-visual{display:grid;grid-template-columns:290px minmax(760px,1fr);gap:20px;min-width:1080px;align-items:center}.route-air-start{display:flex;align-items:center;gap:12px;justify-content:center}.route-air-connector{color:#007f82;font-size:28px;font-weight:800}.route-air-branches{border-left:3px solid #1d6f94;padding-left:28px;display:grid;gap:18px}.route-air-visual-branch{position:relative;border:1px solid #d6e8ed;border-radius:12px;padding:13px 18px;background:#f8fcfd}.route-air-visual-branch::before{content:'➜';position:absolute;left:-28px;top:50%;color:#1d6f94;font-size:23px;transform:translateY(-50%)}.route-air-visual-branch h3{margin:0 0 9px;color:#1262a3;font-size:15px}.route-air-track,.route-ground-track{display:flex;align-items:flex-start;gap:18px}.route-air-stop{position:relative;display:flex;flex-direction:column;align-items:center;min-width:145px}.route-air-stop:not(:last-child)::after,.route-ground-stop:not(:last-child)::after{content:'➜';position:absolute;right:-19px;top:29px;color:#1d6f94;font-size:21px}.route-air-down{color:#008486;font-weight:900;line-height:22px}.route-visual-node{display:flex;flex-direction:column;align-items:center;gap:4px;width:145px;border:0;background:transparent;color:#07383d;cursor:pointer;text-align:center;padding:2px}.route-visual-node:hover .route-visual-symbol,.route-visual-node:focus-visible .route-visual-symbol{outline:3px solid #f1c469;outline-offset:3px;transform:scale(1.06)}.route-visual-node strong{font-size:13px;line-height:1.2}.route-visual-node small{font-size:11px;line-height:1.2;color:var(--muted);max-width:145px}.route-visual-symbol{display:grid;place-items:center;width:55px;height:55px;border-radius:50%;background:#008486;color:#fff;transition:transform .15s}.route-visual-node-plane .route-visual-symbol{background:#1262a3}.route-visual-symbol svg{width:29px;height:29px}.route-ground-visual{margin:16px 0;border:1px solid var(--line);border-radius:12px;background:#fff}.route-ground-visual>summary{cursor:pointer;display:flex;align-items:center;gap:10px;padding:15px;font-weight:750}.route-ground-visual>summary .route-inline-icon{width:33px;height:33px}.route-ground-visual>summary small{margin-left:auto;color:var(--muted);font-weight:500}.route-ground-lane{border-top:1px solid #e5eeee;padding:13px 16px}.route-ground-lane h3{margin:0 0 8px;font-size:13px;color:#006e70}.route-ground-track{overflow-x:auto;gap:18px;padding-bottom:7px}.route-ground-stop{position:relative;flex:none}.route-ground-stop:not(:last-child)::after{color:#008486}.route-visual-dialog{width:min(560px,calc(100vw - 28px));max-height:85vh;border:1px solid #bfd8d8;border-radius:15px;padding:0;box-shadow:0 20px 65px #092f35a3}.route-visual-dialog::backdrop{background:#0223269c}.route-visual-dialog-header{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;background:#edf9f8}.route-visual-dialog-header h2{margin:0;font-size:20px}.route-visual-dialog-close{border:0;background:#fff;border-radius:50%;width:36px;height:36px;cursor:pointer;font-size:24px}.route-visual-details{margin:0;padding:17px 20px;display:grid;gap:0}.route-visual-details div{display:grid;grid-template-columns:150px 1fr;gap:12px;padding:10px 0;border-bottom:1px solid #e5eeee}.route-visual-details div:last-child{border:0}.route-visual-details dt{font-size:12px;font-weight:750;color:#45646a}.route-visual-details dd{margin:0;font-size:14px;overflow-wrap:anywhere}@media(max-width:680px){.route-visual-board{padding:14px}.route-ground-visual>summary{flex-wrap:wrap}.route-visual-details div{grid-template-columns:1fr;gap:2px}}
.route-visual-node:hover,.route-visual-node:focus-visible{background:transparent;color:#07383d}
.route-hub-board{margin:18px 0 25px;padding:20px;border:1px solid var(--line);border-radius:15px;background:#fff}.route-hub-board h2{margin:0 0 5px}.route-hub-board>p{margin:0;color:var(--muted);font-size:13px}.route-hub-origin{display:flex;align-items:center;justify-content:center;gap:13px;margin:20px auto 0;width:fit-content}.route-hub-origin-symbol{display:grid;place-items:center;width:70px;height:70px;border-radius:50%;background:#008486;color:#fff}.route-hub-origin-symbol svg{width:39px;height:39px}.route-hub-origin-text{display:grid;gap:3px}.route-hub-origin-text strong{font-size:17px}.route-hub-origin-text small{color:var(--muted);font-size:12px}.route-hub-stem{width:3px;height:27px;margin:9px auto 0;background:#63bcc0}.route-hub-families{display:grid;grid-template-columns:1fr 1fr;gap:18px}.route-hub-family{position:relative;border:1px solid #d8e8e9;border-radius:12px;padding:16px;background:#f9fcfc}.route-hub-family::before{content:'';position:absolute;top:-18px;left:50%;height:18px;border-left:2px solid #63bcc0}.route-hub-family h3{margin:0 0 12px;color:#006e70;font-size:15px}.route-hub-links{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:9px}.route-hub-link{display:flex;align-items:center;gap:10px;min-height:67px;border:1px solid #c9dedf;border-radius:10px;padding:9px;background:#fff;color:#07383d;text-decoration:none}.route-hub-link:hover,.route-hub-link:focus-visible{border-color:#008486;background:#eefaf9;outline:2px solid #92d1cc;outline-offset:1px}.route-hub-link-symbol{display:grid;place-items:center;flex:none;width:42px;height:42px;border-radius:50%;background:#008486;color:#fff}.route-hub-link-symbol.air{background:#1262a3}.route-hub-link-symbol svg{width:24px;height:24px}.route-hub-link span:last-child{display:grid;gap:3px}.route-hub-link strong{font-size:13px;line-height:1.2}.route-hub-link small{font-size:11px;color:var(--muted)}.route-air-visual-branch,.route-ground-visual{scroll-margin-top:94px}@media(max-width:740px){.route-hub-families{grid-template-columns:1fr}.route-hub-family::before{display:none}.route-hub-stem{height:17px}.route-hub-origin-text small{max-width:190px}}
.route-hub-families{position:relative}.route-hub-families::before{content:'';position:absolute;left:25%;right:25%;top:-18px;border-top:2px solid #63bcc0}@media(max-width:740px){.route-hub-families::before{display:none}}
.route-visual-dialog-form{padding:18px 20px;border-top:1px solid #dceaea;display:grid;grid-template-columns:1fr 1fr;gap:11px}.route-visual-dialog-form h3,.route-visual-dialog-form .route-form-wide{grid-column:1/-1}.route-visual-dialog-form h3{margin:0;font-size:16px}.route-visual-dialog-form label{display:grid;gap:5px;font-size:12px;font-weight:700}.route-visual-dialog-form input,.route-visual-dialog-form select{width:100%;padding:9px;border:1px solid var(--line);border-radius:7px;background:#fff;font:inherit}.route-visual-dialog-form p{margin:0;color:var(--muted);font-size:12px}.route-visual-dialog-form button{justify-self:start}.route-visual-dialog-form [hidden]{display:none!important}@media(max-width:550px){.route-visual-dialog-form{grid-template-columns:1fr}}
.route-ground-stop .route-gap-distance{position:absolute;right:-53px;top:1px;width:88px;text-align:center;color:#006e70;font-size:10px;font-weight:800;z-index:2;pointer-events:none}.route-ground-stop .route-gap-distance.missing{color:#6c8387;font-weight:500}.route-gap-action{padding:0 16px 7px}.route-gap-action button{font-size:12px}
.route-gap-editor{margin:0 16px 12px;border:1px solid #d6e6e8;border-radius:9px;padding:8px 11px;background:#f8fcfc}.route-gap-editor summary{cursor:pointer;color:#006e70;font-size:12px;font-weight:700}.route-gap-editor form{display:grid;grid-template-columns:repeat(auto-fit,minmax(205px,1fr));gap:9px;margin-top:10px}.route-gap-editor label{display:grid;gap:4px;font-size:11px;color:#31575c}.route-gap-editor input{max-width:110px;padding:6px;border:1px solid var(--line);border-radius:6px}.route-gap-editor button{align-self:end;justify-self:start;font-size:12px}
</style>
<h1>Programación de Rutas</h1>
<section class="route-hub-board" aria-labelledby="route-hub-title">
    <h2 id="route-hub-title">Bodega 4 Nortes y troncales</h2>
    <p>Selecciona una troncal para ver su recorrido completo: troncal, postas y agencias de destino.</p>
    <div class="route-hub-origin">
        <span class="route-hub-origin-symbol" aria-hidden="true">
            @include('operations::route-truck-icon')
        </span>
        <span class="route-hub-origin-text"><strong>Bodega 4 Nortes</strong><small>{{ $sharedAirSegment['origin'] ?? 'Galvarino 8481, Bodega 17, Quilicura' }}</small></span>
    </div>
    <div class="route-hub-stem" aria-hidden="true"></div>
    <div class="route-hub-families">
        <section class="route-hub-family" aria-label="Troncales aéreas">
            <h3>Troncales aéreas</h3>
            <div class="route-hub-links">
                @foreach($airBranches as $branch)
                <a class="route-hub-link" href="#air-branch-{{ $branch['trunk_code'] }}">
                    <span class="route-hub-link-symbol air" aria-hidden="true">
                        @include('operations::route-plane-icon')
                    </span>
                    <span><strong>{{ $branch['name'] }}</strong><small>{{ $branch['routes']->count() }} {{ $branch['routes']->count() === 1 ? 'agencia' : 'agencias' }} · ver recorrido</small></span>
                </a>
                @endforeach
            </div>
        </section>
        <section class="route-hub-family" aria-label="Troncales terrestres">
            <h3>Troncales terrestres</h3>
            <div class="route-hub-links">
                @foreach($groundTrunks as $trunk)
                <a class="route-hub-link" href="#visual-trunk-{{ $trunk->id }}">
                    <span class="route-hub-link-symbol" aria-hidden="true">
                        @include('operations::route-truck-icon')
                    </span>
                    @php($agencyCount = $routes->get($trunk->id, collect())->count())
                    <span><strong>{{ $trunk->name }}</strong><small>{{ $agencyCount }} {{ $agencyCount === 1 ? 'agencia' : 'agencias' }} · ver recorrido</small></span>
                </a>
                @endforeach
            </div>
        </section>
    </div>
</section>
<p class="intro route-intro">Los aéreos comparten un tramo de Bodega al aeropuerto y después se separan por destino. Las rutas Sur y Norte muestran también sus descargas consolidadas y los relevos que siguen. Abre cada tramo terrestre en Google Maps y registra el enlace, los kilómetros y el tiempo aproximado que allí consultaste. Las guías existentes conservan sus datos.</p>
<p class="note route-intro">Los 18,6 km de Bodega → Aeropuerto de Santiago y los 25,9 km de Aeropuerto de Antofagasta → agencia provienen de los recorridos que compartiste. El enlace permite revisar la ruta; no calcula ni actualiza las cifras por sí solo.</p>
@if(! $mapsConfigured)
    <p class="note route-intro">Para obtener kilómetros y tiempo automáticamente en los tramos terrestres, puedes activar una <a href="https://account.heigit.org/" target="_blank" rel="noopener noreferrer">clave gratuita de openrouteservice</a>. Una vez configurada en el sistema aparecerá «Calcular con mapas» en cada tramo. Sus resultados pueden diferir de Google Maps y conviene revisarlos.</p>
@else
    <p class="note route-intro">El cálculo automático ya está disponible en los tramos. Si una dirección no se reconoce con precisión, guarda primero el enlace completo de Google Maps del recorrido o ingresa los datos manualmente. Los resultados de openrouteservice pueden diferir de Google Maps.</p>
@endif
@if($sharedAirSegment)
<section class="route-visual-board" aria-labelledby="route-air-map-title">
    <h2 id="route-air-map-title">Recorrido aéreo</h2>
    <p>Pulsa cada camión o avión para ver los datos del tramo. El recorrido común va desde Bodega 4N hasta el aeropuerto de Santiago.</p>
    <div class="route-visual-scroll"><div class="route-air-visual">
        <div class="route-air-start">
            @include('operations::route-visual-node', ['segment' => $sharedAirSegment, 'agencyId' => $sharedAirRoute['agency']->id, 'nodeLabel' => 'Bodega 4N', 'nodeHint' => 'Troncal aérea', 'routeLabel' => 'Troncal Aérea · Bodega a aeropuerto'])
            <span class="route-air-connector" aria-hidden="true">➜</span>
            @include('operations::route-visual-node', ['segment' => $sharedAirSegment, 'agencyId' => $sharedAirRoute['agency']->id, 'nodeType' => 'plane', 'nodeLabel' => 'Aeropuerto Santiago', 'nodeHint' => 'Carga Nacional', 'routeLabel' => 'Troncal Aérea · llegada al aeropuerto'])
        </div>
        <div class="route-air-branches">
            @foreach($airBranches as $branch)
            <section class="route-air-visual-branch" id="air-branch-{{ $branch['trunk_code'] }}" aria-label="{{ $branch['name'] }}">
                <h3>{{ $branch['name'] }}</h3>
                <div class="route-air-track">
                    @foreach($branch['routes'] as $route)
                    @php($agency = $route['agency'])
                    <div class="route-air-stop">
                        @include('operations::route-visual-node', ['segment' => $route['segments']['vuelo'], 'agencyId' => $agency->id, 'nodeType' => 'plane', 'nodeLabel' => $agency->name, 'nodeHint' => 'Llegada aérea', 'routeLabel' => $branch['name'].' · Agencia '.$agency->name, 'agencyNames' => collect([$agency->name])])
                        <span class="route-air-down" aria-hidden="true">↓</span>
                        @include('operations::route-visual-node', ['segment' => $route['segments']['posta1'], 'agencyId' => $agency->id, 'nodeLabel' => 'Reparto local', 'nodeHint' => $route['segments']['posta1']['name'], 'routeLabel' => 'Posta · '.$agency->name, 'agencyNames' => collect([$agency->name])])
                    </div>
                    @endforeach
                </div>
            </section>
            @endforeach
        </div>
    </div></div>
</section>
@endif
<section class="route-visual-board" aria-labelledby="route-ground-map-title">
    <h2 id="route-ground-map-title">Recorridos terrestres</h2>
    <p>Las paradas aparecen en orden por troncal y por posta. Los nodos consolidados reúnen varias agencias hasta el punto de descarga.</p>
    @foreach($groundTrunks as $trunk)
    @php($visualLegs = $groundVisuals->get($trunk->id, collect()))
    <details class="route-ground-visual" id="visual-trunk-{{ $trunk->id }}" @if($loop->first) open @endif>
        <summary><span class="route-inline-icon" aria-hidden="true">@include('operations::route-truck-icon')</span>{{ $trunk->name }}<small>{{ $visualLegs->count() }} tramos</small></summary>
        @if($canEdit && $mapsConfigured)
        <form class="route-gap-action" method="post" action="{{ route('operations.routes.stops.calculate', $trunk->id) }}">@csrf<button type="submit" class="route-map-button">Calcular km entre paradas</button></form>
        @endif
        @if($canEdit && count($groundPairs[$trunk->id] ?? []))
        <details class="route-gap-editor">
            <summary>Ingresar kilómetros entre paradas</summary>
            <form method="post" action="{{ route('operations.routes.stops.save', $trunk->id) }}">
                @csrf @method('PUT')
                @foreach($groundPairs[$trunk->id] as $pair)
                    <label>{{ ['troncal' => 'Troncal', 'posta1' => 'Posta 1', 'posta2' => 'Posta 2', 'posta3' => 'Posta 3'][$pair['role']] }} · {{ $pair['from_label'] }} → {{ $pair['to_label'] }}
                        <input type="text" inputmode="decimal" name="distances[{{ $pair['key'] }}]" placeholder="km (ej. 18,6)" value="{{ $groundDistanceValues[$trunk->id][$pair['key']] ?? '' }}">
                    </label>
                @endforeach
                <button type="submit">Guardar kilómetros</button>
            </form>
        </details>
        @endif
        @forelse($visualLegs->groupBy(fn($item) => $item['segment']['key']) as $role => $stops)
        <div class="route-ground-lane">
            <h3>{{ ['troncal' => 'Troncal', 'posta1' => 'Posta 1', 'posta2' => 'Posta 2', 'posta3' => 'Posta 3'][$role] ?? $role }}</h3>
            <div class="route-ground-track">
                @foreach($stops as $stop)
                <div class="route-ground-stop">
                    @include('operations::route-visual-node', ['segment' => $stop['segment'], 'agencyId' => $stop['agencyId'], 'nodeLabel' => $stop['label'], 'nodeHint' => $stop['segment']['name'], 'routeLabel' => $stop['segment']['role'].' · '.$stop['segment']['name'], 'agencyNames' => $stop['agencies']])
                    @if(! $loop->last)
                        @php($nextStop = $stops->values()[$loop->index + 1])
                        @php($gapKey = 'gap:'.$role.':'.$stop['agencyId'].':'.$nextStop['agencyId'])
                        @php($gapDistance = $groundDistances[$trunk->id][$gapKey] ?? null)
                        <span class="route-gap-distance {{ $gapDistance ? '' : 'missing' }}" title="Distancia {{ $stop['label'] }} → {{ $nextStop['label'] }}">{{ $gapDistance ?? 'km pendiente' }}</span>
                    @endif
                </div>
                @endforeach
            </div>
        </div>
        @empty
        <p class="route-empty">Esta troncal no tiene agencias vinculadas.</p>
        @endforelse
    </details>
    @endforeach
</section>
<dialog class="route-visual-dialog" id="route-visual-dialog" aria-labelledby="route-visual-dialog-title">
    <div class="route-visual-dialog-header"><h2 id="route-visual-dialog-title">Datos del recorrido</h2><button type="button" class="route-visual-dialog-close" data-route-close aria-label="Cerrar ventana">×</button></div>
    <dl class="route-visual-details">
        <div><dt>Ruta</dt><dd data-route-detail="title"></dd></div>
        <div><dt>Nombre chofer</dt><dd data-route-detail="driver"></dd></div>
        <div><dt>RUT chofer</dt><dd data-route-detail="rut"></dd></div>
        <div><dt>Patente asignada</dt><dd data-route-detail="plate"></dd></div>
        <div><dt>Dirección origen</dt><dd data-route-detail="origin"></dd></div>
        <div><dt>Dirección destino</dt><dd data-route-detail="destination"></dd></div>
        <div data-route-agencies-row><dt>Agencia(s)</dt><dd data-route-detail="agencies"></dd></div>
    </dl>
    @if($canEdit)
    <form class="route-visual-dialog-form" id="route-visual-edit-form" method="post">
        @csrf
        @method('PUT')
        <h3>Modificar recorrido</h3>
        <input type="hidden" name="route_agency_id" data-route-input="route_agency_id">
        @if(old('route_agency_id') && $errors->any())
        <div class="error route-form-wide" data-route-edit-errors role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
        @endif
        <input type="hidden" name="segment" data-route-input="segment">
        <label class="route-form-wide">Aplicar el cambio
            <select name="scope" data-route-input="scope" required>
                <option value="permanent">Mantener para las próximas salidas</option>
                <option value="departure">Solo una salida programada</option>
            </select>
        </label>
        <label class="route-form-wide" data-route-departure-row hidden>Salida concreta
            <select name="departure_id" data-route-input="departure_id"></select>
        </label>
        <label class="route-form-wide" data-route-air-scope-row hidden>Troncales aéreas
            <select name="air_route_scope" data-route-input="air_route_scope">
                <option value="only">Cambiar solo este aéreo</option>
                <option value="all">Extender patente y chofer a Norte, Pacífico y Sur</option>
            </select>
        </label>
        <p class="route-form-wide" data-route-scope-note>Los cambios permanentes se guardan en el catálogo. Las salidas ya programadas conservan sus datos.</p>
        <label data-route-transport-row>Patente<input name="plate" data-route-input="plate" maxlength="12"></label>
        <label data-route-transport-row>Nombre chofer<input name="driver_name" data-route-input="driver_name" maxlength="160"></label>
        <label data-route-transport-row>RUT chofer<input name="driver_rut" data-route-input="driver_rut" maxlength="15"></label>
        <label>Dirección destino<input name="destination_address" data-route-input="destination_address" maxlength="255" required></label>
        <p class="route-form-wide" data-route-commune-note></p>
        <button type="submit">Guardar cambio</button>
    </form>
    @endif
</dialog>
@if($sharedAirRoute)
<details class="route-trunk route-air-hub" id="trunk-air" open>
    <summary><span class="route-icon" aria-hidden="true">✈</span><span class="route-trunk-title"><strong>Aéreos · un tramo común</strong><small>{{ $sharedAirSegment['origin'] }} → {{ $sharedAirSegment['destination'] }}</small></span><span class="route-count">{{ $airRoutes->count() }} destinos</span></summary>
    <div class="route-common-leg">
        <h2><span class="route-inline-icon" aria-hidden="true">@include('operations::route-truck-icon')</span>Tramo común · Bodega → Aeropuerto</h2>
        <p>Sale una sola vez de la bodega al aeropuerto de Santiago. Desde allí se abren las postas de cada destino.</p>
        <div class="route-locations"><span><small>Origen · Bodega 17</small><br>{{ $sharedAirSegment['origin'] }}</span><b aria-hidden="true">→</b><span><small>Destino · Aeropuerto Arturo Merino Benítez, Carga Nacional</small><br>{{ $sharedAirSegment['destination'] }}</span></div>
        <p class="route-data">Patente: {{ $sharedAirRoute['agency']->trunk_plate ?: 'Pendiente' }} · Chofer: {{ $sharedAirRoute['agency']->trunk_driver_name ?: 'Pendiente' }} · RUT: {{ $sharedAirRoute['agency']->trunk_driver_rut ?: 'Pendiente' }}</p>
        @include('operations::route-estimate', ['segment' => $sharedAirSegment, 'agency' => $sharedAirRoute['agency'], 'canEdit' => $canEdit, 'mapsConfigured' => $mapsConfigured])
    </div>
    <h2 class="route-air-branches-title">Postas por destino desde el aeropuerto</h2>
    <div class="route-agencies">
    @foreach($airRoutes as $route)
        @include('operations::route-agency', ['route' => $route, 'isAirBranch' => true, 'openByDefault' => $loop->first])
    @endforeach
    </div>
</details>
@endif
@foreach($groundTrunks as $trunk)
@php($trunkRoutes = $routes->get($trunk->id, collect()))
<details class="route-trunk" id="trunk-{{ $trunk->id }}" @if(!$sharedAirRoute && $loop->first) open @endif>
    <summary><span class="route-icon" aria-hidden="true">@include('operations::route-truck-icon')</span><span class="route-trunk-title"><strong>{{ $trunk->name }}</strong><small>{{ $trunk->origin_address }}, {{ $trunk->origin_commune }} → {{ $trunk->destination_address }}, {{ $trunk->destination_commune }}</small></span><span class="route-count">{{ $trunkRoutes->count() }} {{ $trunkRoutes->count() === 1 ? 'agencia' : 'agencias' }}</span></summary>
    @if($trunkRoutes->isEmpty())<p class="route-empty">Esta troncal no tiene agencias vinculadas.</p>@else
    <div class="route-agencies">
    @foreach($trunkRoutes as $route)
        @include('operations::route-agency', ['route' => $route, 'isAirBranch' => false, 'openByDefault' => !$sharedAirRoute && $loop->first && $loop->parent->first])
    @endforeach
    </div>
    @endif
</details>
@endforeach
<script>
function openRouteTarget(){const target=document.getElementById(location.hash.slice(1));if(!target)return;let parent=target;while(parent){if(parent.tagName==='DETAILS')parent.open=true;parent=parent.parentElement}target.scrollIntoView()}
document.addEventListener('DOMContentLoaded',openRouteTarget);
window.addEventListener('hashchange',openRouteTarget);
const routeDialog=document.getElementById('route-visual-dialog');
const routeDrafts=@json($draftDepartures);
const routeForm=document.getElementById('route-visual-edit-form');
let currentRouteNode=null;
function routeDraftOptions(node){return routeDrafts.filter(departure=>departure.role===node.dataset.routeSegment && (node.dataset.routeGroup ? departure.group_code===node.dataset.routeGroup : !departure.group_code && String(departure.agency_id)===node.dataset.routeAgencyId)).filter((departure,index,all)=>all.findIndex(candidate=>candidate.id===departure.id)===index)}
function refreshRouteForm(){
    if(!routeForm||!currentRouteNode)return;
    const scope=routeForm.querySelector('[data-route-input="scope"]').value;
    const departureSelect=routeForm.querySelector('[data-route-input="departure_id"]');
    const selected=routeDraftOptions(currentRouteNode).find(departure=>String(departure.id)===departureSelect.value);
    const temporary=scope==='departure';
    routeForm.querySelector('[data-route-departure-row]').hidden=!temporary;
    routeForm.querySelector('[data-route-air-scope-row]').hidden=temporary||currentRouteNode.dataset.routeAirTrunk!=='1';
    departureSelect.required=temporary;
    routeForm.querySelector('[data-route-scope-note]').textContent=temporary
        ? 'Solo se modifica la salida elegida. Si es una troncal terrestre, sus Posta 1 relacionadas reciben la patente y el chofer; Posta 2 conserva los suyos.'
        : currentRouteNode.dataset.routeInherited==='1'
            ? 'Patente y chofer se guardan en esta Posta 1 y se comparten con sus paradas. La troncal y las otras postas conservan sus datos. La dirección de destino se guarda solo para esta agencia. Las salidas ya programadas conservan sus datos.'
            : 'Los cambios permanentes se guardan en el catálogo. Las salidas ya programadas conservan sus datos.';
    routeForm.querySelectorAll('[data-route-transport-row] input').forEach(input=>{input.readOnly=currentRouteNode.dataset.routeInherited==='1'&&temporary});
    const values=temporary&&selected?{plate:selected.plate,driver_name:selected.driver_name,driver_rut:selected.driver_rut,destination_address:selected.destination_address}:{plate:currentRouteNode.dataset.routePlate,driver_name:currentRouteNode.dataset.routeDriver,driver_rut:currentRouteNode.dataset.routeRut,destination_address:currentRouteNode.dataset.routeAddress};
    for(const field of ['plate','driver_name','driver_rut','destination_address'])routeForm.querySelector(`[data-route-input="${field}"]`).value=values[field]==='No aplica'?'':values[field]||'';
}
document.querySelectorAll('[data-route-node]').forEach(node=>node.addEventListener('click',()=>{
    routeDialog.querySelector('#route-visual-dialog-title').textContent='Datos del recorrido · '+node.dataset.routeStop;
    for(const field of ['title','driver','rut','plate','origin','destination','agencies']){
        routeDialog.querySelector(`[data-route-detail="${field}"]`).textContent=node.dataset[`route${field[0].toUpperCase()}${field.slice(1)}`]||'No aplica';
    }
    routeDialog.querySelector('[data-route-agencies-row]').hidden=!node.dataset.routeAgencies;
    if(routeForm){
        currentRouteNode=node;
        const routeErrors=routeForm.querySelector('[data-route-edit-errors]');
        if(routeErrors)routeErrors.hidden=true;
        routeForm.action=node.dataset.routeEditUrl;
        routeForm.querySelector('[data-route-input="route_agency_id"]').value=node.dataset.routeAgencyId;
        routeForm.querySelector('[data-route-input="segment"]').value=node.dataset.routeSegment;
        const flight=node.dataset.routeSegment==='vuelo';
        const options=routeDraftOptions(node);
        const scopeSelect=routeForm.querySelector('[data-route-input="scope"]');
        scopeSelect.value='permanent';
        routeForm.querySelector('[data-route-input="air_route_scope"]').value='only';
        scopeSelect.querySelector('option[value="departure"]').disabled=flight||options.length===0;
        const departureSelect=routeForm.querySelector('[data-route-input="departure_id"]');
        departureSelect.replaceChildren(new Option('Selecciona una salida', ''));
        for(const departure of options)departureSelect.add(new Option(`#${departure.id} · ${departure.departure_date} · ${departure.name}`,departure.id));
        routeForm.querySelectorAll('[data-route-transport-row]').forEach(row=>{row.hidden=flight;row.querySelector('input').disabled=flight});
        routeForm.querySelector('[data-route-commune-note]').textContent=flight?'En este punto se edita la dirección de origen de la posta aérea.':node.dataset.routeInherited==='1'?'La dirección corresponde a esta agencia. Patente y chofer de esta posta se comparten con sus otras paradas, si las tiene.':options.length?'Selecciona «Solo una salida programada» para modificar una salida pendiente concreta.':'No hay salidas pendientes para este tramo; puedes cambiar el catálogo para futuras salidas.';
        refreshRouteForm();
    }
    routeDialog.showModal();
}));
if(routeForm){routeForm.querySelector('[data-route-input="scope"]').addEventListener('change',refreshRouteForm);routeForm.querySelector('[data-route-input="departure_id"]').addEventListener('change',refreshRouteForm)}
@if(old('route_agency_id') && old('segment') && $errors->any())
const failedRouteNode=Array.from(document.querySelectorAll('[data-route-node]')).find(node=>node.dataset.routeAgencyId===@json((string) old('route_agency_id'))&&node.dataset.routeSegment===@json(old('segment')));
if(failedRouteNode){
    failedRouteNode.click();
    routeForm.querySelector('[data-route-input="scope"]').value=@json(old('scope', 'permanent'));
    routeForm.querySelector('[data-route-input="departure_id"]').value=@json((string) old('departure_id', ''));
    refreshRouteForm();
    const attempted=@json(old());
    for(const field of ['plate','driver_name','driver_rut','destination_address']){
        if(Object.hasOwn(attempted,field))routeForm.querySelector(`[data-route-input="${field}"]`).value=attempted[field]??'';
    }
    const routeErrors=routeForm.querySelector('[data-route-edit-errors]');
    if(routeErrors)routeErrors.hidden=false;
}
@endif
routeDialog.querySelector('[data-route-close]').addEventListener('click',()=>routeDialog.close());
routeDialog.addEventListener('click',event=>{if(event.target===routeDialog)routeDialog.close()});
</script>
@endsection
