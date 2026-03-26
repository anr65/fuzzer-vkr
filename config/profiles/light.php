<?php declare(strict_types=1);

return [
    'byte_mutations' => [
        'weight' => 0.9,
        'operators' => [
            'ChangeByte' => 0.6,
            'InsertByte' => 0.3,
            'ChangeBit' => 0.1,
        ],
    ],
    'micro_structural_mutations' => [
        'weight' => 0.1,
        'operators' => [
            'InsertRepeatedBytes' => 1.0,
        ],
    ],
];
