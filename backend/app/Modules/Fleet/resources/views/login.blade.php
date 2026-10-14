<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ingresar · Control de Flota</title>
<link rel="icon" href="{{ asset('images/logo4n.png') }}" type="image/png">
<style>
*{box-sizing:border-box}body{font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Arial,sans-serif;background:#f4f4f5;color:#202428;min-height:100dvh;display:grid;place-items:center;margin:0;padding:24px}.login-card{background:#fcfcfd;border:1px solid #e2e3e5;border-radius:16px;padding:38px 42px;width:min(540px,100%)}.login-brand{text-align:center;margin-bottom:20px}.login-brand img{width:88px;height:auto}h1{font-size:24px;text-align:center;letter-spacing:-.02em;margin:0 0 10px;font-weight:700}.login-description{text-align:center;color:#7b8085;font-size:13px;margin:0 0 26px}label{display:block;margin:18px 0 7px;font-size:14px;font-weight:500}input{width:100%;padding:12px 13px;border:1px solid #d9dce0;border-radius:9px;background:#fff;color:#262c31;font:inherit;font-size:14px;min-height:44px}input:focus-visible{outline:2px solid #00898d;outline-offset:1px;background:#f5fafa}.login-submit{width:100%;background:#00898d;color:#fff;border:1px solid #00898d;border-radius:8px;padding:13px;margin-top:26px;font:inherit;font-size:14px;font-weight:600;cursor:pointer}.login-submit:hover{background:#00767a}.login-submit:focus-visible,a:focus-visible{outline:2px solid #00898d;outline-offset:3px}.error{color:#a03325;font-size:13px;background:#fff1ee;padding:10px;border-radius:7px}a{color:#008185}.login-return{text-align:center;font-size:12px;margin:20px 0 0}@media(max-width:500px){body{padding:16px}.login-card{padding:28px 24px}h1{font-size:22px}}
</style>
</head>
<body><main class="login-card">
<div class="login-brand"><img src="{{ asset('images/logo4n.png') }}" alt="4N Logística" width="88" height="43"></div>
<h1>¡Bienvenido de nuevo!</h1><p class="login-description">Control de Flota 4N · Ingresa con tu cuenta de LogisticaCL.</p>
@if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
<form method="post" action="{{ route('fleet.login.submit') }}">@csrf
<label for="email">Correo electrónico</label><input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
<label for="password">Contraseña</label><input id="password" type="password" name="password" required autocomplete="current-password">
<button class="login-submit" type="submit">Entrar</button>
</form><p class="login-return"><a href="{{ route('provider-payments.dashboard') }}">Volver a Pago Proveedores</a></p>
</main></body></html>
