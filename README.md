# Signal Forge

Telegram signal manbalarini tahlil qilish, kanal agentlarini paper/shadow muhitida sinash va markaziy risk siyosati bilan boshqarish uchun Laravel platformasi.

Bu dastlabki ishlaydigan versiya. Telegram xabari → arxiv → structured signal → tushuntirilgan agent qarori → risk tekshiruvi → paper/shadow pozitsiya → natija → kanal statistikasi oqimi amalga oshirilgan. To‘liq konsepsiya: [docs/requirements.uz.txt](docs/requirements.uz.txt).

## Lokal ishga tushirish

Talablar: PHP 8.2+, Composer, PDO SQLite; gateway uchun Python 3.11+ va operatsion tizim keyring xizmati. Hozirgi muhitda Laravel 12 va SQLite tayyorlangan. Admin panel oddiy Blade/CSS bilan ishlaydi, Node build shart emas.

```powershell
composer install
# Yangi checkoutda .env.example ni .env ga nusxalang va:
php artisan key:generate
php artisan platform:install
./scripts/start.ps1
```

Manzil: **http://127.0.0.1:8020**. Server, database queue worker va scheduler fon rejimida ishga tushadi. Loglar: `storage/logs/{server,queue,scheduler}.{out,err}.log`. Boshqa port uchun `./scripts/start.ps1 -Port 8021` ishlating va `.env` hamda servislar `PLATFORM_URL` qiymatini moslang.

Administrator email/paroli `storage/app/private/initial-admin.txt` faylida. Umumiy standart parol yo‘q: install tasodifiy parol yaratadi, mavjud hisobni o‘zgartirmaydi. Parolni almashtirish:

```powershell
php artisan platform:password admin@local.test
```

To‘xtatish: `./scripts/stop.ps1`. Qo‘lda, uchta alohida terminalda ishga tushirish ham mumkin:

```powershell
php artisan serve --host=127.0.0.1 --port=8020
php artisan queue:work --tries=5 --timeout=120
php artisan schedule:work
```

XAMPP virtual host ishlatsangiz, DocumentRoot aynan `trade_bot/public` bo‘lishi kerak. Ilova ildizini web root sifatida bermang: `.env`, sessiyalar va lokal login fayli public katalogdan tashqarida turadi.

## Tez sinash

Lokal bazaga aniq `synthetic` deb belgilangan bitta ssenariy qo‘shish:

```powershell
php artisan platform:demo
```

Demo real bozor natijasi emas. U manual kanal, signal, paper TP1 natijasi va parallel oxirgi-TP shadow pozitsiya yaratadi. Qayta chaqirilsa yangi ssenariy qo‘shiladi; oldingi natijalar o‘chirilmaydi. `database/seeders/DatabaseSeeder.php` bu platforma uchun kerak emas; `platform:install` ishlating.

Paneldan sinash:

1. Kanallar → yangi manba → `Manual · laboratoriya`.
2. Laboratoriya → XAUUSD Bid `3376`, Ask `3376.30` yuboring.
3. 30 soniya ichida quyidagi signalni yuboring; worker uni qayta ishlaydi.
4. Signallar sahifasida score, risk va qaror sabablarini oching.
5. Bid `3385`, Ask `3385.30` yuboring: champion TP1 da yopiladi, final-TP shadow davom etadi.

```text
GOLD BUY NOW
3375-3378
SL 3370
TP1 3385
TP2 3392
```

`Close 50% and move SL to BE.` xabariga original Telegram message ID ni reply sifatida bering. Reply bo‘lmagan update `unlinked_update` bo‘lib qoladi. Manual narxlar shu instrumentdagi barcha paper/shadow pozitsiyalarni yangilaydi; alohida tajribalar uchun alohida lokal baza ishlating.

## Hozir mavjud

