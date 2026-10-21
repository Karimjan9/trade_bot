# Kanalga mos ML

Maqsad: kanalning qaysi bozor sharoitida foydali signal berishini o‘rganish, yangi signalni oldindan baholash va keyingi natijalar bilan modelni yangilash. “Maksimal foyda”ni oldindan kafolatlaydigan model yo‘q; tanlangan model aniq keyingi davrda filtrsiz rejaga nisbatan yaxshilangan natija ko‘rsatishi talab qilinadi. Model muallifning ichki sabablarini bilmaydi: trend, breakout, qarshi trend va sessiya bilan kuzatilgan bog‘liqlikni ko‘rsatadi.

## Signal kelganda

1. Original matn/JSON va revisionlar avvalgidek saqlanadi. Missing SL/TP uchun o‘sha vaqtda mavjud kanal siyosati qo‘llanadi.
2. Entry, SL/TP, profit-lock, vaqt chegarasi, bid/ask va xarajatlar rejasi muzlatiladi. Tugallanmagan sham, kelajak narxi yoki keyingi “150 pips” posti feature bo‘lmaydi.
3. Faqat yopilgan minutlardan trend 5/15/30, RSI, ATRga nisbatan stop, diapazon/breakout, spread/R, R/R, yo‘nalish, sessiya va latency hisoblanadi. Oxirgi 15 minut to‘liq bo‘lmasa faol filtr xulosa bermaydi.
4. Kanalning tekshirilgan modeli foydali yakun ehtimoli va kutiladigan net R ni hisoblaydi. Sabab sifatida asosiy omillarning modeldagi hissasi saqlanadi. Bu omillar sabab-oqibat isboti emas.
5. Faol model past ehtimol yoki manfiy kutiladigan natija berganda `ML_LOW_EXPECTED_QUALITY` bilan signal rad etiladi. Noma’lum instrument/yo‘nalish/reja oilasi, tarqatishdan uzoq feature, eskirgan model yoki kontekst yetishmasligi `ABSTAIN` beradi: odatiy paper qoidalari davom etadi.
6. ML risk limitlarini oshirmaydi. Emergency stop, hajm, spread, exposure va boshqa cheklovlar mustaqil ishlaydi.

## Natijadan o‘rganish

Hisobda order ochilmagan yoki ML rad etgan signal ham kuzatiladi. `OutcomeLabeler` muzlatilgan rejani keyingi ticklarda mustaqil qayta ijro qiladi. Bid/ask, slippage, komissiya, stop gap, target, bosqichli SL va vaqt bo‘yicha exit hisoblanadi. Kanalning natija haqidagi posti label hisoblanmaydi. Bu counterfactual net R; broker fill yoki hisobdagi foyda deb ko‘rsatilmaydi. Explicit provider update/save xabarlari bu muzlatilgan reja labeliga orqadan qo‘shilmaydi.

Stop/target yoki vaqt exitgacha 30 sekunddan katta quote bo‘shlig‘i bo‘lsa `DATA_GAP`; entry topilmasa `NO_ENTRY`; reja yaroqsiz bo‘lsa `INVALID_PLAN`. Ular o‘qitishda yutqazilgan signal sifatida sanalmaydi. Hali tugamagan natija `PENDING`. COMPLETE label va feature keyingi claim/tahrir bilan qayta yozilmaydi.

Tarixiy backfill asl maydonlardan signal vaqtidagi quote bilan quriladi. Keyinroq yaratilgan siyosat eski missing SL/TP’ni to‘ldirmaydi. Birinchi kuzatuvdan oldin tahrir qilinganligi ma’lum xabar ishlatilmaydi. Tarixiy replay alohida belgilanadi; tarixiy signal joriy pozitsiya ochmaydi.

Kanalning boshlang‘ich SL/target siyosati statistik replay orqali, signal sifati esa NumPy asosidagi regularizatsiyali logistika regressiyasi va Ridge bilan o‘rganiladi. Logistika uchun L2 penalty va Newton optimizatsiyasi, Ridge uchun regularizatsiyali chiziqli yechim ishlatiladi; yaqinlashmagan model faollashmaydi. Formulalar `services/ml/linear.py` ichida. Bu birinchi supervised ML avlodi; neural/vision model va reinforcement learning hali yo‘q. Model har safar yangi yakunlangan misollar bilan qayta o‘qitilib, alohida versiya bo‘ladi. O‘zini nazoratsiz o‘zgartiradigan faol model ishlatilmaydi.

