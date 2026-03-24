<?php

namespace App\Admin\Resources\ServerResource\Pages;

use App\Admin\Resources\ServerResource;
use App\Helpers\ExtensionHelper;
use Arr;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateServer extends CreateRecord
{
    protected static string $resource = ServerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = 'server';

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $data['enabled'] = true;
        $record = static::getModel()::create(Arr::except($data, ['settings']));

        if (!isset($data['settings'])) {
            return $record;
        }

        $config = ExtensionHelper::getConfig($record->type, $record->extension);

        foreach ($config as $option) {
            $key = $option['name'];
            $value = $data['settings'][$key] ?? null;

            if (is_null($value)) {
                continue;
            }

            $record->settings()->updateOrCreate([
                'key' => $key,
            ], [
                'type' => $option['database_type'] ?? 'string',
                'value' => is_array($value) ? json_encode($value) : $value,
                'encrypted' => $option['encrypted'] ?? false,
            ]);
        }

        ExtensionHelper::call($record, 'enabled', [$record], mayFail: true);

        return $record;
    }
}