| Qism | Amalga oshirilgan xatti-harakat |
| --- | --- |
| Panel | Login, dashboard, kanal, signal/timeline, agent, paper/shadow trade, risk, lab, backtest, ulanishlar va audit |
| Kanal registry | Whitelist, Telegram/manual manba ajratilishi, enable/disable, kanalga champion/challenger yaratish |
| Gateway | User-account MTProto, public broadcast kanalga join, cheklangan initial history, checkpointdan keyingi catch-up, new/edit/delete/reply/caption |
| Yetkazish | Lokal SQLite outbox, Laravel durable inbox, UUID idempotency, queue retry, pending event recovery |
| Arxiv | Birinchi kuzatilgan raw text/JSON o‘zgarmaydi; barcha revision/eventlar alohida saqlanadi |
| Parser | GOLD/XAUUSD, EURUSD, GBPUSD; BUY/SELL, price/range, SL, bir nechta TP; aniq bo‘lmagan matn review talab qiladi |
| Signal update | Explicit reply, partial close, close all, break even, riskni kamaytiruvchi SL, TP, cancel |
| Qaror | Parser confidence, R/R, yetarli bo‘lsa kanal statistikasi va regime natijasi, latency; sabab/context saqlanadi |
| Paper execution | Bid/ask, konfiguratsiyadagi slippage, round-trip komissiya, lot step/min/max, SL/TP, stop gap, partial close, MFE/MAE |
| Shadow | Bir xil qabul qilingan signal uchun oxirgi TP chiqish varianti; alohida hisob, paper equityga qo‘shilmaydi |
| Risk | Trade/channel/symbol/portfolio risk, daily/weekly loss, drawdown/high-water equity, pozitsiya soni, spread/R, minimum R/R, TTL, takroriy symbol exposure, emergency stop |
| Analytics | Net R expectancy, win rate, profit factor, drawdown R, direction/session/regime guruhlari, kuzatilgan edit/delete nisbati |
| Drift | Kamida 60 yopilgan paper trade: oxirgi 30 oldingi 30 bilan solishtiriladi; drift riskni kamaytiradi, severe championni quarantine qiladi |
| Backtest | Mustaqil bid/ask tick replay, first/last TP, xarajatlar, MFE/MAE, xronologik 60/20/20 hisoboti; CSV import |
| MT5 | Alohida read-only narx feed; order execution hali mavjud emas |

Trust score kamida 20 yopilgan paper trade bo‘lganda hisoblanadi. Yetarli kuzatuv bo‘lmagan qiymatlar `—`/`INSUFFICIENT_DATA` bilan ko‘rsatiladi. Tahrir/o‘chirish soni aldovni isbotlamaydi. `profit_factor = null` namuna yoki zararlar yetishmasligini bildiradi; uni cheksiz foyda sifatida talqin qilmang.

## Telegram akkauntini ulash

```powershell
cd services/telegram-gateway
python -m venv .venv
./.venv/Scripts/python.exe -m pip install -r requirements.lock.txt
Copy-Item .env.example .env
./.venv/Scripts/python.exe gateway.py configure
./.venv/Scripts/python.exe gateway.py login
./.venv/Scripts/python.exe gateway.py run
```

`configure` terminalda `api_id`, `api_hash` va Laravel `.env` dagi `TELEGRAM_GATEWAY_TOKEN` ni so‘raydi. `login` telefon, login kodi va kerak bo‘lsa 2FA parolini so‘raydi. Bu qiymatlarni chatga yuborish kerak emas.

Gateway sirlarni OS keyring’da saqlaydi; Telegram StringSession Fernet orqali shifrlanib `var/main.session.enc` ga yoziladi. Keyring backend ishlamasa gateway ochiq matnli sessiyaga o‘tmaydi. `.env` faqat URL, account label, history limit va refresh interval uchun. Windows uchun `requirements.lock.txt` tekshirilgan; boshqa OS uchun `requirements.txt` orqali mos dependency resolve qiling.

Panelga public Telegram kanalini qo‘shing. Gateway registryni yangilab kanalga qo‘shiladi va historyni oladi. Dastlab oxirgi `HISTORY_LIMIT=5000` xabar olinadi; katta arxiv kerak bo‘lsa birinchi importdan oldin o‘zgartiring. Dastlabki import ham, reconnect catch-up ham tarixiy sifatida yuboriladi: eski signaldan joriy order ochilmaydi. Realtime handler faqat whitelist kanallarni outboxga yozadi.

`last_message_id` platformada qayta ishlangan maksimal ID. In-flight eventlar gateway outbox va Laravel inbox orqali tiklanadi. Bu mexanizm missed edits/deletesning to‘liq tarixini tiklashni kafolatlamaydi: Telegram delete eventlari doim yetib kelmaydi; birinchi importda oldin o‘chirilgan/tahrirlangan original matnni olish imkoni yo‘q. Arxivdagi `raw_text` — **birinchi kuzatilgan** matn.

