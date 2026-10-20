<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentDecision;
use App\Models\AuditLog;
use App\Models\Backtest;
use App\Models\MarketSnapshot;
use App\Models\PaperTrade;
use App\Models\RiskProfile;
use App\Models\ServiceHeartbeat;
use App\Models\Signal;
use App\Models\TelegramChannel;
use App\Services\Analytics\Backtester;
use App\Services\Signals\ChannelRegistry;
use App\Services\Signals\EventIngestor;
use App\Services\Trading\DecisionEngine;
use App\Services\Trading\MarketData;
use App\Services\Trading\Portfolio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlatformController extends Controller
{
    public function dashboard(Portfolio $portfolio)
    {
        return view('dashboard', ['summary' => $portfolio->summary(), 'risk' => RiskProfile::current(), 'channels' => TelegramChannel::with('profile')->withCount('signals')->latest()->get(), 'decisions' => AgentDecision::with('signal.channel')->latest()->limit(8)->get(), 'heartbeats' => ServiceHeartbeat::all()->keyBy('service'), 'signalsToday' => Signal::whereDate('created_at', today())->count(), 'agentsCount' => Agent::where('state', 'PAPER')->count(), 'curve' => PaperTrade::where('mode', 'paper')->where('status', 'closed')->orderBy('closed_at')->pluck('realized_pnl')->all(), 'pending' => DB::table('telegram_message_events')->where('status', 'pending')->count()]);
    }

    public function channels()
    {
        return view('channels', ['channels' => TelegramChannel::with('profile')->withCount(['messages', 'signals'])->latest()->get()]);
    }

    public function addChannel(Request $request, ChannelRegistry $registry)
    {
        $request->merge(['username' => strtolower(ltrim(trim($request->string('username')), '@'))]);
        $data = $request->validate(['username' => 'required|regex:/^[a-z][a-z0-9_]{4,31}$/|unique:telegram_channels,username', 'title' => 'required|string|max:100', 'source' => ['required', Rule::in(['telegram', 'manual'])]]);
        $channel = $registry->add($data);

        return redirect()->route('channels.show', $channel)->with('success', 'Kanal va paper/shadow agentlari yaratildi.');
    }

    public function channel(TelegramChannel $channel)
    {
        return view('channel', ['channel' => $channel->load('profile', 'agents'), 'messages' => $channel->messages()->latest()->paginate(15), 'signals' => $channel->signals()->latest()->limit(10)->get(), 'backtests' => Backtest::where('channel_id', $channel->id)->latest()->limit(5)->get()]);
    }

    public function toggleChannel(TelegramChannel $channel)
    {
        DB::transaction(function () use ($channel) {
            RiskProfile::current(true);
            $old = $channel->toArray();
            $channel->update(['enabled' => ! $channel->enabled]);
            AuditLog::record('channel.toggled', 'channel:'.$channel->id, $old, $channel->toArray());
        });

        return back()->with('success', 'Kanal kuzatuvi yangilandi.');
    }

    public function signals(Request $request)
    {
        $query = Signal::with('channel')->latest();
        if ($request->filled('channel')) {
            $query->where('channel_id', $request->integer('channel'));
        }

        return view('signals', ['signals' => $query->paginate(25), 'channels' => TelegramChannel::all()]);
    }

    public function signal(Signal $signal)
    {
        return view('signal', ['signal' => $signal->load('channel', 'message', 'events', 'decisions', 'trades')]);
    }

    public function reevaluate(Signal $signal, DecisionEngine $engine)
    {
        $decision = $engine->evaluate($signal);
        AuditLog::record('signal.reevaluated', 'signal:'.$signal->id, null, ['action' => $decision->action]);

        return back()->with('success', 'Qaror: '.$decision->action.' · '.implode(', ', $decision->reasons));
    }

    public function trades(Request $request)
    {
        $mode = $request->query('mode') === 'shadow' ? 'shadow' : 'paper';

        return view('trades', ['trades' => PaperTrade::with('channel')->where('mode', $mode)->latest()->paginate(25), 'mode' => $mode]);
    }

    public function agents()
    {
        return view('agents', ['agents' => Agent::with('channel.profile')->get()]);
    }

    public function risk()
    {
        return view('risk', ['risk' => RiskProfile::current()]);
    }

    public function saveRisk(Request $request)
    {
        $data = $request->validate(['max_trade_risk_pct' => 'required|numeric|min:0.01|max:1', 'max_channel_risk_pct' => 'required|numeric|min:0.01|max:3', 'max_symbol_risk_pct' => 'required|numeric|min:0.01|max:5', 'max_portfolio_risk_pct' => 'required|numeric|min:0.01|max:10', 'max_daily_loss_pct' => 'required|numeric|min:0.1|max:5', 'max_weekly_loss_pct' => 'required|numeric|min:0.1|max:10', 'max_drawdown_pct' => 'required|numeric|min:0.1|max:20', 'max_positions' => 'required|integer|min:1|max:20', 'max_spread_r' => 'required|numeric|min:0.001|max:0.3', 'min_reward_risk' => 'required|numeric|min:0.5|max:10']);
        DB::transaction(function () use ($data) {
            $risk = RiskProfile::current(true);
            $before = $risk->toArray();
            $risk->update($data);
            AuditLog::record('risk.updated', 'risk:1', $before, $risk->toArray());
        });

        return back()->with('success', 'Risk limitlari saqlandi.');
    }

    public function emergency(Request $request)
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        DB::transaction(function () use ($data) {
            $risk = RiskProfile::current(true);
            $before = $risk->toArray();
            $risk->update(['emergency_stop' => $data['enabled']]);
            AuditLog::record('risk.emergency_stop', 'risk:1', $before, $risk->toArray());
        });

        return back()->with('success', $data['enabled'] ? 'Yangi pozitsiyalar bloklandi. Ochiq pozitsiyalar boshqaruvi davom etadi.' : 'Paper savdo qayta yoqildi.');
    }

    public function lab()
    {
        return view('lab', ['channels' => TelegramChannel::where('enabled', true)->where('source', 'manual')->get(), 'quotes' => MarketSnapshot::where('is_historical', false)->latest('recorded_at')->limit(8)->get()]);
    }

    public function manualMessage(Request $request, EventIngestor $ingestor)
    {
        $data = $request->validate(['channel_id' => 'required|integer|exists:telegram_channels,id', 'text' => 'required|string|max:50000', 'reply_to_message_id' => 'nullable|integer|min:1']);
        $channel = TelegramChannel::findOrFail($data['channel_id']);
        abort_unless($channel->source === 'manual' && $channel->enabled, 403);
        DB::transaction(function () use ($channel, $data, $ingestor) {
            RiskProfile::current(true);
            $id = max((int) $channel->last_message_id, (int) DB::table('telegram_message_events')->where('channel_id', $channel->id)->max('telegram_message_id')) + 1;
            $ingestor->ingest($channel, ['event_id' => (string) Str::uuid(), 'message_id' => $id, 'kind' => 'new', 'text' => $data['text'], 'reply_to_message_id' => $data['reply_to_message_id'] ?? null, 'posted_at' => now()->toIso8601String(), 'occurred_at' => now()->toIso8601String(), 'is_historical' => false, 'raw_json' => ['source' => 'manual_lab']]);
            AuditLog::record('lab.message', 'channel:'.$channel->id, null, ['message_id' => $id]);
        });

        return back()->with('success', 'Xabar navbatga qo‘shildi. Queue worker ishlayotgan bo‘lishi kerak.');
    }

    public function manualQuote(Request $request, MarketData $market)
    {
        $request->merge(['source' => 'manual_lab', 'is_historical' => false, 'recorded_at' => now()->toIso8601String(), 'market_open' => true]);
        $market->record($request->validate(MarketController::rules()));
        AuditLog::record('lab.quote', $request->string('symbol')->toString());

        return back()->with('success', 'Paper bozor narxi yangilandi.');
    }

    public function backtests()
    {
        return view('backtests', ['backtests' => Backtest::latest()->paginate(15), 'channels' => TelegramChannel::all()]);
    }

    public function runBacktest(Request $request, Backtester $backtester)
    {
        $data = $request->validate(['channel_id' => 'required|exists:telegram_channels,id', 'strategy' => ['required', Rule::in(['first_tp', 'last_tp'])]]);
        $backtest = $backtester->run(TelegramChannel::findOrFail($data['channel_id']), $data['strategy']);
        AuditLog::record('backtest.completed', 'backtest:'.$backtest->id);

        return back()->with('success', 'Backtest tugadi. Natija #'.$backtest->id);
    }

    public function importTicks(Request $request)
    {
        $request->validate(['file' => 'required|file|max:10240']);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle);
        if ($header) {
            $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
        }
        abort_unless($header === ['symbol', 'recorded_at', 'bid', 'ask'], 422, 'CSV header: symbol,recorded_at,bid,ask');
        $count = 0;
        try {
            DB::transaction(function () use ($handle, &$count) {
                while (($row = fgetcsv($handle)) !== false) {
                    abort_if(count($row) !== 4 || ++$count > 100000, 422, 'Invalid CSV row or too many ticks.');
                    $data = array_combine(['symbol', 'recorded_at', 'bid', 'ask'], $row);
                    validator($data, ['symbol' => ['required', Rule::in(array_keys(config('trading.symbols')))], 'recorded_at' => 'required|date', 'bid' => 'required|numeric|gt:0', 'ask' => 'required|numeric|gte:bid'])->validate();
                    MarketSnapshot::create($data + ['source' => 'csv_import', 'is_historical' => true]);
                }
            });
        } finally {
            fclose($handle);
        }
        AuditLog::record('market.csv_import', null, null, ['count' => $count]);

        return back()->with('success', $count.' ta tarixiy tick import qilindi.');
    }

    public function audit()
    {
        return view('audit', ['logs' => AuditLog::latest('id')->paginate(30)]);
    }

    public function integrations()
    {
        return view('integrations', ['heartbeats' => ServiceHeartbeat::all()->keyBy('service'), 'pending' => DB::table('telegram_message_events')->where('status', 'pending')->count(), 'failed' => DB::table('failed_jobs')->count()]);
    }
}
