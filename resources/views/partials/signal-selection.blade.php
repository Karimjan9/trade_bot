@php($selection = $signal->decisions->first()?->context['selection'] ?? [])
@if($selection)
<section class="card">
    <div class="card-heading"><h2>Bir instrument uchun umumiy tanlov</h2><span class="badge">{{ $selection['symbol'] }}</span></div>
    @if(isset($selection['held_trade']))
        <p>Hozir {{ $selection['held_trade']['direction'] }} pozitsiya #{{ $selection['held_trade']['id'] }} ochiq. Qarama-qarshi signal sababli bu pozitsiya avtomatik yopilmaydi yoki almashtirilmaydi.</p>
    @elseif($selection['selected_signal_id'])
        <p>Tanlangan: <a href="{{ route('signals.show', $selection['selected_signal_id']) }}">signal #{{ $selection['selected_signal_id'] }}</a>. Boshqa kanallar mustaqil qarama-qarshi pozitsiya ochmaydi.</p>
    @else<p>Hozircha yo‘nalish tanlanmadi. Qaror sababi quyida saqlangan.</p>@endif
    @if($selection['candidates'])<div class="table-wrap"><table><thead><tr><th>Signal</th><th>Yo‘nalish</th><th>ML kutiladigan net R</th><th>Qarshi yurish bahosi</th><th>Ushlab turish</th><th>Taqqoslash bahosi</th></tr></thead><tbody>
        @foreach($selection['candidates'] as $candidate)<tr><td><a href="{{ route('signals.show', $candidate['signal_id']) }}">#{{ $candidate['signal_id'] }}</a></td><td>{{ $candidate['direction'] }}</td>@if($candidate['comparable'])<td>{{ number_format($candidate['expected_net_r'], 2) }} R</td><td>{{ number_format($candidate['adverse_r'], 2) }} R</td><td>{{ round($candidate['holding_seconds'] / 60, 1) }} min</td><td>{{ number_format($candidate['utility_r'], 2) }} R<small>{{ $candidate['risk_time_source'] === 'conservative_plan_fallback' ? 'Tarix kam: ehtiyotkor reja bahosi' : 'Oldingi narx ijrosidan' }}</small></td>@else<td colspan="4">Taqqoslash uchun tasdiqlangan ML bahosi yo‘q.</td>@endif</tr>@endforeach
    </tbody></table></div>@endif
    <p class="help">Kanal soni ovoz sifatida ishlatilmaydi. Kutiladigan net natija qarshi yurish va band bo‘ladigan vaqt bilan birga baholanadi. Yo‘nalishlar orasidagi ustunlik kichik bo‘lsa tizim kutadi.</p>
</section>
@endif
@if($observation)
<section class="card">
    <div class="card-heading"><h2>Signalning narx yo‘li</h2><span class="badge">{{ $observation->path_state }}</span></div>
    @if($observation->path_metrics['entry_observed'] ?? false)
        <div class="health-item"><span>Kuzatilgan eng katta foydali yurish</span><strong>{{ number_format($observation->path_metrics['observed_favourable_pips'], 1) }} pips</strong></div>
        <div class="health-item"><span>Kuzatilgan eng katta qarshi yurish</span><strong>{{ number_format($observation->path_metrics['observed_adverse_pips'], 1) }} pips</strong></div>
        <div class="table-wrap"><table><thead><tr><th>Daraja</th><th>Qancha vaqtda yetdi</th><th>Ungacha qarshi yurish</th><th>Dastlabki SL</th></tr></thead><tbody>
            @forelse(($observation->path_metrics['milestones'] ?? []) as $pips => $milestone)<tr><td>+{{ $pips }} pips</td><td>{{ round($milestone['elapsed_seconds'] / 60, 1) }} min</td><td>{{ number_format($milestone['adverse_before_pips'], 1) }} pips</td><td>{{ $milestone['initial_stop_already_hit'] ? 'Bu foydadan OLDIN urilgan' : 'Oldin urilmagan' }}</td></tr>@empty<tr><td colspan="4">Hali belgilangan foyda bosqichi kuzatilmagan.</td></tr>@endforelse
        </tbody></table></div>
        @if($observation->path_metrics['initial_stop_hit_at'])<p>Dastlabki SL urilgan vaqt: {{ \Carbon\CarbonImmutable::parse($observation->path_metrics['initial_stop_hit_at'])->timezone('Asia/Tashkent')->format('d.m H:i:s') }}.</p>@endif
        <p class="help">Narx yo‘li {{ round($observation->path_metrics['horizon_seconds'] / 60) }} minut kuzatiladi, trade yopilgandan keyingi harakat ham qayd etiladi. Bu yopilgan foyda emas. SL’dan keyingi ko‘tarilish ishlagan signal deb hisoblanmaydi. Narx qamrovi: {{ $observation->path_metrics['covered'] ? 'kuzatilgan davrda yetarli' : 'uzilish bor' }}.</p>
    @else<p class="muted-text">Kirish narxi yoki narx yo‘li hali aniqlanmagan. Kuzatuvlar ML navbatida yangilanadi.</p>@endif
</section>
@endif
