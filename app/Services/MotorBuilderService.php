<?php

namespace App\Services;

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Models\BomTemplate;
use App\Models\MotorModel;
use Illuminate\Support\Facades\DB;

/**
 * "Motor oluştur": bir motor şablonundan bileşen seçerek yeni motor modeli, mamul kartı ve reçetesi tek işlemde
 * oluşturulur. Herhangi bir satır reddedilirse (veya hiç bileşen seçilmezse) model de oluşmaz. Kurallar
 * MotorModelService ve BomTemplateService'te; mevcut modelin değiştirilmesi bu akışın dışındadır.
 */
class MotorBuilderService
{
    public function __construct(
        private readonly MotorModelService $models,
        private readonly BomTemplateService $templates,
    ) {}

    /**
     * @param  array<string, mixed>  $model  Motor modeli alanları (MotorModelService::create).
     * @param  array<int, array{part_id?: mixed, quantity_per_unit?: mixed}>  $rows  şablon satırı id => seçim
     * @return array{model: MotorModel, added: int}
     */
    public function build(BomTemplate $template, array $model, array $rows): array
    {
        if ($template->product_type !== PartType::FinishedGood) {
            throw new BusinessRuleException('Motor oluşturmak için bir motor (mamul) şablonu seçin.');
        }

        return DB::transaction(function () use ($template, $model, $rows): array {
            $created = $this->models->create($model);
            $result = $this->templates->apply($template, $created->finishedPart()->firstOrFail(), $rows);

            return ['model' => $created, 'added' => $result['added']];
        });
    }
}
