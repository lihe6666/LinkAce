<?php
return [
    'tag' => 'Tag',
    'tags' => 'Tags',
    'all_tags' => 'All Tags',
    'recent_tags' => 'Recent Tags',
    'update_tags' => 'Update Tags',

    'add' => 'Add Tag',
    'show' => 'Show Tag',
    'edit' => 'Edit Tag',
    'update' => 'Update Tag',
    'delete' => 'Delete Tag|Delete Tags',

    'filter_tags' => 'Filter Tags...',

    'public' => 'Public Tag',
    'internal' => 'Internal Tag',
    'private' => 'Private Tag',

    'name' => 'Tag Name',
    'visibility' => 'Visibility Status',

    'history_deleted' => 'Tag was moved to the trash',
    'history_restored' => 'Tag was restored',
    'history_created' => 'Tag was created',

    'author' => 'by :user',

    'no_tags' => 'No Tags',

    'bulk_title' => 'You want to edit :count Tag.|You want to edit :count Tags.',
    'bulk_edit_add' => 'Add Tag to bulk edit',
    'bulk_edit_success' => 'Successfully updated :success Tags out of :selected selected ones.',
    'bulk_delete_success' => 'Successfully moved :success Tags out of :selected selected ones to the trash.',
    'bulk_mode_append' => 'Append new Tags to existing ones',
    'bulk_mode_replace' => 'Replace existing Tags with new ones',

    'added_successfully' => 'Tag added successfully.',
    'updated_successfully' => 'Tag updated successfully.',
    'deleted_successfully' => 'Tag successfully moved to the trash.',
    'deletion_error' => 'Tag could not be moved to the trash.',

    'merge_similar' => 'Merge Similar Tags',
    'merge_intro' => 'The AI scans all your tags, groups the ones with the same meaning and merges them. '
        . 'Each group keeps its tag with the most links. This can take a moment.',
    'merge_all' => 'Find and merge similar tags',
    'merge_all_confirm' => 'Let the AI find synonymous tags and merge them now? This can take a moment.',
    'merged_all_successfully' => 'Merged :count tag group(s).',
    'merge_ai_failed' => 'The AI could not group your tags. Nothing was merged.',
    'merge_ai_not_configured' => 'No AI API key is configured (LLM_API_KEY).',
];
