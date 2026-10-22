<?php

namespace App\Telegram\Phrase;

use App\Models\Category;
use App\Models\User;

/**
 * Picks the category for a phrase from the bot (docs/PLAN.md, section 9):
 * first where the same words went before (so a category changed by hand is remembered),
 * then the keyword dictionary, then «Прочее».
 */
final class CategoryGuesser
{
    /**
     * Word starts, Russian and Uzbek, by category key. A key of three letters or fewer
     * must match a whole word («зп», «газ»), a longer one matches the start of a word
     * («аптек» catches «аптека», «аптеке»).
     */
    public const KEYWORDS = [
        // expense
        'transport_taxi' => ['такси', 'taksi', 'taxi', 'yandex go', 'яндекс го', 'uklon', 'mytaxi', 'индрайв', 'indrive'],
        'transport_public' => ['метро', 'metro', 'автобус', 'avtobus', 'проезд', 'маршрутк', 'электричк', 'poyezd', 'поезд'],
        'transport_fuel' => ['бензин', 'benzin', 'заправк', 'топливо', 'метан', 'metan', 'пропан', 'propan', 'yoqilg', 'дизел'],
        'transport_parking' => ['парковк', 'parkovka', 'стоянк', 'avtoturargoh'],
        'transport_service' => ['мойка', 'автомойк', 'moyka', 'шиномонтаж', 'автосервис', 'запчаст', 'техосмотр', 'ремонт машин'],
        'groceries_bazaar' => ['базар', 'bozor', 'рынок', 'chorsu', 'чорсу'],
        'groceries_supermarket' => ['korzinka', 'корзинк', 'makro', 'макро', 'havas', 'хавас', 'супермаркет', 'supermarket', 'carrefour', 'карфур'],
        'groceries' => ['продукт', 'хлеб', 'non', 'нон', 'молоко', 'sut', 'мясо', "go'sht", 'gosht', 'овощ', 'фрукт', 'meva', 'яйц', 'tuxum', 'вода', 'oziq', 'магазин', "do'kon"],
        'cafe_coffee' => ['кофе', 'coffee', 'qahva', 'капучино', 'латте', 'перекус', 'шаурм', 'самса', 'somsa', 'выпечк', 'пончик', 'мороженое'],
        'cafe_delivery' => ['доставк', 'wolt', 'express24', 'экспресс24', 'yandex eda', 'яндекс еда', 'tezkor', 'delivery', 'yetkazib'],
        'cafe_restaurants' => ['ресторан', 'restoran', 'кафе', 'kafe', 'обед', 'tushlik', 'ужин', 'завтрак', 'nonushta', 'плов', 'osh', 'бургер', 'пицц', 'pizza', 'шашлык', 'kabob', 'суши', 'чайхан', 'choyxona'],
        'home_rent' => ['аренд', 'ijara', 'квартплат', 'за квартиру'],
        'home_utilities' => ['коммунал', 'kommunal', 'свет', 'svet', 'электричеств', 'газ', 'gaz', 'вывоз мусор', 'отоплени'],
        'home_internet' => ['интернет', 'internet', 'uztelecom', 'узтелеком', 'тв', 'телевидени', 'sarkor', 'turon'],
        'home_supplies' => ['хозтовар', 'моющ', 'порошок', 'туалетн', 'салфетк', 'губк'],
        'home_repair' => ['ремонт', "ta'mir", 'tamir', 'мебел', 'mebel', 'сантехник', 'электрик'],
        'connection_mobile' => ['телефон', 'связь', 'баланс', 'beeline', 'билайн', 'ucell', 'юселл', 'uzmobile', 'mobiuz', 'мобиуз', 'mobil'],
        'connection_subscriptions' => ['подписк', 'obuna', 'netflix', 'spotify', 'youtube', 'icloud', 'chatgpt', 'claude', 'premium', 'премиум', 'apple music', 'google one', 'кинопоиск'],
        'health_pharmacy' => ['аптек', 'dorixona', 'лекарств', 'dori', 'таблетк', 'витамин'],
        'health_doctors' => ['врач', 'shifokor', 'анализ', 'tahlil', 'клиник', 'klinika', 'стоматолог', 'зубн', 'больниц', 'shifoxona', 'узи', 'мрт'],
        'health_sport' => ['спортзал', 'зал', 'фитнес', 'fitness', 'бассейн', 'basseyn', 'sport', 'спорт', 'тренировк', 'тренер'],
        'clothes' => ['одежд', 'kiyim', 'обувь', 'poyabzal', 'кроссовк', 'krossovka', 'футболк', 'джинс', 'куртк', 'платье', 'рубашк', 'брюк', 'носки'],
        'beauty_haircut' => ['стрижк', 'барбер', 'barber', 'парикмахер', 'sartarosh', 'маникюр', 'педикюр', 'салон'],
        'beauty_cosmetics' => ['косметик', 'kosmetika', 'крем', 'шампун', 'парфюм', 'духи', 'atir'],
        'family_gifts' => ['подарок', 'подарки', "sovg'a", 'sovga', 'цветы', 'букет', 'gul'],
        'family_help' => ['родител', 'маме', 'маму', 'папе', 'onamga', 'otamga', 'бабушк', 'дедушк', 'брату', 'сестре', 'ota-ona'],
        'family_celebrations' => ['свадьб', "to'y", 'той', 'день рождени', 'tug\'ilgan', 'праздник', 'bayram', 'юбилей', 'никох', 'nikoh'],
        'leisure_events' => ['кино', 'kino', 'концерт', 'konsert', 'театр', 'teatr', 'музей', 'выставк', 'боулинг', 'квест'],
        'leisure_hobby' => ['хобби', 'xobbi', 'игра', 'игры', 'игру', "o'yin", 'steam', 'playstation', 'рыбалк'],
        'leisure_travel' => ['путешеств', 'sayohat', 'отель', 'гостиниц', 'mehmonxona', 'hotel', 'авиабилет', 'самолёт', 'самолет', 'отпуск', 'тур'],
        'education_courses' => ['курс', 'kurs', 'обучени', "o'qish", 'репетитор', 'урок', 'школ', 'maktab'],
        'education_books' => ['книг', 'kitob', 'учебник'],
        'finance_fees' => ['комисси', 'komissiya', 'обслуживание карты'],
        'finance_interest' => ['процент по кредит', 'проценты банку', 'foiz'],
        'finance_taxes' => ['налог', 'soliq', 'штраф', 'jarima', 'пошлин'],
        'charity' => ['садака', 'садақа', 'sadaqa', 'благотвор', 'xayriya', 'хайрия', 'донат', 'закят', 'zakot'],
        // income
        'salary' => ['зарплат', 'зп', 'аванс', 'maosh', 'oylik', 'avans', 'salary'],
        'side_job' => ['подработк', 'фриланс', 'freelance', 'халтур', "qo'shimcha ish"],
        'bonus' => ['преми', 'бонус', 'bonus', 'mukofot'],
        'interest_cashback' => ['кэшбэк', 'кешбэк', 'кешбек', 'кэшбек', 'cashback', 'keshbek', 'проценты по вклад'],
        'sales' => ['продал', 'продажа', 'sotdim', 'sotuv'],
    ];

