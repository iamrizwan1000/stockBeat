<?php

namespace App\Models;

use Database\Factories\ContentBlockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Plan §5.1/§8.7.3: admin-editable paywall & store-listing marketing copy
 * blocks, so an admin can tweak paywall copy without a mobile app release.
 * `body` can contain simple `{placeholder}` tokens (e.g. `{price}`) the
 * client substitutes at render time. `locale` is i18n-ready per §4.10, but
 * only `en` has real content today. `key` is immutable after creation — it's
 * the stable identifier the mobile app's `content` map is keyed by.
 *
 * @property int $id
 * @property string $key
 * @property string $title
 * @property string $body
 * @property string $locale
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'title', 'body', 'locale', 'active'])]
class ContentBlock extends Model
{
    /** @use HasFactory<ContentBlockFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
