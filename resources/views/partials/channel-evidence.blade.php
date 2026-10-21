<section class="card">
    <div class="card-heading"><div><h2>Kanalning signal tarixi</h2><p>{{ $evidence['counts']['observed_messages'] }} ta kuzatilgan xabar · takroriy yetkazishlar bir marta hisoblanadi</p></div></div>
    <div class="metrics-grid compact">
        @foreach(['complete_signals' => 'To‘liq matnli signal', 'incomplete_signals' => 'SL/TP yetishmagan', 'edited_signal_messages' => 'Tahrirlangan signal', 'deleted_signal_messages' => 'O‘chirilgan signal'] as $key => $label)
            <div class="metric"><div>{{ $label }}</div><strong>{{ $evidence['counts'][$key] }}</strong></div>
        @endforeach
    </div>
    <p class="help">Birinchi kuzatilgan matn asosida hisoblanadi. Rasm ichidagi signallar hali o‘qilmaydi. Kanal SL/TP bermasa, agent shu kanal uchun tarixdan o‘rganilgan darajalar va foydani himoyalash siyosatini ishlatadi. Yetarli tarix yig‘ilmaguncha bu signal o‘rganish kuzatuvi sifatida saqlanadi.</p>
    @if($evidence['counts']['save_needs_policy'])<div class="notice">{{ $evidence['counts']['save_needs_policy'] }} ta “save” xabarida yopiladigan ulush aniqlashtirilishi kerak.</div>@endif
    @if($evidence['incomplete'])
        <h3>SL/TP yetishmagan so‘nggi signallar</h3>
        <div class="table-wrap"><table><thead><tr><th>Message ID</th><th>Signal</th><th>Entry</th><th>Yetishmagan maydon</th><th>Holat</th></tr></thead><tbody>
        @foreach(array_reverse($evidence['incomplete']) as $item)
            <tr><td>{{ $item['message_id'] }}</td><td>{{ $item['candidate']['symbol'] }} {{ $item['candidate']['direction'] }}</td><td>{{ $item['candidate']['entry_min'] }}–{{ $item['candidate']['entry_max'] }}</td><td>{{ implode(', ', $item['missing_fields']) }}</td><td>{{ $item['deleted'] ? 'O‘chirilgan' : 'Kuzatuv' }}</td></tr>
        @endforeach
        </tbody></table></div>
    @endif
    <h3>Kanalning natija da’volari · {{ $evidence['counts']['result_reports'] }}</h3>
    <p class="help">“150 pips” yoki “TP hit” yozuvi tekshirilmagan da’vo sifatida saqlanadi. Bu raqamlar paper P/L yoki tasdiqlangan win rate’ga qo‘shilmaydi.</p>
    <div class="table-wrap"><table><thead><tr><th>Message ID / Reply</th><th>Original matn</th><th>Aytilgan natija</th><th>Tasdiq</th></tr></thead><tbody>
    @forelse(array_reverse($evidence['reports']) as $report)
        <tr><td>{{ $report['message_id'] }} / {{ $report['reply_to'] ?? '—' }}</td><td class="message-preview">{{ \Illuminate\Support\Str::limit($report['text'], 200) }}</td><td>@foreach($report['claims'] as $claim)<span>{{ $claim['value'] }} {{ $claim['unit'] }}</span> @endforeach</td><td>
            @forelse($report['checks'] as $check)
                <div><span class="badge neutral">{{ ['OBSERVED_SHORTFALL' => 'KUZATILGAN HARAKAT KAMROQ', 'OBSERVED_REACHED' => 'NARX HARAKATIDA KUZATILDI', 'DATA_GAP' => 'TARIXDA BO‘SHLIQ', 'UNITS_UNKNOWN' => 'BIRLIK ANIQLANMAGAN', 'NO_OBSERVED_ENTRY' => 'ENTRY KUZATILMAGAN'][$check->status] ?? $check->status }}</span><small>Aytilgan: {{ $check->claimed_pips ?? '—' }} · Kuzatilgan: {{ $check->observed_pips ?? '—' }} · Farq: {{ $check->shortfall_pips ?? '—' }} pips</small><details><summary>Dalillar</summary><pre>{{ json_encode(json_decode($check->evidence, true), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details></div>
            @empty<span class="badge neutral">TEKSHIRILMAGAN</span>@endforelse
        </td></tr>
    @empty<tr><td colspan="4" class="empty-cell">Natija da’volari kuzatilmagan.</td></tr>@endforelse
    </tbody></table></div>
    <h3>So‘nggi tahrir va o‘chirish hodisalari</h3>
    <div class="table-wrap"><table><thead><tr><th>Message ID</th><th>Hodisa</th><th>Vaqt (UZT)</th><th>Yangi matn</th></tr></thead><tbody>
    @forelse($evidence['revisions'] as $revision)
        <tr><td>{{ $revision->telegram_message_id }}</td><td><span class="badge neutral">{{ strtoupper($revision->kind) }}</span></td><td>{{ $revision->occurred_at->timezone('Asia/Tashkent')->format('d.m H:i:s') }}</td><td class="message-preview">{{ \Illuminate\Support\Str::limit($revision->payload['text'] ?? '', 180) }}</td></tr>
    @empty<tr><td colspan="4" class="empty-cell">Tahrir yoki o‘chirish hodisasi kuzatilmagan.</td></tr>@endforelse
    </tbody></table></div>
</section>
