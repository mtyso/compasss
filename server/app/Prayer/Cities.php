<?php

namespace App\Prayer;

/**
 * Cities of the Muslim Board's taqvim (docs/PLAN.md, D40): each takes Tashkent's prayer times moved by its
 * minutes, the same for every prayer of every day. The coordinates are only for the Qibla's direction.
 */
final class Cities
{
    public const DEFAULT = 'tashkent';

    /** The slugs, for validation. */
    public const LIST = 'tashkent,andijan,angren,asaka,akhangaran,bekabad,bukhara,gulistan,denau,jizzakh,zarafshan,kagan,karshi,kattakurgan,kokand,kuvasay,kungrad,margilan,almalyk,namangan,navoi,nukus,nurafshan,samarkand,termez,turtkul,urgench,fergana,khanabad,khiva,khodjeyli,chimbay,chirchik,shahrisabz,shirin,yangiyul,yangiyer';

    /** slug => [Russian name, Uzbek name, minutes after Tashkent, latitude, longitude] */
    public const ALL = [
        'tashkent' => ['Ташкент', 'Toshkent', 0, 41.2995, 69.2401],
        'andijan' => ['Андижан', 'Andijon', -12, 40.7821, 72.3442],
        'angren' => ['Ангрен', 'Angren', 0, 41.0167, 70.1436],
        'asaka' => ['Асака', 'Asaka', -12, 40.6415, 72.2387],
        'akhangaran' => ['Ахангаран', 'Ohangaron', 0, 40.9064, 69.6383],
        'bekabad' => ['Бекабад', 'Bekobod', 0, 40.2208, 69.2697],
        'bukhara' => ['Бухара', 'Buxoro', 19, 39.7747, 64.4286],
        'gulistan' => ['Гулистан', 'Guliston', 2, 40.4897, 68.7842],
        'denau' => ['Денау', 'Denov', 8, 38.2667, 67.8989],
        'jizzakh' => ['Джизак', 'Jizzax', 6, 40.1158, 67.8422],
        'zarafshan' => ['Зарафшан', 'Zarafshon', 20, 41.5728, 64.2014],
        'kagan' => ['Каган', 'Kogon', 19, 39.7228, 64.5517],
        'karshi' => ['Карши', 'Qarshi', 14, 38.8606, 65.7891],
        'kattakurgan' => ['Каттакурган', "Kattaqo'rg'on", 12, 39.8986, 66.2561],
        'kokand' => ['Коканд', "Qo'qon", -7, 40.5286, 70.9425],
        'kuvasay' => ['Кувасай', 'Quvasoy', -11, 40.2972, 71.9800],
        'kungrad' => ['Кунград', "Qo'ng'irot", 36, 43.0758, 58.9006],
        'margilan' => ['Маргилан', "Marg'ilon", -10, 40.4711, 71.7247],
        'almalyk' => ['Алмалык', 'Olmaliq', 0, 40.8447, 69.5983],
        'namangan' => ['Наманган', 'Namangan', -10, 40.9983, 71.6726],
        'navoi' => ['Навои', 'Navoiy', 15, 40.0844, 65.3792],
        'nukus' => ['Нукус', 'Nukus', 39, 42.4531, 59.6103],
        'nurafshan' => ['Нурафшан', 'Nurafshon', 0, 41.0400, 69.3600],
        'samarkand' => ['Самарканд', 'Samarqand', 9, 39.6542, 66.9597],
        'termez' => ['Термез', 'Termiz', 8, 37.2242, 67.2783],
        'turtkul' => ['Турткуль', "To'rtko'l", 32, 41.5500, 61.0000],
        'urgench' => ['Ургенч', 'Urganch', 34, 41.5500, 60.6333],
        'fergana' => ['Фергана', "Farg'ona", -10, 40.3842, 71.7843],
        'khanabad' => ['Ханабад', 'Xonobod', -12, 40.8019, 73.0000],
        'khiva' => ['Хива', 'Xiva', 35, 41.3783, 60.3639],
        'khodjeyli' => ['Ходжейли', "Xo'jayli", 36, 42.4047, 59.4517],
        'chimbay' => ['Чимбай', 'Chimboy', 36, 42.9311, 59.7708],
        'chirchik' => ['Чирчик', 'Chirchiq', 0, 41.4689, 69.5822],
        'shahrisabz' => ['Шахрисабз', 'Shahrisabz', 10, 39.0578, 66.8342],
        'shirin' => ['Ширин', 'Shirin', 3, 40.2200, 69.1000],
        'yangiyul' => ['Янгиюль', "Yangiyo'l", 0, 41.1122, 69.0472],
        'yangiyer' => ['Янгиер', 'Yangiyer', 2, 40.2750, 68.8225],
    ];

    public static function exists(string $slug): bool
    {
        return isset(self::ALL[$slug]);
    }

    /** @return array{slug: string, name: string, offset: int, lat: float, lon: float} */
    public static function get(?string $slug, string $locale = 'ru'): array
    {
        $slug = $slug !== null && self::exists($slug) ? $slug : self::DEFAULT;
        [$ru, $uz, $offset, $lat, $lon] = self::ALL[$slug];

        return ['slug' => $slug, 'name' => $locale === 'uz' ? $uz : $ru, 'offset' => $offset, 'lat' => $lat, 'lon' => $lon];
    }

    /** @return list<array{slug: string, name: string, offset: int, lat: float, lon: float}> in the alphabet of the language, Tashkent first */
    public static function list(string $locale = 'ru'): array
    {
        $cities = array_map(fn (string $slug) => self::get($slug, $locale), array_keys(self::ALL));
        usort($cities, fn (array $a, array $b) => [$a['slug'] !== self::DEFAULT, $a['name']] <=> [$b['slug'] !== self::DEFAULT, $b['name']]);

        return $cities;
    }
}
