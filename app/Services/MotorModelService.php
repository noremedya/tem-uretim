<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\MotorModel;
use App\Models\Part;
use App\Support\Gtip;
use App\Support\Quantity;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Motor modelleri. Her modelin bir mamul kartı vardır: model oluşturulunca açılır, kodu/adı/aktifliği
 * modelle birlikte değişir (CLAUDE.md bölüm 5 "Mamul ve yarı mamul").
 */
class MotorModelService
{
    public function __construct(private readonly PartService $parts) {}

    private const INTEGER_FIELDS = [
        'speed_rpm' => 'Devir',
        'frequency_hz' => 'Frekans',
        'pole_count' => 'Kutup sayısı',
    ];

    public function create(array $data): MotorModel
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($data): MotorModel {
            $model = new MotorModel(Arr::only($data, MotorModel::FIELDS));
            $this->ensurePartCodeFree($model->code);

            $finished = $this->parts->createFinishedGood($model->code, $model->name, true);
            $model->finished_part_id = $finished->getKey();
            $model->is_active = true;
            $model->save();

            // Mamul alt türü (Trifaze, Monofaze MD…) modelin formundan girilir, mamul kartında tutulur.
            if (filled($data['subtype_id'] ?? null)) {
                $this->parts->setFinishedGoodSubtype($finished, (int) $data['subtype_id']);
            }

            return $model;
        });
    }

    /**
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(MotorModel $model, array $data, int $expectedLockVersion): MotorModel
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($model, $data, $expectedLockVersion): MotorModel {
            $model->fill(Arr::only($data, MotorModel::FIELDS));

            if ($model->isDirty('code')) {
                $this->ensurePartCodeFree($model->code, except: $model->finished_part_id);
            }

            $model->saveExpectingVersion($expectedLockVersion);
            $this->syncFinishedPart($model);

            if (array_key_exists('subtype_id', $data)) {
                $finished = $model->finishedPart()->firstOrFail();
                $subtypeId = filled($data['subtype_id']) ? (int) $data['subtype_id'] : null;

                if ($finished->subtype_id !== $subtypeId) {
                    $this->parts->setFinishedGoodSubtype($finished, $subtypeId);
                }
            }

            return $model;
        });
    }

    public function deactivate(MotorModel $model): MotorModel
    {
        return DB::transaction(function () use ($model): MotorModel {
            $model->is_active = false;
            $model->save();
            $this->syncFinishedPart($model);

            return $model;
        });
    }

    public function activate(MotorModel $model): MotorModel
    {
        return DB::transaction(function () use ($model): MotorModel {
            $model->is_active = true;
            $model->save();
            $this->syncFinishedPart($model);

            return $model;
        });
    }

    private function syncFinishedPart(MotorModel $model): void
    {
        $this->parts->syncFinishedGood($model->finishedPart()->firstOrFail(), $model->code, $model->name, $model->is_active);
    }

    /** Mamul kartı modelin kodunu kullanır; bu kodla başka bir stok kartı olmamalı. */
    private function ensurePartCodeFree(string $code, ?int $except = null): void
    {
        if (Part::query()->where('code', $code)->when($except, fn ($query) => $query->whereKeyNot($except))->exists()) {
            throw new BusinessRuleException("{$code} koduyla bir stok kartı zaten var. Motor modelinin mamul kartı aynı kodu kullanır; farklı bir kod seçin.");
        }
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

        if (array_key_exists('gtip_code', $data)) {
            $data['gtip_code'] = Gtip::normalize($data['gtip_code']);
        }

        if (($data['net_weight_kg'] ?? null) !== null) {
            $weight = Quantity::parse($data['net_weight_kg']);

            if (Quantity::compare($weight, '0') <= 0) {
                throw new BusinessRuleException('Net ağırlık sıfırdan büyük olmalıdır.');
            }

            $data['net_weight_kg'] = $weight;
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