    /** Income keys: a phrase like «зарплата 12м» is income even without a «+». */
    public const INCOME_KEYS = ['salary', 'side_job', 'bonus', 'interest_cashback', 'sales'];

    public function __construct(private User $user) {}

    /** True when the words of the phrase name an income, e.g. «зарплата». */
    public static function soundsLikeIncome(string $note): bool
    {
        $key = self::keyFor($note);

        return $key !== null && in_array($key, self::INCOME_KEYS, true);
    }

    /** @param  'expense'|'income'  $kind */
    public function guess(string $note, string $kind): Category
    {
        return $this->find($note, $kind)
            ?? $this->bySystemKey($kind === 'income' ? 'other_income' : 'other_expense')
            ?? $this->user->categories()->where('kind', $kind)->whereNull('archived_at')->orderBy('sort')->firstOrFail();
    }

    /**
     * Only a real match, no «Прочее»: «можно купить наушники?» is checked against a category budget
     * only when the words say which one.
     *
     * @param  'expense'|'income'  $kind
     */
    public function find(string $note, string $kind): ?Category
    {
        return $this->fromHistory($note, $kind) ?? $this->bySystemKey(self::keyFor($note, $kind));
    }

    /** The category of the latest operation with the same note, if it still exists. */
    private function fromHistory(string $note, string $kind): ?Category
    {
        if ($note === '') {
            return null;
        }

        // Notes from the bot always start with a capital (PhraseParser), so an exact match is enough;
        // SQLite's LOWER() would not fold Cyrillic anyway.
        $categoryId = $this->user->transactions()
            ->where('type', $kind)
            ->where('note', $note)
            ->latest('occurred_at')
            ->value('category_id');

        return $categoryId ? $this->user->categories()->whereNull('archived_at')->find($categoryId) : null;
    }

    private function bySystemKey(?string $key): ?Category
    {
        if ($key === null) {
            return null;
        }

        $category = $this->user->categories()->where('system_key', $key)->first();
        if ($category && $category->archived_at !== null) {
            // An archived subcategory falls back to its main category.
            $category = $category->parent_id ? $this->user->categories()->whereNull('archived_at')->find($category->parent_id) : null;
        }

        return $category;
    }

    /** @param  'expense'|'income'|null  $kind  limits the search to that kind's keys */
    public static function keyFor(string $note, ?string $kind = null): ?string
    {
        $text = ' '.mb_strtolower($note).' ';
        $words = preg_split("/[^\\p{L}\\d'ʻ’]+/u", $text, -1, PREG_SPLIT_NO_EMPTY);

        foreach (self::KEYWORDS as $key => $keywords) {
            $isIncome = in_array($key, self::INCOME_KEYS, true);
            if ($kind !== null && $isIncome !== ($kind === 'income')) {
                continue;
            }
            foreach ($keywords as $keyword) {
                if (str_contains($keyword, ' ')) {
                    if (str_contains($text, " {$keyword}")) {
                        return $key;
                    }

                    continue;
                }
                foreach ($words as $word) {
                    if (mb_strlen($keyword) <= 3 ? $word === $keyword : str_starts_with($word, $keyword)) {
                        return $key;
                    }
                }
            }
        }

        return null;
    }
}