## O‘qitish va faollashuv

Standart minimum: 60 train, 20 calibration va 20 test, train/calibrationda ikki natija sinfidan kamida 5 tadan. Vaqt bo‘yicha 60/20/20 ajratiladi; label oynasi keyingi bo‘lim boshlanishiga tegib ketgan misollar chiqariladi, shuning uchun amalda 100 tadan ko‘p signal kerak bo‘lishi mumkin. Median va scale faqat train bo‘limidan olinadi. Ehtimol kalibratsiyasi va filtr threshold tanlash faqat calibration bo‘limida bajariladi. Oxirgi test bu tanlovlarga aralashmaydi.

O‘tkazib yuborilgan foydali signal ham hisobga olinadi: asosiy mezon barcha imkoniyatlarga nisbatan net R (`SKIP=0`), faqat tanlanganlar win rate’i emas. Testda kamida 10 tanlangan signal, musbat umumiy R, filtrsizdan kamida 0.01 R/imkoniyat yaxshilanish, kattaroq bo‘lmagan pasayish va prevalence baseline’dan yaxshi Brier kerak. Natijalar hisobdagi parallel exposure yoki broker ijrosini simulyatsiya qilmaydi; drawdown ham shu reja R ketma-ketligiga tegishli.

Sinovdan o‘tgan model `ACTIVE_PAPER`; dalili kam model `INSUFFICIENT_DATA`; natijasi o‘tmagan model `SHADOW_ONLY`. Mavjud faol model o‘rniga challenger faqat shu model o‘qitilmagan yangi test davrida ayni signallarda yaxshiroq ishlasa o‘tadi. Faol modelning dalili 7 kundan eski bo‘lsa filtr ishlamaydi. Yangilangan dataset/config/trainer hash yangi versiya yaratadi, bir xil input takroriy model yaratmaydi.

Yangi real vaqtdagi 30 kuzatuv oynasida faol modelning tanlangan natijasi manfiylashsa yoki filtrsizdan yomonlashsa `QUARANTINED` bo‘ladi; avvalgi qoidalarga qaytiladi. Bu ham xarajatli signal va rad etilgan foydali signalni birga hisoblaydi. Keyingi mustaqil dalil bo‘lmaguncha eski model avtomatik qayta yoqilmaydi.

## Qarama-qarshi kanallar va vaqt bo‘yicha farq

`DecisionEngine` bir instrumentdagi amaldagi signallarni bitta tranzaksiyada baholaydi. Dastlabki 3 sekund yig‘ish oynasi kanal xabarlari ketma-ket kelganda birinchisi avtomatik yutib ketishini kamaytiradi. Queue’dagi `ReconsiderSymbol` oynadan keyin yana tekshiradi; yangi fresh quote ham qayta baholaydi. Bu kelajakda hali kelmagan signalni bilish imkonini bermaydi.

Faqat entry oynasi ichidagi, cancel/review/historical bo‘lmagan, risk va model filtridan o‘tgan signallar tanlovda qatnashadi. BUY va SELL o‘rtasida tasdiqlangan faol ML bahosi bo‘lmasa `OPPOSING_SIGNALS_NEED_MODEL_EVIDENCE` bilan kutadi. Tasdiqlangan bahosi bor nomzodlar net R, oldingi shu instrument/yo‘nalish/reja/davr bo‘yicha qarshi yurishning 80-persentili va pozitsiya davomiyligining medianasi bilan taqqoslanadi. Tarix 20 tadan kam bo‘lsa 1R qarshi yurish va to‘liq reja davri ehtiyotkor fallback bo‘ladi. Muallifning pips da’volari bu taqqoslashga kirmaydi.

