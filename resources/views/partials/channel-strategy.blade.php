@php
    $tradeLabels = ['PAPER_BASELINE' => 'Boshlang‘ich paper kuzatuvi', 'PERSONALIZED' => 'Kanalga mos usul tanlangan', 'SELECTIVE' => 'Ayrim yo‘nalishlar cheklangan', 'MONITOR_ONLY' => 'Faqat monitoring', 'EXCLUDED' => 'Foydasiz usullar aniqlangan — savdo bloklangan'];
    $strategyLabels = ['provider_first' => 'Birinchi TP', 'provider_last' => 'Oxirgi TP', 'tp_50' => '50 pipsda yopish', 'tp_100' => '100 pipsda yopish', 'lock_50' => '50 pips qadamli SL', 'save_50' => '50 pipsda yarmini yopish + himoya', 'time_5m' => '5 minutda chiqish'];
    $researchLabels = ['INSUFFICIENT_DATA' => 'Tarix yetarli emas', 'LEARNING_DISABLED' => 'O‘qitish o‘chirilgan', 'PAPER_ELIGIBLE' => 'Paper uchun tekshirilgan', 'SHADOW_ONLY' => 'Usul sinovdan o‘tmagan', 'UNPROFITABLE' => 'Sinalgan usullar zararli', 'RECOVERY_SHADOW' => 'Qaytish uchun yangi mustaqil sinov kerak'];
@endphp
<section class="card">
    <div class="card-heading"><h2>Kanalga individual yondashuv</h2><span class="badge">{{ $tradeLabels[$channel->trade_state] ?? $channel->trade_state }}</span></div>
    <p>Har kanal uchun zonaga kirish, kutish, qismlarga bo‘lish, SL, chiqish va foydani himoyalash usullari alohida solishtiriladi. Qarshi yurish hamda natijaga ketgan vaqt hisobga olinadi.</p>
    <div class="health-item"><span>Monitoring</span><strong>{{ $channel->enabled ? 'Davom etmoqda' : 'O‘chirilgan' }}</strong></div>
    <div class="health-item"><span>Video siyosati</span><strong>Ochilmaydi · yuklab olinmaydi</strong></div>
    <p class="help">Video ichidagi ma’lumot ishlatilmaydi. Yozma caption bo‘lsa, faqat o‘sha matn tahlil qilinadi. Kuzatilgan video postlari: {{ $evidence['counts']['video_posts_skipped'] }}.</p>
    @forelse($strategyReports as $strategy)
        <div class="agent-mini">
            <h3>{{ $strategy->symbol }} {{ $strategy->direction }} <span class="badge">{{ $researchLabels[$strategy->status] ?? $strategy->status }}</span></h3>
            @if($strategy->parameters)<p>Usul: <strong>{{ app(\App\Services\Trading\EntryPlans::class)->label($strategy->parameters['key']) }}</strong> · Maksimal vaqt: {{ round($strategy->parameters['horizon_seconds'] / 60, 1) }} min. · Belgilangan risk limitidan: {{ round(($strategy->parameters['risk_multiplier'] ?? 1) * 100) }}%.</p>@endif
            <p class="help">O‘qitish / sozlash / keyingi sinov: {{ $strategy->report['train_count'] }} / {{ $strategy->report['validation_count'] }} / {{ $strategy->report['test_count'] }}. Yetishmagan narxlar: {{ $strategy->report['omitted'] }}. @if($strategy->expires_at)Dalil muddati: {{ $strategy->expires_at->timezone('Asia/Tashkent')->format('d.m.Y H:i') }}.@endif</p>
            @if($strategy->report['variants'])<details><summary>Usullar natijalarini solishtirish</summary><div class="table-wrap"><table><thead><tr><th>Usul</th><th>O‘qitish, R</th><th>Sozlash, R</th><th>Keyingi sinov, R</th><th>Sinovdagi pasayish, R</th></tr></thead><tbody>
                @foreach($strategy->report['variants'] as $key => $stats)<tr><td>{{ app(\App\Services\Trading\EntryPlans::class)->label($key) }}</td><td>{{ number_format($stats['train']['expectancy_r'], 3) }}</td><td>{{ number_format($stats['validation']['expectancy_r'], 3) }}</td><td>{{ number_format($stats['test']['expectancy_r'], 3) }}</td><td>{{ number_format($stats['test']['max_drawdown_r'], 2) }}</td></tr>@endforeach
            </tbody></table></div></details>@endif
        </div>
    @empty<p class="muted-text">Hali individual usul uchun yetarli dalil yo‘q. Natijalarni tekshirish va modelni o‘qitish tugmasi tadqiqotni ham navbatga qo‘shadi.</p>@endforelse
    <p class="help">Usul eski davrdan tanlanib, keyingi alohida davrda tekshiriladi. Savdodan chiqarilgan kanalning original xabarlari va natijalari o‘chirilmaydi. “Zararli” xulosasi sinalgan usullar va kuzatilgan davrga tegishli; foydaning mutlaq maksimumi deb ko‘rsatilmaydi.</p>
</section>
