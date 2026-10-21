@php
    $mlStateLabels = ['ACTIVE_PAPER' => 'Paper filtr faol', 'SHADOW_ONLY' => 'Kuzatuvdagi model', 'INSUFFICIENT_DATA' => 'Ma’lumot yetarli emas', 'QUARANTINED' => 'Natija yomonlashgan', 'RETIRED' => 'Oldingi versiya', 'LEARNING_DISABLED' => 'O‘qitish o‘chirilgan'];
    $mlFeatureLabels = ['direction_buy' => 'BUY yo‘nalishi', 'spread_r' => 'Spread xarajati', 'reward_risk' => 'Foyda/risk nisbati', 'stop_atr' => 'Stop va volatilitet nisbati', 'entry_distance_r' => 'Kirish zonasi', 'latency_fraction' => 'Signal kechikishi', 'trailing_plan' => 'Bosqichli SL', 'history_coverage' => 'Tarix qamrovi', 'range_position' => 'Diapazondagi narx', 'breakout_up' => 'Yuqoriga chiqish', 'breakout_down' => 'Pastga chiqish', 'breakout_alignment' => 'Yo‘nalishga mos chiqish', 'rsi_14' => 'RSI', 'volatility_fraction' => 'Volatilitet', 'trend_5' => '5 minutlik trend', 'trend_15' => '15 minutlik trend', 'trend_30' => '30 minutlik trend', 'alignment_5' => '5 minutlik trendga moslik', 'alignment_15' => '15 minutlik trendga moslik', 'alignment_30' => '30 minutlik trendga moslik', 'session_asia' => 'Osiyo sessiyasi', 'session_london' => 'London sessiyasi', 'session_new_york' => 'Nyu-York sessiyasi', 'session_overlap' => 'Sessiyalar kesishuvi'];
    $mlHypothesisLabels = ['TREND_ALIGNED' => 'Trend yo‘nalishida', 'COUNTER_TREND' => 'Trendga qarshi', 'BREAKOUT_ALIGNED' => 'Diapazondan chiqish', 'UNDETERMINED' => 'Usul aniqlanmagan'];
    $mlStatus = $channel->source !== 'manual' && ! $channel->learning_eligible ? 'LEARNING_DISABLED' : ($mlActive ? 'ACTIVE_PAPER' : $mlModels->first()?->state);