Birinchi umumiy siyosat: `utility_R = ML_expected_net_R − 0.15 × adverse_R − 0.02 × holding_hours`. Eng yaxshi yo‘nalish musbat utility va qarama-qarshi eng yaxshi nomzoddan kamida 0.1R ustunlik ko‘rsatishi kerak. Aks holda WAIT. Bu vaqt/risk jarimalari konfiguratsiyadagi dastlabki qoidalar; umumiy portfelni optimal boshqarishni o‘rganib bo‘ldi degan da’vo emas. Bir xil yo‘nalishdagi ko‘p kanal mustaqil ovoz hisoblanmaydi va lot ko‘paytirilmaydi.

Bir instrumentda faqat bitta paper pozitsiya ochiladi, BUY/SELL birga ochilmaydi. Ochiq pozitsiya bo‘lsa yangi signal kuzatiladi, lekin avtomatik close/reverse bo‘lmaydi. Eski pozitsiya o‘z SL/TP/save/vaqt rejasi bo‘yicha yopilgach, boshqa signal hali amal qilsa fresh quote, entry va barcha risklar yana tekshiriladi. Signaldagi qarorlar tarixi barcha nomzod baholari, tanlangan signal va ushlab turilgan trade’ni saqlaydi.

`SignalTrajectory` signaldan keyingi narx yo‘lini trade yopilgandan keyin ham bir xil 60 minut davrda alohida o‘lchaydi. GOLD uchun 50/100/150 pipsga birinchi yetgan vaqt, ungacha maksimal qarshi yurish va dastlabki SL oldin urilganligi qayd qilinadi. 5/15/60 minutdagi kuzatilgan narx natijasi ham saqlanadi. Harakat natijasi, muzlatilgan reja counterfactual net R’i va haqiqiy yopilgan paper P/L alohida qiymatlar. 30 sekunddan katta narx bo‘shlig‘i `DATA_GAP`; yetishmagan oraliq taxmin qilinmaydi. Keyingi narx va claim oldingi tanlovga yozilmaydi.

Misol: SELL avval +50 pips yurib keyin qaytadi, BUY esa avval −50 pips va keyin +100 pips yuradi. SELL’da +50 da haqiqiy exit/partial bo‘lsa foyda shu yopilgan ulushga tegishli; exit bo‘lmasa bu faqat ko‘rilgan ochiq foyda. BUY’ning SL’i −30 da urilgan bo‘lsa keyingi +100 signal foydasi hisoblanmaydi. SL qoidasida qarshi yurishga joy bo‘lib targetga yetgan bo‘lsa BUY rejasining natijasi ham foydali bo‘lishi mumkin. Qaysi variantni haqiqiy hisobda tanlash esa oldindan mavjud dalillar va bitta pozitsiya chekloviga qarab bajariladi.

`ml_observations.path_metrics` va signal sahifasi batafsil yo‘lni, kanal sahifasi esa instrument/yo‘nalish/davr/pip birligi kesimida +50/+100, median qarshi yurish va vaqtni ko‘rsatadi. +100 ga yetmaganlar denominator’dan olib tashlanmaydi; qarshi yurish medianasi esa aynan yetgan kichik guruhga tegishli ekani ko‘rsatiladi. Eski, pip/trajectory rejasi muzlatilmagan kuzatuvlar uchun tarix orqadan ixtiro qilinmaydi.

## Har kanalga individual yondashuv va foydasiz kanallar

`ChannelStrategyLearner` kanal/instrument/yo‘nalish bo‘yicha alohida siyosat tadqiq qiladi. Asosiy 7 chiqish varianti: provider birinchi TP, oxirgi TP, 50 pips, 100 pips, 50 pips qadamli SL, 50 pipsda 50% yopish + qolganini himoyalash, va 5 minutda vaqt bo‘yicha chiqish. Yangi muzlatilgan kuzatuvlarda quyidagi kirish bo‘limida yozilgan 34 kombinatsiya ishlaydi. Yetishmagan SL avvalgi kanal siyosatidan olinadi; provider originali saqlanadi. Variantlarni tanlash statistik replay; signal sifati esa yuqorida yozilgan supervised ML qatlamida baholanadi.

Har variant bir xil kirish kuzatuvi, bir xil SL, bid/ask va muzlatilgan xarajatlar bilan tekshiriladi. Partial, stop gap, foyda himoyasi va vaqt exit real paper ijrosidagi tartibda hisoblanadi. `save_50` tanlansa paper’da 50 pipsdan keyin yarim pozitsiya bir marta yopiladi va SL entryga ko‘chadi; keyingi bosqichlarda SL foydani himoya qiladi. Counterfactual label ham shu ulush va xarajatlarni hisoblaydi. Bu brokerda lot bo‘linishi tekshirilganini anglatmaydi: MT5 execution hali yo‘q.

