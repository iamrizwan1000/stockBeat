<?php

namespace App\Models;

use Database\Factories\RuleExecutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $rule_id
 * @property int|null $order_id
 * @property string $trigger
 * @property array<int, array<string, mixed>> $actions_result
 * @property Carbon $fired_at
 */
#[Fillable(['rule_id', 'order_id', 'trigger', 'actions_result', 'fired_at'])]
class RuleExecution extends Model
{
    /** @use HasFactory<RuleExecutionFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actions_result' => 'array',
            'fired_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Rule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
