<?php

namespace App\Services\Monitoring;

use App\Models\ChannelMlModel;
use App\Models\ChannelStrategy;
use App\Models\ClaimCheck;
use App\Models\Signal;
use App\Models\TelegramMessage;
use App\Models\TelegramMessageEvent;
use App\Services\Signals\MediaPolicy;
use App\Services\Signals\SignalParser;

class SignalIssues
{
    public function parsed(TelegramMessage $message, TelegramMessageEvent $event, array $parsed): void
    {
        if ($parsed['type'] !== 'REVIEW_REQUIRED') {
            return;
        }
        $save = ($parsed['intent'] ?? null) === 'SAVE_POSITION';
        $code = $save ? 'SAVE_FRACTION_UNKNOWN' : 'SIGNAL_PARSE_REVIEW';
        app(Journal::class)->record($code, 'channel:'.$message->channel_id, 'telegram:'.$event->event_id, [
            'kind' => 'QUESTION', 'severity' => 'WARNING', 'channel_id' => $message->channel_id, 'message_id' => $message->id, 'signal_id' => $message->signal?->id,
            'title' => $save ? 'Save ulushi aniqlashtirilishi kerak' : 'Signal matni noaniq',
            'description' => $save ? 'Kanal save dedi, lekin yopiladigan ulushni bermadi. Noaniq buyruq pozitsiyaga qo‘llanmadi.' : 'Parser xabarni ishonchli ajrata olmadi. Original xabar tekshirish uchun saqlangan.',
            'question' => $save ? 'Shu kanal uchun save ulushini qanday belgilaymiz?' : 'Shu kanal yozuvini qaysi qoida bilan ajratamiz?',
        ], ['source_event_id' => $event->id, 'parser_version' => SignalParser::VERSION, 'reason' => $parsed['reason'] ?? null, 'intent' => $parsed['intent'] ?? null]);
    }

    public function image(TelegramMessage $message, TelegramMessageEvent $event): void
    {
        $raw = $event->payload['raw_json'] ?? [];
        if (app(MediaPolicy::class)->isVideo($raw) || (data_get($raw, '_signal_forge_media.kind') !== 'photo' && data_get($raw, 'media._') !== 'MessageMediaPhoto')) {
            return;
        }
        app(Journal::class)->record('IMAGE_READER_UNAVAILABLE', 'channel:'.$message->channel_id, 'telegram:'.$event->event_id, [
            'kind' => 'QUESTION', 'severity' => 'WARNING', 'channel_id' => $message->channel_id, 'message_id' => $message->id, 'signal_id' => $message->signal?->id,
            'title' => 'Rasm mazmuni hali o‘qilmagan',
            'description' => 'Hozir vision adapteri ulanmagan: faqat mavjud yozma matn tahlil qilinadi. Rasm ichidagi darajalar aniqlanmagan.',
            'question' => 'Vision adapterini qo‘shganda shu kanalning rasm namunalari bilan tekshiramiz.',
        ], ['source_event_id' => $event->id, 'media_kind' => 'photo', 'downloaded' => false]);
    }

    public function unlinked(TelegramMessage $message, TelegramMessageEvent $event, bool $report): void
    {
        app(Journal::class)->record($report ? 'UNLINKED_RESULT' : 'UNLINKED_UPDATE', 'channel:'.$message->channel_id, 'telegram:'.$event->event_id, [
            'kind' => 'QUESTION', 'severity' => 'WARNING', 'channel_id' => $message->channel_id, 'message_id' => $message->id,
            'title' => $report ? 'Natija qaysi signalga tegishli?' : 'Buyruq qaysi signalga tegishli?',
            'description' => 'Aniq reply orqali signal topilmadi. Taxminiy bog‘lash va pozitsiyaga o‘zgartirish kiritish bajarilmadi.',
            'question' => 'Kanal xabarlarini ishonchli bog‘lash qoidasini birga aniqlaymiz.',
        ], ['source_event_id' => $event->id, 'reply_to_message_id' => $message->reply_to_message_id, 'historical' => $message->is_historical]);
    }