Default minimum 30 train, 10 validation, 10 test; bo‘limlar 60/20/20. Standart holatda kamida 50 ta yetarli narx qamroviga ega mustaqil kuzatuv kerak. 20 000 tagacha scope kuzatuvi ichidan eng ko‘pi 200 ta ustma-ust tushmaydigan oyna olinadi; juda faol kanalning ketma-ket nusxa signallari ishonchli mustaqil dalil deb sanalmaydi. Davr chegaralaridagi ustma-ust label oynalari ham chiqariladi. Parametr eng katta train net R bo‘yicha tanlanadi; validation va test musbat bo‘lishi, testdagi pasayish birinchi TP baseline’dan katta bo‘lmasligi va natija baseline’dan kamida 0.05R yaxshilanishi kerak. Aks holda oldindan belgilangan birinchi TP baseline faqat o‘z validation/test natijasi musbat bo‘lsa saqlanadi. Testdagi boshqa eng yaxshi variant orqadan tanlanmaydi.

Yetarli dalil yo‘q — `INSUFFICIENT_DATA`. Variant o‘tmagan va baseline ham foydali bo‘lmagan — `SHADOW_ONLY`, scope yangi order ochmaydi. Sinalgan barcha usullarning validation va test expectancy’si −0.05R yoki undan yomon bo‘lsa, testda kamida 20 ta mustaqil kuzatuv hamda 5 xil kun talab qilinadi; shundagina `UNPROFITABLE`. Bu kamida taxminan 100 ta to‘liq misolni talab qiladi. Yolg‘iz video, edit/delete, past win rate yoki ma’lumot yo‘qligi kanalni foydasiz deb chiqarishga yetmaydi.

`telegram_channels.enabled` transport monitoringini, `trade_state` savdoga yaroqlilikni bildiradi. Foydasiz deb topish monitoringni o‘chirmaydi. `SELECTIVE` kanalning ayrim yo‘nalishlari cheklanganini, `EXCLUDED` barcha kuzatilgan yo‘nalishlar sinalgan usullar bilan zararli chiqqanini bildiradi. `MONITOR_ONLY` hali foydali usul tasdiqlanmagan tekshirilgan kanal uchun savdo pauzasidir. Eski boshlang‘ich paper kuzatuvi `PAPER_BASELINE` sifatida ochiq belgilanadi. ML va tadqiqot uchun Telegram `learning_eligible` talabi saqlanadi.

Yangi signallar bloklangan kanal uchun ham original, feature, narx yo‘li va counterfactual natija saqlanadi. Ochiq trade avtomatik buzilmaydi; uning avvalgi rejasi boshqariladi. Savdoga qaytish uchun chiqarilgan vaqtdan keyin boshlangan yangi mustaqil test musbat bo‘lishi kerak. Eski foydali davrni qayta ishlash kanalni qayta yoqmaydi (`RECOVERY_SHADOW`). Har siyosat versiyasi va holat o‘zgarishi auditga yoziladi. Yangi usul faqat dalili signal vaqtidan oldin tugagan kelgusi signallarga tatbiq etiladi; muddati tugagan siyosat bilan yangi order ochilmaydi.

Bu dastlabki variantlar oilasi. Cheklanmagan entry qidiruvi, news filtrlari, boshqa partial ulushlari, to‘liq portfolio walk-forward optimallashtirish va broker ijrosi hali to‘liq tadqiq qilinmagan. “Foydasiz” xulosasi sinalgan usullar va kuzatilgan davr bilan chegaralangan. Barcha mumkin bo‘lgan yondashuvlar ichidagi mutlaq maksimum topildi deb ko‘rsatilmaydi.

Kanal sahifasi individual holat, har variantning train/validation/test net R va pasayishini ko‘rsatadi. ML navbati har yangilanishda mavjud scope’larni, soatlik `platform:research-channels` ham exit tadqiqotini hisoblaydi. Bir xil ma’lumot va qoidalar takroriy versiya yaratmaydi. O‘sha paytda qo‘llangan exit key’i va siyosat ID’si paper trade’da saqlanadi.

