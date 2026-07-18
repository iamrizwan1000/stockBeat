<?php

namespace App\Actions\Admin\ContentBlocks;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\ContentBlock;

/**
 * The block `key` is immutable after creation — it's the stable identifier
 * the mobile app's `content` map is keyed by — only the human-facing
 * fields plus locale/active are editable here.
 */
class UpdateContentBlockAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(AdminUser $admin, ContentBlock $block, array $data): ContentBlock
    {
        $before = [
            'title' => $block->title,
            'body' => $block->body,
            'locale' => $block->locale,
            'active' => $block->active,
        ];

        $block->update([
            'title' => $data['title'],
            'body' => $data['body'],
            'locale' => $data['locale'] ?? 'en',
            'active' => $data['active'] ?? true,
        ]);

        $this->auditLog->handle($admin, 'content_block.update', ContentBlock::class, $block->id, $before, [
            'title' => $block->title,
            'body' => $block->body,
            'locale' => $block->locale,
            'active' => $block->active,
        ]);

        return $block;
    }
}
