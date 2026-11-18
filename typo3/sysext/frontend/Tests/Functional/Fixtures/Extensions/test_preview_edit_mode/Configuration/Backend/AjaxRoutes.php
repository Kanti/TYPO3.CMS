<?php

use TYPO3Tests\TestPreviewEditMode\Controller\EditFrameController;

return [
    'test_preview_edit_mode_url' => [
        'path' => '/test-preview-edit-mode/url',
        'methods' => ['GET'],
        'target' => EditFrameController::class . '::urlAction',
    ],
];
