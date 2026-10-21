<?php

namespace App\Services\Monitoring;

use App\Models\ServiceHeartbeat;
use App\Models\TelegramChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class SystemIssues
{
    private static bool $reporting = false;

    public function applicationFailure(Throwable $exception): void
    {
        // Queue retries are reported only after exhaustion by the JobFailed listener.
        if (app()->runningInConsole() && str_starts_with($_SERVER['argv'][1] ?? '', 'queue:')) {
            return;
        }
        $this->attempt(function () use ($exception) {
            $route = app()->bound('request') ? request()->route()?->getName() : null;
            $scope = hash('sha256', $exception::class.'|'.$exception->getFile().'|'.$exception->getLine().'|'.$route);
            app(Journal::class)->record('APPLICATION_ERROR', $scope, (string) Str::uuid(), [
                'kind' => 'ERROR', 'severity' => 'ERROR', 'title' => 'Ilovada texnik xato qayd etildi',
                'description' => 'Ilova xatosi tekshirish uchun qayd qilindi. Jurnalga so‘rov, maxfiy qiymatlar yoki stack trace ko‘chirilmaydi.',
            ], ['exception_class' => $exception::class, 'route_name' => $route, 'source_file' => basename($exception->getFile()), 'source_line' => $exception->getLine()]);
        });
    }

    public function jobFailure(string $uuid, string $jobClass, string $exceptionClass): void
    {
        $this->attempt(function () use ($uuid, $jobClass, $exceptionClass) {
            $class = $this->className($jobClass);
            app(Journal::class)->record('QUEUE_JOB_FAILED', 'job:'.$class, 'failed-job:'.$uuid, [
                'kind' => 'ERROR', 'severity' => 'ERROR', 'title' => 'Fon vazifasi yakunlanmadi',
                'description' => 'Queue vazifasi urinishlardan keyin xato bilan to‘xtadi. Sababni tekshirib, qayta ishga tushirish kerak.',
                'question' => 'Qaysi tuzatish va qayta ishlash kerakligini birga hal qilamiz.',
            ], ['job_uuid' => $uuid, 'job_class' => $class, 'exception_class' => $this->className($exceptionClass)]);
        });
    }

    public function collect(): void
    {
        // Never deserialize a queue command or copy an exception message/trace.
        DB::table('failed_jobs')->orderBy('id')->lazyById(100)->each(function ($job) {
            $payload = json_decode($job->payload, true) ?? [];
            preg_match('/^([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)(?=:)/', $job->exception, $match);
            $this->jobFailure($job->uuid, $payload['displayName'] ?? 'UnknownJob', $match[1] ?? 'UnknownException');
        });
        ServiceHeartbeat::where('status', 'DEGRADED')->each(function ($heartbeat) {
            $this->degraded($heartbeat);
        });
        TelegramChannel::where('enabled', true)->where('status', 'ERROR')->each(function ($channel) {
            $this->channelError($channel);
        });
    }

    public function degraded(ServiceHeartbeat $heartbeat): void
    {
        $this->attempt(function () use ($heartbeat) {
            app(Journal::class)->record('SERVICE_DEGRADED', 'service:'.$heartbeat->service, 'service:'.$heartbeat->service.':'.($heartbeat->monitoring_error_key ?? 'legacy'), [
                'kind' => 'ERROR', 'severity' => 'WARNING', 'title' => 'Servis nosozlik haqida xabar berdi',
                'description' => 'Ulangan servis DEGRADED holatini yubordi. Ulash va qayta yetkazish holatini tekshirish kerak.',
            ], ['service' => $heartbeat->service, 'status' => 'DEGRADED', 'seen_at' => $heartbeat->seen_at->toIso8601String()]);
        });
    }

    public function channelError(TelegramChannel $channel): void
    {
        $this->attempt(function () use ($channel) {
            app(Journal::class)->record('CHANNEL_GATEWAY_ERROR', 'channel:'.$channel->id, 'channel-error:'.$channel->id.':'.($channel->monitoring_error_key ?? 'legacy'), [
                'kind' => 'ERROR', 'severity' => 'WARNING', 'channel_id' => $channel->id, 'title' => 'Kanalni tinglashda xato',
                'description' => 'Gateway kanal uchun ERROR holatini qayd etdi. Original texnik xabar kanal sahifasida; jurnalga maxfiy ma’lumot ko‘chirilmaydi.',
            ], ['channel_status' => 'ERROR']);
        });
    }

    private function className(string $value): string
    {
        return preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,200}$/D', $value) ? $value : 'UnknownClass';
    }

    /** Logging a failure must never replace the original failure with a journal error. */
    private function attempt(callable $callback): void
    {
        if (self::$reporting) {
            return;
        }
        self::$reporting = true;
        try {
            if (Schema::hasTable('monitoring_issues')) {
                $callback();
            }
        } catch (Throwable $exception) {
            Log::warning('Monitoring journal write failed', ['exception_class' => $exception::class]);
        } finally {
            self::$reporting = false;
        }
    }
}
