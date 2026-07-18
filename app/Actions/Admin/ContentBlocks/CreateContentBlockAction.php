<?php

namespace App\Actions\Admin\ContentBlocks;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\ContentBlock;

class CreateContentBlockAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(AdminUser $admin, array $data): ContentBlock
    {
        $block = ContentBlock::query()->create([
            'key' => $data['key'],
            'title' => $data['title'],
            'body' => $data['body'],
            'locale' => $data['locale'] ?? 'en',
            'active' => $data['active'] ?? true,
        ]);

        $this->auditLog->handle($admin, 'content_block.create', ContentBlock::class, $block->id, null, [
            'key' => $block->key,
            'title' => $block->title,
            'body' => $block->body,
            'locale' => $block->locale,
            'active' => $block->active,
        ]);

        return $block;
    }
}
