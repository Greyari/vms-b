<?php

namespace App\Services\Import\Transformers;

class NationalityTransformer
{
    /**
     * @param  array<string, mixed>  $row
     * @return array{source_key: string, attributes: array{name: string}}|null
     */
    public function transform(array $row): ?array
    {
        $name = trim((string) ($row['Description'] ?? ''));
        if ($name === '') {
            return null;
        }

        $code = trim((string) ($row['NationalityCode'] ?? ''));
        if ($code === '') {
            $code = $name;
        }

        return [
            'source_key' => $code,
            'attributes' => [
                'name' => $name,
            ],
        ];
    }
}
