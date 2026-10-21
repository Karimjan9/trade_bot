# Kanal dalillari va strategiya tadqiqoti

Bu talablar 2026-10-20 dagi aniqlashtirishlarni saqlaydi. Maqsad: qaysi kanalning qaysi signallarini, qanday entry/exit va risk siyosati bilan ishlash foydali ekanini xarajatlar hamda zarar chegaralarini hisobga olib tekshirish. Doimiy foyda va’da qilinmaydi; xulosa kelajak ma’lumotini ishlatmaydigan testlar va demo natijalari bilan asoslanadi.

## Signal manbasi va to‘liqligi

| Holat | Saqlanadigan dalil | Tadqiqot va execution qoidasi |
| --- | --- | --- |
| To‘liq matn | Original matn, narxlar, vaqt, kanal/message ID | Maydonlar va risk tekshiruvidan o‘tadi |
| Rasm ichidagi yozuv | Original rasm, caption, hash, model versiyasi, ajratilgan maydonlar va dalil joylashuvi | Vision/OCR qo‘shilgach; original har bir revision uchun saqlanadi |
| Grafik ustida entry/SL/TP chiziqlari | To‘liq grafik, narx o‘qi, instrument, yo‘nalish, belgilar va ularning koordinatalari | Instrument, yo‘nalish va entry ajratiladi. SL/TP yo‘qligi rad etish sababi emas: ularni kanal agenti tarixdan hisoblaydi |
| Entry bor, TP yo‘q | Provider TP yo‘qligi va agent target/exit siyosati alohida saqlanadi | Kanalning tarixiy yurishidan target bosqichlari, foydani himoyalash qadami va vaqt bo‘yicha exit hisoblanadi |
| Entry bor, SL yo‘q | Provider SL yo‘qligi va agent SL manbasi | Kanalga xos normal qarshi yurish zonasi tarixdan hisoblanib, lot risk limitiga moslanadi |
| SL ham, TP ham yo‘q | Provider entry/yo‘nalish va versiyalangan agent darajalari | O‘rganilgan kanal siyosati ikkala maydonni ham to‘ldiradi; statistik himoya bo‘lmagan ilk kuzatuvlar o‘rganish uchun yig‘iladi |

Yetishmagan qiymatni 0 bilan to‘ldirmaslik kerak. Agent SL/TP’ni ma’lumotdan hisoblaydi: bu kanal bermagan narxni kanal bergandek yozish degani emas. Kanal bergan qiymat bilan agent hisoblagan qiymat alohida saqlanadi. Xira rasm sabab instrument/yo‘nalish/entry aniqlanmasa tekshiruv talab qiladi; SL/TP’ning shunchaki berilmagani esa kanal siyosati bilan hal qilinadi.

Foydalanuvchi tasdiqlagan GOLD birligi: **100 pips = 3300 → 3310 = narxda 10.0; 1 pip = 0.1.** Bu yerda aytilgan “punkt” brokerning `SYMBOL_POINT` maydoni bilan avtomatik tenglashtirilmaydi. Kanal/instrument sozlamasi boshqa bo‘lsa alohida yoziladi.

## Kanal tarixidan avtomatik darajalar

`channel_policies` har bir kanal/instrument/yo‘nalish uchun versiyalangan natijani saqlaydi. Dastlabki statistik o‘rganish:

1. Original xabarlardagi entry bilan to‘liq qamrab olingan tarixiy bid/ask yo‘llari moslanadi. Keyin o‘chirilgan yoki keyin tahrirlangan xabarning kuzatilgan originali ham hisobga kiradi. Birinchi kuzatuvdan oldin tahrirlangan raw tarix kelajak ma’lumoti aralashmasligi uchun chiqariladi.
2. Oldingi davrda qarshi va foydali yurishlardan SL hamda target nomzodlari hisoblanadi. Masalan, SL 30 pips yoki target 100 pips chiqishi mumkin; bu qiymatlar hamma kanalga bir xil berilmaydi.
3. Komissiya, bid/ask va slippage bilan rolling profit-lock replay qilinadi. Nomzod faqat train davrida tanlanadi; keyingi validation davri tanlashda ishlatilmaydi. Train/validation vaqt oynalari ustma-ust bo‘lsa train yozuvlari purge qilinadi.
4. Standart minimum 20 train / 10 validation kuzatuv. Ikkala davr expectancy’si ijobiy bo‘lsa faqat `PAPER_ELIGIBLE`; yomon holdout `REJECTED`, kam yoki bo‘shliqli tarix `INSUFFICIENT_DATA` bo‘ladi. Bu holatlar saqlanadi.
5. Yangi signalga faqat undan oldin tugagan dalillarga asoslangan, muddati tugamagan siyosat qo‘llanadi. Hisoblangan SL/target va manbasi qaror hamda trade’da saqlanadi; provider maydonlari qayta yozilmaydi. Risk engine yakuniy lot va hard limitlarni tekshiradi.

Bu birinchi avlod statistik o‘rganishdir; vision yoki neural ML modeli hali emas. Siyosat mustaqil signal replay’iga asoslanadi, portfel stress-testi va live promotion hisoblanmaydi. Narx manbai va hisoblash xarajatlari brokerga mos bo‘lishi kerak. Scheduler soatiga qayta hisoblaydi; qo‘lda: `php artisan platform:research-channels CHANNEL_ID`.

## Save semantikasi

Foydalanuvchi tasdiqlagan ma’no: pozitsiyaning bir qismini foydada yopish va qolgan qismning SL’ini entry narxiga ko‘chirish.

- `Save 25%`: mavjud ochiq qoldiqning 25% ini yopish va qolganini BE’ga ko‘chirish. Fresh quote, foyda va stopning amaldagi narxga mosligi tekshiriladi.
- `Save now`: yopish ulushi aytilmagan. Hozir review; keyinchalik aynan shu kanal uchun tasdiqlangan, versiyalangan default ulush bo‘lishi mumkin.
- `Close 50% and move SL to BE`: explicit reply orqali mavjud signalga ulanadi.
- Qaysi signalga tegishliligi noaniq bo‘lsa, tizim boshqa ochiq pozitsiyani taxmin bilan boshqarmaydi.

Entry narxidagi BE komissiya/slippage hisobiga mutlaq zararsizlikni bildirmaydi. Xarajatlarni qoplaydigan stop alohida siyosat bo‘lishi kerak; providerning BE ma’nosi o‘zgartirib yuborilmaydi.

**Foydalanuvchi aytgan “bez ubitka” profit-lock’ni ham anglatadi.** Bu save’dan mustaqil: partial close bo‘lmasa ham SL foydaga ko‘chishi mumkin. Masalan, o‘rganilgan target 100 va qadam 50 pips bo‘lsa: +50 da SL entry, +100 da SL +50, +150 da SL +100. BUY va SELL uchun yo‘nalish teskari hisoblanadi. Oldingi SL hech qachon bo‘shatilmaydi; gap/slippage haqiqiy fill’ni belgilangan stopdan farqlashi mumkin.

`Bez ubitka TP1`, `Bez ubitka 50 pips`, `Lock 50 pips` explicit reply orqali qaysi foyda narxini himoyalashni bildiradi. Darajasiz `Bez ubitka` trade ochilganda saqlangan kanal siyosatining qadamini ishlatadi. Kanal TP bermagan trade’da hisoblangan target majburiy yopish emas, yurish bosqichidir: himoya ko‘tarilib boradi, exit stop yoki siyosatning vaqt oynasi bilan amalga oshadi.

## Natija da’vosi va haqiqiy natija

Uchta turdagi qiymat mustaqil saqlanadi:

