@extends('layout')
@section('title', 'Savollar va xatolar')
@section('description', 'Monitoringdagi noaniqlik, xato va tafovutlar — dalil, muhokama va yechim tarixi bilan.')
@section('content')
<div class="status-strip"><strong>{{ $openCount }} ta ochiq yozuv</strong><span>{{ $waitingCount }} ta birga aniqlashtiriladi</span><a href="#new-issue">+ Savol yoki xato yozish</a></div>
<section class="card"><div class="card-heading"><h2>Monitoring jurnali</h2><span class="badge">{{ $issues->total() }} YOZUV</span></div>
<form method="get" class="form-row">
<label>Holat<select name="status"><option value="unresolved" @selected($status === 'unresolved')>Barcha ochiq</option><option value="all" @selected($status === 'all')>Hammasi</option>@foreach(\App\Models\MonitoringIssue::STATUSES as $key => $label)<option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>@endforeach</select></label>
<label>Tur<select name="kind"><option value="">Hammasi</option>@foreach(\App\Models\MonitoringIssue::KINDS as $key => $label)<option value="{{ $key }}" @selected(($filters['kind'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
<label>Kanal<select name="channel_id"><option value="">Barcha kanallar</option>@foreach($channels as $channel)<option value="{{ $channel->id }}" @selected(($filters['channel_id'] ?? '') == $channel->id)>{{ $channel->title }}</option>@endforeach</select></label>
<label>Qidirish<input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ID, sarlavha yoki kod" maxlength="100"></label><button class="button secondary">Ko‘rsatish</button>
</form><div class="table-wrap"><table><thead><tr><th>Yozuv</th><th>Kanal</th><th>Tur / holat</th><th>Kuzatuv</th></tr></thead><tbody>
@forelse($issues as $issue)<tr><td class="message-preview"><a href="{{ route('issues.show', $issue) }}">#{{ $issue->id }} · {{ $issue->title }}</a><small>{{ $issue->code }}</small></td><td>{{ $issue->channel?->title ?? 'Platforma' }}</td><td>{{ \App\Models\MonitoringIssue::KINDS[$issue->kind] }}<small>{{ \App\Models\MonitoringIssue::STATUSES[$issue->status] }}</small></td><td>{{ $issue->occurrence_count }} holat<small>{{ $issue->last_seen_at->timezone('Asia/Tashkent')->format('d.m.Y H:i') }} UZT</small></td></tr>
@empty<tr><td class="empty-cell" colspan="4">Bu filtr bo‘yicha yozuv yo‘q. Monitoring ishlaganda aniqlangan holatlar shu yerga tushadi.</td></tr>@endforelse
</tbody></table></div>{{ $issues->links() }}
<p class="help">Takroriy tekshiruv yangi holat hisoblanmaydi. Yopilgan muammo haqiqatan qaytalansa, shu yozuv qayta ochiladi. Jurnaldagi qaror savdo sozlamalarini avtomatik o‘zgartirmaydi.</p></section>
<section class="card" id="new-issue"><div class="card-heading"><h2>Savol yoki xato qo‘shish</h2></div><form method="post" action="{{ route('issues.store') }}">@csrf
<div class="form-grid"><label>Sarlavha<input name="title" required maxlength="200" value="{{ old('title') }}"></label><label>Tur<select name="kind">@foreach(\App\Models\MonitoringIssue::KINDS as $key => $label)<option value="{{ $key }}" @selected(old('kind', 'QUESTION') === $key)>{{ $label }}</option>@endforeach</select></label>
<label>Kanal<select name="channel_id"><option value="">Platforma / signal bo‘yicha</option>@foreach($channels as $channel)<option value="{{ $channel->id }}" @selected(old('channel_id') == $channel->id)>{{ $channel->title }}</option>@endforeach</select></label><label>Signal ID · ixtiyoriy<input name="signal_id" type="number" min="1" value="{{ old('signal_id') }}"></label></div>
<label>Nima kuzatildi?<textarea name="description" required rows="3" maxlength="4000">{{ old('description') }}</textarea></label><label>Birga hal qiladigan savol · ixtiyoriy<textarea name="question" rows="2" maxlength="1000">{{ old('question') }}</textarea></label><button class="button">Jurnalga saqlash</button></form></section>
@endsection