## Video postlari

Gateway faqat video/round-video metadatasini qayd qiladi: `video_content_skipped=true`, `downloaded=false`. Video faylini ochish, download, frame extraction, audio transkripsiya yoki videoni modelga yuborish yo‘q. Backend ham video metadata bilan kelgan bo‘sh postni `SKIPPED_VIDEO` qilib saqlaydi va review/signal yaratmaydi. Yozma caption bo‘lsa faqat o‘sha matn oddiy parserga kiradi. Video soni kanal foydasizligi yoki foydaliligiga dalil bo‘lmaydi.

## Kirish zonasi, qismlar va kanal riski

Yangi `entry_research` rejasi muzlatilgan kuzatuvlar uchun `EntryPlans` 34 oldindan belgilangan kombinatsiyani tekshiradi. Avvalgi 7 exit’ga qo‘shimcha: zona o‘rtasi, ichkari chegarasi, dastlabki narxdan 50 pips qaytish, 3 qismli kirish hamda 60/90 pips SL variantlari birinchi/oxirgi TP yoki foyda himoyasi bilan taqqoslanadi. Narxning zona tashqarisidagi qismidan kirish olinmaydi. Masalan, 50 pips kutish nuqtasi provider zonasidan tashqarida bo‘lsa bu usul kirmaydi. Qadamlar va SL keyingi natijani ko‘rib shu signalga moslab ixtiro qilinmaydi.

`EntryResearch` signal uchun umumiy **entry deadline + research horizon** oynasini, boshidan oxirigacha maksimal 30 sekund bo‘shliq talabini tekshiradi. Bir xil to‘liq oynada barcha variantlar hisoblanadi; zonaga kelmagan usul 0 R hisoblanadi, denominator’dan chiqarilmaydi. To‘liq oyna bo‘lmasa xulosa yo‘q. Standart entry deadline signal vaqtidan 180 sekund; avtomatik abadiy kutish yo‘q. Bir oynaga 20 000 tick chegarasi bor: bundan oshgan yozuv yupqalashtirilmasdan tadqiqotdan qoldiriladi. Eski muzlatilgan rejalar o‘zgartirilmaydi; ular yangi 34 variantli tanlovga qo‘shilmaydi.

Signal sahifasidagi “Qaysi kirish usuli foydali bo‘lardi?” jadvali keyingi haqiqiy kuzatilgan bid/ask bilan bajarilgan **hypothetical** replay. Standart tadqiqot budjeti $100; hajm qadami, min/max lot, spread, komissiya va kirish/SL slippage hisobga olinadi. Bu account’da amalga oshgan foyda emas. −50 pipsdan keyin +250 pips bo‘lsa, dastlabki 30 pips SL urilgan variant zarar bo‘lib qoladi. Oldindan tanlangan 60 pips SL yoki zona ichidagi boshqa kirish varianti alohida foydali chiqishi mumkin. +250 milestone yangi narx yo‘li rejalarida ham saqlanadi.

Kanal darajasida 34 variant ayni mustaqil, ustma-ust tushmagan kuzatuvlarda, avvalgi 60/20/20 tartibida sinovdan o‘tadi. Eng yaxshi variant faqat train’dan olinadi; validation/test yuqoridagi foyda va drawdown talablarini tekshiradi. Bir signalning keyin eng yaxshi chiqqan usuli keyingi signal uchun avtomatik qoida bo‘lmaydi. Usulga mos yangilangan supervised ML uchun ham yangi family kuzatuvlari kerak; mos dalil bo‘lmagan eski model abstain qiladi. Bu chekli variantlar orasidagi statistik tanlov; mumkin bo‘lgan barcha usullar maksimumi yoki portfolio walk-forward isboti emas.

