<?php

return [
    'frontend' => [
        'typo3tests/test-preview-edit-mode/edit-mode' => [
            'target' => \TYPO3Tests\TestPreviewEditMode\Middleware\EditMode::class,
            'after' => [
                // Sets the aspect frontend.preview with the mode of the preview token
                'typo3/cms-frontend/preview-token-authentication',
            ],
        ],
    ],
];
