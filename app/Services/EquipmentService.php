<?php

namespace App\Services;

use App\Enums\BudgetTransactionType;
use App\Models\Equipment;
use App\Models\EquipmentAssignment;
use App\Models\EquipmentFuelLog;
use App\Models\EquipmentMaintenance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EquipmentService
{
    public function __construct(
        private readonly BudgetService $budgetService,
    ) {}

    public function logMaintenance(
        Equipment $equipment,
        array $data,
        User $actor,
    ): EquipmentMaintenance {
        return DB::transaction(function () use ($equipment, $data, $actor) {
            $cost = bcadd((string) $data['cost'], '0', 2);
            $projectId = $this->resolveProjectId($equipment, $data);

            $maintenance = EquipmentMaintenance::create([
                'equipment_id' => $equipment->id,
                'type' => $data['type'],
                'cost' => $cost,
                'description' => $data['description'] ?? null,
                'date' => $data['date'],
                'created_at' => now(),
            ]);

            if ($projectId) {
                $this->budgetService->createTransaction($projectId, [
                    'type' => BudgetTransactionType::EquipmentCost,
                    'amount' => $cost,
                    'boq_item_id' => $data['boq_item_id'] ?? null,
                    'reference_entity_type' => 'equipment_maintenance',
                    'reference_entity_id' => $maintenance->id,
                    'created_by' => $actor->id,
                ]);
            }

            return $maintenance;
        });
    }

    public function logFuel(
        Equipment $equipment,
        array $data,
        User $actor,
    ): EquipmentFuelLog {
        return DB::transaction(function () use ($equipment, $data, $actor) {
            $cost = bcadd((string) $data['cost'], '0', 2);
            $projectId = $this->resolveProjectId($equipment, $data);

            $fuelLog = EquipmentFuelLog::create([
                'equipment_id' => $equipment->id,
                'assignment_id' => $data['assignment_id'] ?? null,
                'liters' => bcadd((string) $data['liters'], '0', 2),
                'cost' => $cost,
                'date' => $data['date'],
                'created_at' => now(),
            ]);

            if ($projectId) {
                $this->budgetService->createTransaction($projectId, [
                    'type' => BudgetTransactionType::FuelCost,
                    'amount' => $cost,
                    'boq_item_id' => $data['boq_item_id'] ?? null,
                    'reference_entity_type' => 'equipment_fuel_log',
                    'reference_entity_id' => $fuelLog->id,
                    'created_by' => $actor->id,
                ]);
            }

            return $fuelLog;
        });
    }

    public function assign(array $data, User $actor): EquipmentAssignment
    {
        return DB::transaction(function () use ($data) {
            return EquipmentAssignment::create([
                'equipment_id' => $data['equipment_id'],
                'project_id' => $data['project_id'],
                'boq_item_id' => $data['boq_item_id'] ?? null,
                'hours_budgeted' => $data['hours_budgeted'] ?? null,
                'hours_used' => '0',
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
            ]);
        });
    }

    private function resolveProjectId(Equipment $equipment, array $data): ?int
    {
        if (! empty($data['project_id'])) {
            return (int) $data['project_id'];
        }

        if (! empty($data['assignment_id'])) {
            $assignment = EquipmentAssignment::find($data['assignment_id']);

            return $assignment?->project_id;
        }

        $activeAssignment = EquipmentAssignment::where('equipment_id', $equipment->id)
            ->whereNull('end_date')
            ->first();

        return $activeAssignment?->project_id;
    }
}