`ladder_3` zonaning yaqin chegarasi / o‘rtasi / ichkari chegarasi bo‘yicha **uchta teng risk ulushi** ajratadi. Lot teng bo‘lishi shart emas: umumiy SL’gacha masofa va xarajatga qarab har qism hajmi oldindan hisoblanadi. Jami rejalashtirilgan zarar bitta signal budjetidan oshmaydi. Min lotga yetmagan qism bo‘lsa budjet oshirilmaydi, usul ochilmaydi. Narx bir nechta darajadan o‘tib allaqachon zonada bo‘lsa tegishli qismlar bir kuzatilgan narxda to‘lishi mumkin; limitga tegish taxminiy yaxshiroq fill bermaydi.

Paper hisobida barcha qismlar bitta net pozitsiya, bitta yo‘nalish va bitta umumiy SL. To‘lgan hajm va o‘rtacha entry yangilanadi, har yangi qism komissiyasi shu vaqtda realization event’iga yoziladi. Pending qismlarning riski portfel va kanal limitida oldindan band qilinadi. SL, TP, vaqt exit, save/partial, foydani himoyalash yoki entry oynasi tugashi pending qismlarni bekor qiladi. Har qo‘shishdan oldin emergency, kanal holati, yangi policy/drift, zarar/pasayish, spread va jami risk qayta tekshiriladi. Kattaroq yo‘qotishdan keyin lot ko‘paytirish yoki ochiq savdoning SL’ini kengaytirish yo‘q. Narx gap’i va haqiqiy broker ijrosi rejalashtirilgan riskdan farq qilishi mumkin.

Kanal sahifasida besh mustaqil yuqori chegara bor: bitta signal riski, kanal jami ochiq riski, kunlik zarar, haftalik zarar va pasayish. Bo‘sh qiymat markaziy limitni oladi; kanal uni oshira olmaydi. Kun/hafta UTC, zararlar boshlang‘ich paper kapitalga nisbatan. Kanal drawdown’i realized ledger’dagi cho‘qqi va joriy manfiy/floating P/L bilan hisoblanadi; tarixiy intratrade floating cho‘qqilar alohida tiklanmaydi. Parametr o‘zgarishi auditga yoziladi. Train’dan tanlangan usul risk multiplikatori `min(1, 3 / max(1, train_drawdown_R))` bilan faqat riskni kamaytirishi mumkin; joriy kanal/global cap hamon yuqori chegara.

Bu broker orderlarini bo‘lib yuborish emas: haqiqiy MT5 demo execution hali mavjud emas. `entry_legs` paper pozitsiyaning muzlatilgan rejalari va fill tarixini, `entry_experiments` esa signal bo‘yicha versiyalangan taqqoslashni saqlaydi. Portfolio cheklovlaridan mustaqil replay bilan haqiqiy tanlangan paper yo‘li farq qilishi mumkin; counterfactual natija realized hisobga qo‘shilmaydi.

## Monitoring savollari va xatolar jurnali

Panel → **Savollar va xatolar** (`/issues`) savol, xato va kuzatilgan tafovutlarni alohida bazada saqlaydi. Kanal, signal, original xabar, vaqt (UZT), birinchi/oxirgi dalil va qaytalanish soni ko‘rsatiladi. ID orqali birga muhokama qilish mumkin: masalan, “jurnaldagi #12 ni ko‘ramiz”. Qo‘lda savol yoki izoh qo‘shish ham mumkin.

Avtomatik manbalar: parser review, ulushi ko‘rsatilmagan save, reply orqali bog‘lanmagan update yoki natija, claim shortfall, tekshirish uchun narx/birlik/kirish yetishmasligi, hali ulanmagan rasm o‘qish, ML karantini, zararli chiqqan kanal usullari, tugamagan queue vazifalari, gateway kanal ERROR/servis DEGRADED va ilovaning reportable xatolari. Birinchi konfiguratsiya qilinmagan servis yoki oddiy WAIT, SL/TP bo‘yicha dastlabki ma’lumot yetishmasligi, shovqin matni va bo‘sh video xato deb jurnalni to‘ldirmaydi. Narx qamrovi yo‘qligi kanal yolg‘on gapirdi degan xulosa emas.

