<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\MotorModel;
use App\Support\Quantity;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class MotorModelService
{
    private const INTEGER_FIELDS = [
        'speed_rpm' => 'Devir',
        'frequency_hz' => 'Frekans',
        'pole_count' => 'Kutup sayısı',
    ];

    public function create(array $data): MotorModel
    {
        $model = new MotorModel(Arr::only($this->normalize($data), MotorModel::FIELDS));
        $model->is_active = true;
        $model->save();

        return $model;
    }

    /**
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(MotorModel $model, array $data, int $expectedLockVersion): MotorModel
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($model, $data, $expectedLockVersion): MotorModel {
            $model->fill(Arr::only($data, MotorModel::FIELDS));
            $model->saveExpectingVersion($expectedLockVersion);

            return $model;
        });
    }

    public function deactivate(MotorModel $model): MotorModel
    {
        $model->is_active = false;
        $model->save();

        return $model;
    }

    public function activate(MotorModel $model): MotorModel
    {
        $model->is_active = true;
        $model->save();

        return $model;
    }

    /** Boş alanlar null olur; güç Türkçe biçimde girilebilir ("5,5"); sayısal alanlar pozitif olmalı. */
    private function normalize(array $data): array
    {
        foreach (MotorModel::FIELDS as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]) !== '' ? trim($data[$field]) : null;
            }
        }

        if (($data['power_kw'] ?? null) !== null) {
            $power = Quantity::parse($data['power_kw']);

            if (Quantity::compare($power, '0') <= 0) {
                throw new BusinessRuleException('Güç sıfırdan büyük olmalıdır.');
            }

            $data['power_kw'] = $power;
        }

        foreach (self::INTEGER_FIELDS as $field => $label) {
            if (($data[$field] ?? null) === null) {
                continue;
            }

            if (filter_var($data[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new BusinessRuleException("{$label} pozitif bir tam sayı olmalıdır.");
            }

            $data[$field] = (int) $data[$field];
        }

        return $data;
    }
}