    public function claim(ClaimCheck $check, Signal $signal, ?int $messageId): void
    {
        if (! in_array($check->status, ['OBSERVED_SHORTFALL', 'DATA_GAP', 'UNITS_UNKNOWN', 'NO_OBSERVED_ENTRY'])) {
            return;
        }
        $short = $check->status === 'OBSERVED_SHORTFALL';
        $code = $short ? 'CLAIM_SHORTFALL' : 'CLAIM_'.$check->status;
        app(Journal::class)->record($code, 'channel:'.$signal->channel_id.':'.$signal->symbol, 'claim-check:'.$check->id, [
            'kind' => $short ? 'ANOMALY' : 'QUESTION', 'severity' => 'WARNING', 'channel_id' => $signal->channel_id, 'signal_id' => $signal->id, 'message_id' => $messageId,
            'title' => $short ? 'Kanal da’vosi va kuzatilgan yurish farq qiladi' : 'Kanal natijasini tekshirishda ma’lumot yetishmayapti',
            'description' => $short ? 'Berilgan davrda kuzatilgan bid/ask yurishi e’lon qilingan pipsga yetmagan. Bu realized foyda yoki aldov xulosasi emas.' : 'Narx qamrovi, kirish yoki birlik yetishmayapti. Da’vo tasdiqlanmadi ham, xato deb belgilanmadi ham.',
            'question' => $short ? 'Original da’vo, kirish va narx dalillarini birga tekshiramiz.' : 'Narx qamrovi va kanal birlik sozlamasini tekshiramiz.',
        ], ['claim_check_id' => $check->id, 'status' => $check->status, 'symbol' => $signal->symbol, 'claimed_pips' => $check->claimed_pips, 'observed_pips' => $check->observed_pips, 'difference_pips' => $check->shortfall_pips, 'coverage' => $check->evidence]);
    }

    public function drift(ChannelMlModel $model): void
    {
        app(Journal::class)->record('ML_MODEL_DRIFT', 'model-channel:'.$model->channel_id, 'model:'.$model->id, [
            'kind' => 'ANOMALY', 'severity' => 'WARNING', 'channel_id' => $model->channel_id,
            'title' => 'ML modeli karantinga o‘tdi', 'description' => 'Yangi natijalarda model sifati pasaydi. Sabablar va yangi sinov davri ko‘rib chiqiladi.',
            'question' => 'Model va yangi bozor davri dalillarini birga tekshiramiz.',
        ], ['model_id' => $model->id, 'drift' => $model->report['drift'] ?? []]);
    }

    public function strategy(ChannelStrategy $strategy): void
    {
        if ($strategy->status !== 'UNPROFITABLE') {
            return;
        }
        app(Journal::class)->record('CHANNEL_TESTS_UNPROFITABLE', 'channel:'.$strategy->channel_id.':'.$strategy->symbol.':'.$strategy->direction, 'strategy:'.$strategy->id, [
            'kind' => 'ANOMALY', 'severity' => 'WARNING', 'channel_id' => $strategy->channel_id,
            'title' => 'Sinalgan kanal usullari zararli chiqdi', 'description' => 'Ushbu instrument va yo‘nalishda yangi paper kirishlar bloklangan; monitoring davom etadi. Xulosa faqat sinalgan usullar va davrga tegishli.',
            'question' => 'Sinov dalillari va keyingi kuzatuv davrini ko‘rib chiqamiz.',
        ], ['strategy_id' => $strategy->id, 'symbol' => $strategy->symbol, 'direction' => $strategy->direction, 'test_count' => $strategy->report['test_count'] ?? null, 'test_distinct_days' => $strategy->report['test_distinct_days'] ?? null]);
    }
}