1. **Provider claim:** kanalning `150 pips`, `TP hit`, `profit booked` yozuvi va uni yuborgan vaqt. Boshlang‘ich tasdiq `UNVERIFIED`.
2. **Market evidence:** signal olinganidan keyingi bid/ask yo‘li, mumkin bo‘lgan entry, TP/SL hodisalari, MFE/MAE va ma’lumot bo‘shliqlari.
3. **Execution result:** paper yoki MT5 demo hisobida haqiqiy ochilgan/yopilgan volume, fill narxlari, komissiya va net natija.

Narx bir payt 150 pips foyda tomoniga borgan bo‘lishi, pozitsiya shu foydada yopilganini isbotlamaydi. Avval stop urilib, keyin targetga borish win deb hisoblanmaydi. Keyin yuborilgan foyda yozuvi oldingi vaqtga qaytarilib, entry/exit qarorida ishlatilmaydi.

Pips, broker point va tick bir xil birlik deb olinmaydi. Providerning pip talqini, broker tick size/value va instrument spetsifikatsiyasi aniqlanmaguncha claim pulga aylantirilmaydi. Natijalar net USD va, risk oldindan belgilangan bo‘lsa, net R bilan taqqoslanadi.

Claim signal entry’sidan claim yuborilgan vaqtgacha bo‘lgan tarix bilan tekshiriladi. Masalan: **claimed 150 / observed 130 / shortfall 20 pips**. Boshlang‘ich reference birinchi entry diapazoniga mos kelgan executable quote; BUY uchun keyingi bid, SELL uchun ask harakati hisoblanadi. Claim’dan keyingi ko‘tarilish orqaga qo‘shilmaydi. Qamrov yaxshi bo‘lsa `OBSERVED_SHORTFALL`; ticklar orasida katta bo‘shliq bo‘lsa `DATA_GAP`, hatto kuzatilgan maksimum 130 bo‘lsa ham, yakuniy yolg‘on hukmi chiqarilmaydi.

`claim_checks` har bir hisoblash versiyasini saqlaydi: aytilgan/kuzatilgan/farq, pip birligi, reference entry, vaqt oynasi, tick soni va eng katta gap. Tarix keyin to‘ldirilsa yangi versiya qo‘shiladi; eski dalil o‘chmaydi. Natija market excursion bo‘yicha; bu summa bajarilgan paper/demo foydasiga qo‘shilmaydi.

Reply bo‘lsa claim signal timeline’iga `RESULT_CLAIM` sifatida ulanadi; uning o‘zi trade’ni yopmaydi yoki TP natijasini yaratmaydi. Reply yoki ishonchli bog‘lanish bo‘lmasa `unlinked_report` saqlanadi. Kelgusi matcher vaqt/kanal/instrument/candidate’larni ishlatadi va noaniqlikni ko‘rsatadi.

## Tahrir va o‘chirish

Har bir kanal uchun quyidagilar talab etiladi:

- birinchi kuzatilgan original xabar va media;
- edit/delete hodisasi, kuzatilgan vaqt va oldingi/yangi qiymatlar;
- tahrir paytidagi bozor konteksti va trade holati;
- kuzatilgan o‘chirilgan signallar soni, natijasi ma’lum/noma’lumligi;
- signal bo‘lmagan oddiy post o‘chirilishini signal o‘chirilishidan ajratish;
- history/realtime takroriy yetkazishini bitta xabar sifatida hisoblash.

O‘chirilgan signal tahlildan chiqarilmaydi. Tahrir dastlabki entry/SL/TP yoki ochilgan trade tarixini qayta yozmaydi. Har qanday edit/delete’ni avtomatik aldov deb belgilash mumkin emas. Kuzatuvdan oldin yo‘qolgan original yoki listener olmagan delete hodisasi noma’lum deb ko‘rsatiladi; to‘liq tarix sifatida taqdim etilmaydi.

## Kanalga mos strategiya va ML

