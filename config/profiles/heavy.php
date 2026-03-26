<?php declare(strict_types=1);

return [
    'byte_mutations' => [
        'weight' => 0.15,
        'operators' => [
            'EraseBytes' => 0.2,
            'InsertByte' => 0.2,
            'InsertRepeatedBytes' => 0.2,
            'ChangeByte' => 0.2,
            'ChangeBit' => 0.2,
        ],
    ],
    'block_mutations' => [
        'weight' => 0.6,
        'operators' => [
            'CopyPart' => 0.45,
            'ShuffleBytes' => 0.3,
            'CrossOver' => 0.25,
        ],
    ],
    'semantic_mutations' => [
        'weight' => 0.25,
        'operators' => [
            'ChangeASCIIInt' => 0.35,
            'ChangeBinInt' => 0.35,
            'AddWordFromManualDictionary' => 0.3,
        ],
    ],
];