Gateway 403/409/413/422 javob olgan eventni lokal outboxda `failed` qilib saqlaydi. Muammo hal qilingach `python gateway.py retry-outbox` bilan qayta navbatga qo‘shish mumkin. 202 Laravel inboxga yozilganini bildiradi; processing tugaganini emas. Navbatga yetmay qolgan pending yozuvlarni scheduler har daqiqada qayta yuboradi.

## Market data va MT5 feed

Laravel `.env` da gateway va market uchun **alohida** kamida 32 belgili tasodifiy token bo‘lishi shart. Hozirgi lokal `.env` da ular yaratilgan. Yangi checkout uchun har biriga alohida quyidagi buyruq bilan qiymat oling va maxfiy faylga joylang:

```powershell
python -c "import secrets; print(secrets.token_hex(32))"
```

`services/mt5-feed` Windowsdagi allaqachon login qilingan MT5 terminalidan faqat bid/ask o‘qiydi. Gateway MT5 credentialini bilmaydi; feed ham broker parolini saqlamaydi.

```powershell
cd services/mt5-feed
python -m venv .venv
./.venv/Scripts/python.exe -m pip install -r requirements.txt
Copy-Item .env.example .env
# .env: terminal path va broker instrument suffixlarini moslang.
./.venv/Scripts/python.exe feed.py configure
./.venv/Scripts/python.exe feed.py run
```

Feed `MARKET_DATA_TOKEN` ni OS keyring’da saqlaydi. Server vaqtini UTC yuboradi; eskirgan tickni uzatmaydi. Rejim `UNKNOWN`, ATR `null`: bu servis ularni taxminiy hisoblab to‘ldirmaydi. Session UTC soatlari bilan qo‘pol tasniflanadi; exchange holiday/DST kalendari keyingi bosqichda qo‘shiladi. MT5 integratsiyasi haqiqiy terminal/akkaunt bilan hozircha tekshirilmagan.

Instrumentlar `config/trading.php` da. Hisob valyutasi hozir USD; XAUUSD 100, EURUSD/GBPUSD 100000 contract multiplier bilan modellashtirilgan. Broker contract/tick qiymati, volume step, komissiya va slippage bilan moslash talab etiladi. Non-USD profit currency konvertatsiyasi va broker margin hisoblash hali yo‘q.

## API

Bearer tokenlar bir-birining scope’iga kira olmaydi. API tashqi tarmoqda HTTPS bilan ishlatiladi. Panel endpointlari session auth va CSRF bilan himoyalangan.

| Method | Endpoint | Scope |
| --- | --- | --- |
| GET | `/api/gateway/channels` | gateway |
| PATCH | `/api/gateway/channels/{local_id}` | gateway |
| POST | `/api/gateway/events` | gateway |
| POST | `/api/gateway/heartbeat` | gateway |
| POST | `/api/market/snapshots` | market |

Event format:

```json
{
  "event_id": "66e4587b-80cf-4ef8-9ad6-847aa47ab53e",
  "channel_id": 1,
  "telegram_id": "-1001234567890",
  "message_id": 123,
  "kind": "new",
  "text": "GOLD BUY 3375-3378 SL 3370 TP1 3385 TP2 3392",
  "raw_json": {},
  "reply_to_message_id": null,
  "posted_at": "2026-10-20T09:00:00Z",
  "occurred_at": "2026-10-20T09:00:00Z",
  "is_historical": true
}
```

Takroriy event aynan bir payload bilan kelishi kerak. Bir UUID boshqa payload uchun ishlatilsa 409 qaytadi. Edit original signal narxlarini qayta yozmaydi: signal review holatiga o‘tadi, ochiq pozitsiya dastlabki himoyasini saqlaydi. Update eski bo‘lsa yoki fresh quote bo‘lmasa, boshqaruv harakati bajarilmaydi va sabab saqlanadi.

Narx format:

```json
{
  "symbol": "XAUUSD", "bid": 3376, "ask": 3376.30,
  "atr": null, "regime": "UNKNOWN", "session": "LONDON",
  "source": "provider_feed", "market_open": true,
  "is_historical": false, "recorded_at": "2026-10-20T09:00:00Z"
}
```

Quote TTL 30 soniya, signal entry oynasi 180 soniya, kelajak vaqt toleransi 5 soniya. Range tashqarisidagi signal `WAIT_RETRACEMENT`: yangi fresh quote bilan validity oynasi ichida qayta ko‘riladi. Historical, eski, kelajakdagi yoki ketma-ketligi buzilgan narx joriy pozitsiyalarni yopmaydi. Kun/hafta risk chegaralari UTC, paneldagi vaqt UZT. Kun/hafta zarar foizi boshlang‘ich paper kapitalga nisbatan hisoblanadi; drawdown kuzatilgan equity high-water markdan hisoblanadi.

