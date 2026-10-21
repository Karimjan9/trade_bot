<section class="card">
    <div class="card-heading"><div><h2>Kanaldan o‘rganiladigan SL va foydani himoyalash</h2><p>Yetishmagan darajalar kanalning oldingi narx yo‘llaridan hisoblanadi.</p></div></div>
    <form method="post" action="{{ route('channels.research', $channel) }}" class="form-row">
        @csrf
        <label>Instrument<select name="symbol">@foreach(array_keys(config('trading.symbols')) as $symbol)<option>{{ $symbol }}</option>@endforeach</select></label>
        <label>Yo‘nalish<select name="direction"><option>BUY</option><option>SELL</option></select></label>
        <label>1 pip narxdagi masofasi<input type="number" name="pip_size" value="{{ $channel->research_settings['XAUUSD']['pip_size'] ?? config('trading.symbols.XAUUSD.pip_size') }}" min="0.00000001" step="any" required></label>
        <label>Kuzatuv muddati, soniya<input type="number" name="horizon_seconds" value="{{ $channel->research_settings['XAUUSD']['horizon_seconds'] ?? config('trading.research.horizon_seconds') }}" min="60" max="86400" required></label>
        <button class="button secondary">Tarixdan hisoblash</button>
    </form>
    <p class="help">GOLD: 100 pips = narxda 10.0. SL va yurish masofasi avtomatik tanlanadi. O‘rganish va keyingi davr tekshiruvi ajratilgan; kamida {{ config('trading.research.min_train') }} / {{ config('trading.research.min_validation') }} ta qamrovi yetarli kuzatuv kerak. Rejalashtiruvchi tarixni har soatda qayta tahlil qiladi.</p>
    <div class="table-wrap"><table><thead><tr><th>Versiya</th><th>Instrument</th><th>SL / Kutiladigan yurish</th><th>Foyda qadami</th><th>Train / tekshiruv</th><th>Holat</th></tr></thead><tbody>
    @forelse($evidence['policies'] as $policy)
        <tr><td>#{{ $policy->id }}</td><td>{{ $policy->symbol }} {{ $policy->direction }}</td><td>{{ $policy->sl_pips ?? '—' }} / {{ $policy->target_pips ?? '—' }} pips</td><td>{{ $policy->lock_step_pips ?? '—' }} pips</td><td>{{ $policy->evidence['train_count'] }} / {{ $policy->evidence['validation_count'] }}<small>Tekshiruv expectancy: {{ $policy->evidence['validation']['expectancy_r'] ?? '—' }} R</small></td><td><span class="badge {{ $policy->status === 'PAPER_ELIGIBLE' ? 'green' : 'neutral' }}">{{ $policy->status }}</span><details><summary>Hisoblash dalillari</summary><pre>{{ json_encode($policy->evidence, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details></td></tr>
    @empty<tr><td colspan="6" class="empty-cell">Hali kanal siyosati hisoblanmagan.</td></tr>@endforelse
    </tbody></table></div>
    <p class="help">Kutiladigan yurish — avtomatik yopish nuqtasi emas. Masalan, qadam 50 pips bo‘lsa: +50 → SL entry, +100 → SL +50, +150 → SL +100. Kanal TP bermagan trade shu himoya va vaqt bo‘yicha chiqish bilan boshqariladi. Kanalning original SL/TP ma’lumoti o‘zgartirilmaydi.</p>
</section>
