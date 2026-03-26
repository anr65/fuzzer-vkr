<?php declare(strict_types=1);

return [
    'byte_mutations' => [
        'weight' => 0.35,
        'operators' => [
            'ChangeByte' => 0.4,
            'ChangeBit' => 0.25,
            'InsertByte' => 0.2,
            'EraseBytes' => 0.15,
        ],
    ],
    'block_mutations' => [
        'weight' => 0.4,
        'operators' => [
            'CopyPart' => 0.35,
            'ShuffleBytes' => 0.25,
            'InsertRepeatedBytes' => 0.2,
            'CrossOver' => 0.2,
        ],
    ],
    'semantic_mutations' => [
        'weight' => 0.25,
        'operators' => [
            'ChangeASCIIInt' => 0.4,
            'ChangeBinInt' => 0.3,
            'AddWordFromManualDictionary' => 0.3,
        ],
    ],
];