## Backtest va qayta parse

Backtest sahifasida `symbol,recorded_at,bid,ask` headerli CSV import qilinadi; misol: [docs/example-ticks.csv](docs/example-ticks.csv). Noto‘g‘ri qator butun importni rollback qiladi. Historical import joriy price oqimidan ajratilgan.

Replay signal vaqtidan oldingi narxni ishlatmaydi. Entry 180 soniyada topilmasa skip; exitga yetadigan narx bo‘lmasa `unclosed`. Stop gap kuzatilgan yomonroq narxdan, TP targetdan hisoblanadi. Tarixiy tickning yetishmasligi natijani cheklaydi. Bu OHLC simulyatori emas; MFE/MAE faqat berilgan ticklar bo‘yicha o‘lchanadi. Swap, likvidlik, partial fill va haqiqiy latency execution modeli hali mavjud emas.

Arxiv yangi parser versiyasi bilan tahlil qilinishi mumkin:

```powershell
php artisan platform:reparse 1
```

Parser o‘zgarganda `SignalParser::VERSION` yangilang. Buyruq missing version resultlarini `signal_parses` ga qo‘shadi; original signallarni o‘zgartirmaydi va trade bajarmaydi. Natijani tekshirib, migratsiya qilish alohida bosqich.

## Redis va kengaytirish

Lokal versiya tashqi Redis talab qilmaydi: database queue/cache ishlaydi. Redis o‘rnatilgach:

```dotenv
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
```

So‘ng `php artisan config:clear` va workerlarni qayta ishga tushiring. Predis Composer orqali o‘rnatilgan. SQLite uchun bitta queue worker ishlating; ko‘p worker va katta tarix uchun PostgreSQL/MySQL hamda Redisga o‘ting. Risk tekshiruvi va pozitsiya ochish bitta tranzaksiya hamda singleton risk-profile row lock ostida bajariladi. Database unique constraintlar duplicate signal/tradeni ham bloklaydi. MySQL/PostgreSQL parallel stress sinovi hali bajarilmagan.

## Keyingi bosqichlar

1. Katta history import uchun chunk progress, MTProto update-state persistence, provider ruxsat yozuvlari va direct provider feed adapteri.
2. Haqiqiy market history adapteri, ATR/trend/regime hisoblash, economic calendar va news proximity.
3. Kanal original lifecycle benchmark, slippage/latency/swap/partial-fill modeli, chronological portfolio backtest, walk-forward optimization va statistical promotion criteria.
4. Ko‘p strategiyali challengerlar, counterfactual simulation, agent versions/experiments, quarantine recovery va out-of-sample promotion.
5. Signal-source clustering, weighted consensus, USD/correlated exposure, capital allocation va meta-agent.
6. MT5 demo execution: broker contract/margin/order_check, idempotent order lifecycle, broker deals/positions reconciliation va recovery; demo acceptance’dan keyingina micro-live.

Hozirgi versiyada broker orderlari, live mode, avtomatik ML training yoki live promotion yo‘q. `learning_eligible=false` boshlang‘ich qiymat; Telegram transport arxivi keyingi AI datasetidan ajratilishi uchun saqlangan. Kelgusi ML qatlamiga faqat foydalanish huquqi qayd etilgan provider ma’lumoti kiritilishi kerak.

## Tekshirish

```powershell
php artisan test --compact
php vendor/bin/pint --test
composer audit
cd services/telegram-gateway
./.venv/Scripts/python.exe -m unittest -v test_gateway.py
```

PHP testlar alohida in-memory SQLite bazada ishlaydi. Testsiz tashqi akkauntga login qilinmaydi va broker orderi yuborilmaydi. Ixtiyoriy browser smoke uchun `scripts/verify_ui.py` Playwright va Windows Edge ishlatadi; initial credential faylini o‘qiydi, sirlarni terminalga chiqarmaydi.

Rasmiy integratsiya manbalari: [Laravel 12](https://laravel.com/framework/docs/12.x/releases), [Telethon events](https://docs.telethon.dev/en/stable/quick-references/events-reference.html), [Telethon sessions](https://docs.telethon.dev/en/stable/concepts/sessions.html), [MT5 Python integration](https://www.mql5.com/en/docs/python_metatrader5).
