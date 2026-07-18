<?php

namespace App\Actions\Admin\ContentBlocks;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\ContentBlock;

class DeleteContentBlockAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, ContentBlock $block): void
    {
        $before = ['key' => $block->key, 'title' => $block->title];
        $blockId = $block->id;

        $block->delete();

        $this->auditLog->handle($admin, 'content_block.delete', ContentBlock::class, $blockId, $before, null);
    }
}
