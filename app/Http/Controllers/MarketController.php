<?php

namespace App\Http\Controllers;

use App\Services\Trading\MarketData;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MarketController extends Controller
{
    public static function rules(): array
    {
        return ['symbol' => ['required', Rule::in(array_keys(config('trading.symbols')))], 'bid' => 'required|numeric|gt:0', 'ask' => 'required|numeric|gte:bid', 'atr' => 'nullable|numeric|gt:0', 'regime' => ['required', Rule::in(['UNKNOWN', 'TREND_UP', 'TREND_DOWN', 'RANGE', 'BREAKOUT', 'HIGH_VOLATILITY', 'LOW_VOLATILITY', 'NEWS_SHOCK'])], 'session' => ['required', Rule::in(['ASIA', 'LONDON', 'NEW_YORK', 'OVERLAP', 'UNKNOWN'])], 'source' => 'required|string|max:64', 'market_open' => 'required|boolean', 'is_historical' => 'required|boolean', 'recorded_at' => 'required|date'];
    }

    public function store(Request $request, MarketData $market)
    {
        $quote = $market->record($request->validate(self::rules()));

        return response()->json(['id' => $quote->id], 201);
    }
}
