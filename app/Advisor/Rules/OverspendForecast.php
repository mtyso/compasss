<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;

/**
 * «При таком темпе на продукты уйдёт 2,9 млн при плане 2,5 млн.»
 * For essential categories, where cutting back is not the advice but knowing in advance is.
 * Flexible ones get CategoryPace with a daily amount instead. Once a week per category.
 */
final class OverspendForecast extends BaseRule
{
    public function key(): string
    {
        return 'overspend_forecast';
    }

    public function events(): array
    {
        return [self::EVENT_TRANSACTION, self::EVENT_MORNING];
    }

    public function evaluate(AdvisorContext $context): array
    {
        $budget = $context->budget();
        if (! $this->aboutThisMonth($context) || $budget->daysGone < 7) {
            return [];
        }
        $tolerance = $context->pick(1.15, 1.05, 1.0);
        $alertShare = (int) $context->setting('budget_alert_percent', 80) / 100;
        $findings = [];

        foreach ($budget->items as $item) {
            if ($context->category($item['category_id'])?->necessity !== 'essential' || $item['forecast_uzs'] === null) {
                continue;
            }
            if ($item['spent_uzs'] >= $item['limit_uzs'] * $alertShare || $item['forecast_uzs'] <= $item['limit_uzs'] * $tolerance) {
                continue;
            }
            $findings[] = $this->finding('warn', $context->week(), (string) $item['category_id'], [
                'category_id' => $item['category_id'],
                // «около 3 400 000», not «около 3 395 238»: a forecast is not exact to the som.
                'forecast_uzs' => (int) (round($item['forecast_uzs'] / 10_000) * 10_000),
                'limit_uzs' => $item['limit_uzs'],
            ]);
        }

        return $findings;
    }
}