Holatlar: `OPEN`, `IN_PROGRESS`, `WAITING_USER`, `RESOLVED`, `DISMISSED`. Yopishda yechim yoki sabab yozish shart. Izoh va holat o‘zgarishlari o‘chirish/tahrirlash endpointisiz tarixga qo‘shiladi. Parallel ko‘rib chiqishda eskirgan sahifa yangi dalilni yopib yubormasligi uchun versiya tekshiriladi. Bir xil event/check/job qayta tekshirilsa son oshmaydi va yopilgan yozuv qayta ochilmaydi; yangi hodisa yoki yangi claim dalili versiyasi kelganda shu turdagi masala qayta ochilishi mumkin. Uzluksiz gateway nosozligi tiklanguncha bitta hodisa hisoblanadi. Avtomatik tiklanish journal masalasini jim yopmaydi: yechim foydalanuvchi bilan qayd etiladi.

Jurnal yozuvi risk, signal linkage, save ulushi yoki savdo siyosatini o‘zidan-o‘zi o‘zgartirmaydi. Kelishilgan qoida kod/sozlama sifatida alohida amalga oshiriladi va sinaladi. Avtomatik dalillarga queue payload, credential, xatoning to‘liq matni/stack trace yoki xom log ko‘chirilmaydi; faqat cheklangan diagnostika va ID saqlanadi. Qo‘lda izohlarga maxfiy qiymat kiritmang.

Scheduler `platform:collect-issues` ni har daqiqada ishlatib `failed_jobs` va mavjud ERROR/DEGRADED holatlarni qayta yig‘adi. Ilova to‘liq o‘chiq yoki DB ishlamayotgan vaqtda jurnalga yozishning imkoni yo‘q; durable queue xatolari keyin tiklanadi. Gateway’dan umuman yetib kelmagan xato markaziy jurnalga tushmaydi, uning lokal log/outbox’ini tekshirish kerak. Bu suhbatdagi agentning mustaqil 24/7 kuzatuvi emas: servislar ishlaganda dastur qayd qiladi, keyingi suhbatda yozuvlarni birga ko‘rib chiqamiz.

## Ishga tushirish

```powershell
python -m venv services/ml/.venv
./services/ml/.venv/Scripts/python.exe -m pip install -r services/ml/requirements.lock.txt
php artisan migrate --force
php artisan platform:learn-models CHANNEL_ID --sync
./services/ml/.venv/Scripts/python.exe -m unittest discover -s services/ml -v
```

`requirements.lock.txt` Windows/Python 3.13 muhitida tekshiriladi. Boshqa platforma uchun `requirements.txt` bilan resolve qiling. `ML_PYTHON` interpreter yo‘lini o‘zgartiradi. Python mahalliy JSON bilan ishlaydi; pickle, tashqi model endpointi yoki hisob paroli kerak emas. Dataset vaqtinchalik `storage/app/private/ml` ichida turadi va process tugagach o‘chiriladi; model koeffitsiyentlari SQLite’da versiyalanadi.

Scheduler har 5 minutda `platform:learn-models` orqali `ml` navbatiga kanal job’ini yuboradi. Shu kanal uchun takroriy job cheklanadi. Alohida `ml` worker matnli signallar navbatini band qilmaydi. `scripts/start.ps1` ikkalasini ishga tushiradi. Queue `retry_after=240`, ML job timeout 180 sekund; uzilgan tahlil uchun jami 2 urinish bor. Sozlamani o‘zgartirgach workerlarni qayta ishga tushiring.

Telegram kanalning `learning_eligible` sozlamasi kanal sahifasida yoqiladi; o‘chirilganda shu kanalning ML filtri va o‘qitishi ishlamaydi. Manual laboratoriya ma’lumoti paper sinov uchun ruxsatli deb olinadi. Real kanalga mos model uchun original signal tarixi va ayni instrumentning yetarli bid/ask tarixi kerak. Hozir broker execution va rasm/grafik parseri yo‘qligi sababli ular tayyor deb ko‘rsatilmaydi.

Asosiy fayllar: `app/Services/Learning`, `services/ml/train.py`, `LearnChannelModel` job’i, `platform:learn-models` buyrug‘i va kanal sahifasi.

Rasmiy usul manbalari: [scikit-learn: leakage va preprocessing](https://scikit-learn.org/stable/common_pitfalls.html), [vaqt bo‘yicha tekshirish](https://scikit-learn.org/stable/modules/cross_validation.html), [ehtimol kalibratsiyasi](https://scikit-learn.org/stable/modules/calibration.html).