Provider xatti-harakati va agent siyosatining natijalari alohida taqqoslanadi. Exit ko‘rsatilmagan kanalga orqadan eng yaxshi TP tanlab, uni kanalning original natijasi deb yozish mumkin emas.

Tadqiqot variantlari: immediate/range entry, kutish, tasdiqlangan model SL, first/final TP, vaqt bo‘yicha exit, partial + BE, trailing. SL/TP modeli taxminlarini birinchi navbatda paper/shadow’da tekshirish kerak. Grafikni tushunadigan model bilan bu variantlarni tanlaydigan natija modeli mustaqil qatlamlardir.

ML faqat qaror paytida mavjud feature’lardan foydalanadi. Keyingi claim, edit, delete yoki kelajak narxi kirish feature’i bo‘la olmaydi. Dataset xronologik ajratiladi, eksperimentlar va parametr tanlash qayd etiladi; ko‘p variant orasidan topilgan eng yaxshi tarixiy natija mustaqil testda qayta tekshiriladi. Korrelyatsiyalangan nusxa kanallar mustaqil tasdiq sifatida sanalmaydi.

Yakuniy hisobotda kanal, instrument/yo‘nalish/sessiya, siyosat versiyasi, sample size, tahlil qamrovi, noma’lum natijalar, net expectancy, drawdown, worst losing streak, xarajatlar, demo natijasi va drift holati ko‘rsatilishi kerak. Ma’lumot yetishmasa tavsiya `INSUFFICIENT_DATA` bo‘ladi. Faol kapital/risk oshirish model score’iga qarab cheksiz amalga oshirilmaydi.

## Hozir bajarilgan va qolgan ishlar

**Bajarilgan:** incomplete signal saqlash; kanal tarixidan statistik SL/target/profit-lock hisoblash; alohida chronological validation; yetishmagan darajalarni paper execution uchun agent siyosatidan olish; original maydonlarni saqlash; bosqichli foyda SL’i va vaqt bo‘yicha exit; claim’dagi pips bilan kuzatilgan maksimum/farq/qamrovni versiyalab saqlash; explicit save va profit-lock update’lari; kanal sahifasida sozlash, siyosatlar, edit/delete va claim dalillari.

Kanal kuzatuv hisoboti joriy parser qoidalari bilan birinchi kuzatilgan matndan tuziladi. Bu hisobotning o‘zi claim tekshiruvi yoki ML o‘qitish hisoblanmaydi. Signal trade bo‘lmasa ham kuzatuv soniga kiradi.

**ML qo‘shilgan:** kanalga xos supervised signal sifati modeli, kalibratsiya va mustaqil xronologik sinov, sabablar, oldindan filtr, rad etilgan signal natijalarini kuzatish, qayta o‘qitish va drift. Ishlash tartibi va chegaralar: [Kanal ML oqimi](channel-ml.uz.md).

**Individual strategiya qo‘shilgan:** 7 chiqish usulini kanal/instrument/yo‘nalish bo‘yicha mustaqil sinash, tanlangan usulni kelgusi paper signallarga qo‘llash, foydasi tasdiqlanmagan scope’ni monitoringda qoldirish va yetarli dalil bilan foydasiz kanalning yangi savdolarini bloklash. Transport monitoringi va savdo holati alohida. Video ochilmaydi/yuklanmaydi; faqat yozma caption ishlatiladi.

**Qolgan:** original media download/arxiv va revisionlar; grafikni tushunish; brokerning haqiqiy demo fill’lari bilan claim reconciliation; kanalga xos default save ulushi; chuqurroq ML/strategiya modellari; provider baseline; walk-forward portfolio validation va MT5 demo execution/reconciliation.

Rasmiy manbalar: [MT5 instrument spetsifikatsiyalari](https://www.mql5.com/en/docs/constants/environment_state/marketinfoconstants), [CFTC: trading system natijalari va simulyatsiya chegaralari](https://www.cftc.gov/LearnAndProtect/AdvisoriesAndArticles/fraudadv_tradingsystem.html).
