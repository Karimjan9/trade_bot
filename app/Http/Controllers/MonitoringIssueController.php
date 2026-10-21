<?php

namespace App\Http\Controllers;

use App\Models\MonitoringIssue;
use App\Models\Signal;
use App\Models\TelegramChannel;
use App\Services\Monitoring\Journal;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MonitoringIssueController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_merge(['all', 'unresolved'], array_keys(MonitoringIssue::STATUSES)))], 'kind' => ['nullable', Rule::in(array_keys(MonitoringIssue::KINDS))], 'channel_id' => 'nullable|integer|exists:telegram_channels,id', 'q' => 'nullable|string|max:100']);
        $query = MonitoringIssue::with('channel')->orderByDesc('last_seen_at')->orderByDesc('id');
        $status = $filters['status'] ?? 'unresolved';
        if ($status === 'unresolved') {
            $query->whereNotIn('status', MonitoringIssue::CLOSED);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        foreach (['kind', 'channel_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['q'])) {
            $search = $filters['q'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%');
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }

        return view('issues.index', ['issues' => $query->paginate(25)->withQueryString(), 'channels' => TelegramChannel::orderBy('title')->get(['id', 'title']), 'filters' => $filters, 'status' => $status, 'openCount' => MonitoringIssue::whereNotIn('status', MonitoringIssue::CLOSED)->count(), 'waitingCount' => MonitoringIssue::where('status', 'WAITING_USER')->count()]);
    }

    public function show(MonitoringIssue $issue)
    {
        return view('issues.show', ['issue' => $issue->load(['channel', 'signal', 'message']), 'events' => $issue->events()->with('user')->latest('id')->paginate(30)]);
    }

    public function store(Request $request, Journal $journal)
    {
        $data = $request->validate(['title' => 'required|string|max:200', 'description' => 'required|string|max:4000', 'question' => 'nullable|string|max:1000', 'kind' => ['required', Rule::in(array_keys(MonitoringIssue::KINDS))], 'channel_id' => 'nullable|integer|exists:telegram_channels,id', 'signal_id' => 'nullable|integer|exists:signals,id']);
        if (! empty($data['signal_id'])) {
            $signal = Signal::findOrFail($data['signal_id']);
            if (! empty($data['channel_id']) && (int) $data['channel_id'] !== $signal->channel_id) {
                throw ValidationException::withMessages(['signal_id' => 'Signal tanlangan kanalga tegishli emas.']);
            }
            $data['channel_id'] = $signal->channel_id;
            $data['message_id'] = $signal->message_id;
        }
        $identity = (string) Str::uuid();
        $issue = $journal->record('MANUAL_NOTE', $identity, $identity, $data + ['severity' => 'WARNING', 'automatic' => false], [], $request->user()->id);

        return redirect()->route('issues.show', $issue)->with('success', 'Yozuv saqlandi. Muhokamani shu yerda davom ettiramiz.');
    }

    public function comment(Request $request, MonitoringIssue $issue, Journal $journal)
    {
        $data = $request->validate(['body' => 'required|string|max:4000']);
        $journal->comment($issue, $data['body'], $request->user()->id);

        return back()->with('success', 'Izoh tarixga qo‘shildi.');
    }

    public function status(Request $request, MonitoringIssue $issue, Journal $journal)
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(MonitoringIssue::STATUSES))], 'version' => 'required|integer|min:1', 'note' => 'nullable|string|max:4000']);
        $journal->changeStatus($issue, $data['status'], (int) $data['version'], $data['note'] ?? null, $request->user()->id);

        return back()->with('success', 'Holat va qaror tarixga yozildi.');
    }
}
