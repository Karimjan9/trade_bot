@extends('layout')
@section('title', 'Signallar')
@section('description', 'Standartlashtirilgan signal va qarorlar tarixi.')
@section('content')<section class="card"><form class="form-row" method="get"><label>Manba<select name="channel"><option value="">Barcha kanallar</option>@foreach($channels as $channel)<option value="{{ $channel->id }}" @selected(request('channel') == $channel->id)>{{ $channel->title }}</option>@endforeach</select></label><button class="button secondary">Filtrlash</button></form></section><section class="card">@include('partials.signal-table'){{ $signals->links() }}</section>@endsection