@endphp
<section class="card">
    <div class="card-heading"><h2>Kanalning ML modeli</h2><span class="badge">{{ $mlStateLabels[$mlStatus] ?? 'MA’LUMOT TO‘PLANMOQDA' }}</span></div>
    <p>Model signal kelgan paytdagi trend, diapazon, volatilitet, sessiya va savdo xarajatlarini baholaydi. Yaxshi va rad etilgan signallarning keyingi natijalari bir xil qoida bilan kuzatiladi.</p>
    @if($trajectorySummary)
    <h3>Qisqa foyda va keyingi katta yurish</h3>
    <div class="table-wrap"><table><thead><tr><th>Yo‘nalish / davr</th><th>Kuzatuv</th><th>+50 pipsga yetdi</th><th>+100 pipsga yetdi</th><th>+100 gacha qarshi yurish</th><th>+100 gacha vaqt</th><th>SL oldin urilgan</th></tr></thead><tbody>
        @foreach($trajectorySummary as $path)<tr><td>{{ $path['symbol'] }} {{ $path['direction'] }}<small>{{ round($path['horizon_seconds'] / 60) }} min · 1 pip={{ $path['pip_size'] }}</small></td><td>{{ $path['count'] }}</td><td>{{ $path['reached_50'] }}</td><td>{{ $path['reached_100'] }}</td><td>{{ $path['median_adverse_before_100_pips'] === null ? '—' : number_format($path['median_adverse_before_100_pips'], 1).' pips' }}</td><td>{{ $path['median_seconds_to_100'] === null ? '—' : round($path['median_seconds_to_100'] / 60, 1).' min' }}</td><td>{{ $path['stop_before_100'] }}</td></tr>@endforeach
    </tbody></table></div>
    <p class="help">Faqat qamrovi yetarli tugallangan narx yo‘llari. Qarshi yurish va vaqt +100 ga yetgan misollarning medianasi; yetmagan misollar alohida sonlarda qoladi. Bu raqamlar yopilgan foyda emas va kichik namuna hali kanal usulini isbotlamaydi.</p>
    @endif
    <div class="health-item"><span>Kuzatuvlar</span><strong>{{ $mlCounts->sum() }}</strong></div>
    <p class="help">@forelse($mlCounts as $state => $count){{ $state }}: {{ $count }}{{ $loop->last ? '' : ' · ' }}@empty Hali ML kuzatuvi yo‘q. @endforelse</p>
    <form method="post" action="{{ route('channels.learn', $channel) }}">@csrf
        <input type="hidden" name="learning_eligible" value="0">
        @if($channel->source === 'manual')
            <input type="hidden" name="learning_eligible" value="1"><p class="help">Laboratoriya ma’lumotlari paper model sinovi uchun ishlatiladi.</p>
        @else
            <label><input type="checkbox" name="learning_eligible" value="1" @checked($channel->learning_eligible)> Ushbu kanal ma’lumotlaridan model o‘qitishda foydalanish</label>
        @endif
        <button class="button secondary">Natijalarni tekshirish va modelni o‘qitish</button>
    </form>
    <p class="help">Kamida {{ config('trading.ml.min_train') + config('trading.ml.min_calibration') + config('trading.ml.min_test') }} ta yetarli narx tarixi bor, yakunlangan kuzatuv kerak. Ustma-ust natija davrlari chiqarilgach ko‘proq signal talab qilinishi mumkin. Alohida keyingi sinovda foyda yaxshilanmasa model kuzatuvda qoladi. Har 5 daqiqada yangi natijalar tekshiriladi.</p>
    @foreach($mlModels as $model)
        <div class="agent-mini">
            <h3>Model #{{ $model->id }} <span class="badge {{ $model->state === 'ACTIVE_PAPER' ? 'green' : 'neutral' }}">{{ $mlStateLabels[$model->state] ?? $model->state }}</span></h3>
            <p>{{ $model->report['rows'] ?? 0 }} ta kuzatuv · {{ $model->created_at->timezone('Asia/Tashkent')->format('d.m H:i') }}</p>
            @if(isset($model->report['split']))<p class="help">O‘qitish: {{ $model->report['split']['train'] }} · Sozlash: {{ $model->report['split']['calibration'] }} · Mustaqil sinov: {{ $model->report['split']['test'] }} · Ustma-ust davrlar: {{ $model->report['split']['purged'] }}</p>@endif
            @if(isset($model->report['test_filtered']))
                <div class="table-wrap"><table><thead><tr><th>Keyingi sinov</th><th>Filtrsiz</th><th>ML bilan</th></tr></thead><tbody>
                    <tr><td>Barcha signallarga nisbatan R</td><td>{{ number_format($model->report['test_baseline']['r_per_opportunity'], 3) }}</td><td>{{ number_format($model->report['test_filtered']['r_per_opportunity'], 3) }}</td></tr>
                    <tr><td>Eng katta pasayish, R</td><td>{{ number_format($model->report['test_baseline']['max_drawdown_r'], 2) }}</td><td>{{ number_format($model->report['test_filtered']['max_drawdown_r'], 2) }}</td></tr>
                    <tr><td>Tanlangan signallar</td><td>{{ $model->report['test_baseline']['count'] }}</td><td>{{ $model->report['test_filtered']['count'] }}</td></tr>
                </tbody></table></div>
                <p class="help">Bu muzlatilgan reja bo‘yicha narx qayta ijrosi; hisobdagi haqiqiy foyda emas. Kanal muallifining asl fikrlash sababi isbotlangan deb ko‘rsatilmaydi.</p>
                <p><strong>Natija bilan bog‘langan omillar:</strong> @foreach(($model->report['associations'] ?? []) as $association){{ $mlFeatureLabels[$association['feature']] ?? str_replace('symbol_', '', $association['feature']) }} ({{ number_format($association['net_r_per_standard_deviation'], 2) }} R){{ $loop->last ? '' : ', ' }}@endforeach</p>
                @foreach(($model->report['market_hypotheses'] ?? []) as $hypothesis => $stats)<p class="help">{{ $mlHypothesisLabels[$hypothesis] ?? $hypothesis }} · n={{ $stats['count'] }} · {{ number_format($stats['mean_net_r'], 2) }} R</p>@endforeach
            @endif
            @if(isset($model->report['promotion_reason']))<p class="help">{{ $model->report['promotion_reason'] }}</p>@endif
            @if(isset($model->report['reason']))<p class="help">{{ $model->report['reason'] }}</p>@endif
        </div>
    @endforeach
    @if($mlPredictions->isNotEmpty())<div class="table-wrap"><table><thead><tr><th>Signal</th><th>ML qarori</th><th>Foydali yakun ehtimoli</th><th>Kutiladigan natija</th></tr></thead><tbody>
        @foreach($mlPredictions as $prediction)<tr><td><a href="{{ route('signals.show', $prediction->signal_id) }}">#{{ $prediction->signal_id }}</a></td><td>{{ $prediction->action }}<small>{{ $prediction->explanation['reason'] ?? '' }}</small></td><td>{{ number_format($prediction->probability * 100, 1) }}%</td><td>{{ number_format($prediction->expected_r, 2) }} R</td></tr>@endforeach
    </tbody></table></div>@endif
</section>
