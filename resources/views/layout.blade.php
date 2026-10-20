<!DOCTYPE html>
<html lang="uz">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('title', 'Boshqaruv') · Signal Forge</title><link rel="stylesheet" href="{{ asset('platform.css') }}"></head>
<body>
<aside class="sidebar"><a class="brand" href="{{ route('dashboard') }}"><span class="brand-mark">S<span>F</span></span><span>Signal Forge<small>TRADING INTELLIGENCE</small></span></a>
<div class="workspace-label">ISH MAYDONI</div>
<nav>@foreach(['dashboard' => ['◈', 'Umumiy ko‘rinish'], 'channels' => ['◉', 'Kanallar'], 'signals' => ['↗', 'Signallar'], 'agents' => ['◇', 'Agentlar'], 'trades' => ['⇄', 'Pozitsiyalar'], 'backtests' => ['▥', 'Backtest'], 'risk' => ['⊞', 'Risk nazorati'], 'lab' => ['⚗', 'Laboratoriya'], 'integrations' => ['⌁', 'Ulanishlar'], 'audit' => ['≡', 'Audit tarixi']] as $route => [$icon, $label])<a href="{{ route($route) }}" class="{{ request()->routeIs($route, $route.'.*') ? 'active' : '' }}"><span>{{ $icon }}</span>{{ $label }}</a>@endforeach</nav>
<div class="sidebar-bottom"><div class="mode-card"><span class="dot"></span> PAPER MUHIT<small>Virtual kapital · USD</small></div><div class="user-row"><span class="avatar">{{ substr(auth()->user()->name, 0, 1) }}</span><div>{{ auth()->user()->name }}<small>Platforma administratori</small></div><form method="post" action="{{ route('logout') }}">@csrf<button class="logout" aria-label="Chiqish">↪</button></form></div></div></aside>
<div class="app-shell"><header class="topbar"><span>Workspace <span class="slash">/</span> <strong>@yield('title', 'Boshqaruv')</strong></span><span class="top-meta">{{ now('Asia/Tashkent')->format('d M Y · H:i') }} UZT <span class="badge">PAPER</span></span></header>
<main><div class="page-heading"><div><div class="eyebrow">ADAPTIVE SIGNAL INTELLIGENCE</div><h1>@yield('title', 'Boshqaruv')</h1><p>@yield('description', 'Signal, qaror va natija — bitta nazorat markazida.')</p></div>@yield('actions')</div>
@if(session('success'))<div class="notice success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="notice error" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
@yield('content')</main><footer>Signal Forge <span>Research & execution platform · Paper v1</span></footer></div></body></html>
