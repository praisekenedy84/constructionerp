<?php

namespace App\Services;

use App\Enums\BoqItemCategory;
use App\Enums\BoqRevisionStatus;
use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Models\BoqSection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class BOQService
{
    /**
     * @return array{success: array<int, array<string, mixed>>, errors: array<int, array{row: int, message: string}>}
     */
    public function importFromFile(Project $project, UploadedFile $file): array
    {
        $rows = Excel::toArray(null, $file)[0] ?? [];
        $success = [];
        $errors = [];

        if (empty($rows)) {
            return ['success' => [], 'errors' => [['row' => 0, 'message' => 'File is empty.']]];
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($rows));

        DB::transaction(function () use ($project, $rows, $header, &$success, &$errors) {
            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2;

                if ($this->isEmptyRow($row)) {
                    continue;
                }

                $mapped = $this->mapRow($header, $row);

                try {
                    $sectionName = $mapped['section'] ?? 'General';
                    $description = trim((string) ($mapped['description'] ?? ''));

                    if ($description === '') {
                        throw new \InvalidArgumentException('Description is required.');
                    }

                    $section = BoqSection::firstOrCreate(
                        ['project_id' => $project->id, 'name' => $sectionName],
                        ['display_order' => BoqSection::where('project_id', $project->id)->count() + 1]
                    );

                    $budgetedQty = bcadd((string) ($mapped['budgeted_qty'] ?? '0'), '0', 4);
                    $unitRate = bcadd((string) ($mapped['unit_rate'] ?? '0'), '0', 2);
                    $budgetedAmount = bcmul($budgetedQty, $unitRate, 2);

                    $category = $this->resolveCategory($mapped['category'] ?? 'materials');

                    $item = BoqItem::create([
                        'section_id' => $section->id,
                        'description' => $description,
                        'unit' => trim((string) ($mapped['unit'] ?? 'ea')),
                        'category' => $category,
                        'budgeted_qty' => $budgetedQty,
                        'unit_rate' => $unitRate,
                        'budgeted_amount' => $budgetedAmount,
                    ]);

                    $success[] = [
                        'row' => $rowNumber,
                        'id' => $item->id,
                        'description' => $item->description,
                    ];
                } catch (\Throwable $e) {
                    $errors[] = ['row' => $rowNumber, 'message' => $e->getMessage()];
                }
            }
        });

        return ['success' => $success, 'errors' => $errors];
    }

    public function createRevision(Project $project, User $requester, string $reason): BoqRevision
    {
        return DB::transaction(function () use ($project, $requester, $reason) {
            $versionNo = (int) BoqRevision::where('project_id', $project->id)->max('version_no') + 1;

            return BoqRevision::create([
                'project_id' => $project->id,
                'version_no' => $versionNo,
                'reason' => $reason,
                'requested_by' => $requester->id,
                'status' => BoqRevisionStatus::Draft,
            ]);
        });
    }

    public function activateRevision(BoqRevision $revision, User $approver): BoqRevision
    {
        return DB::transaction(function () use ($revision, $approver) {
            BoqRevision::where('project_id', $revision->project_id)
                ->where('status', BoqRevisionStatus::Active)
                ->update([
                    'status' => BoqRevisionStatus::Draft,
                    'activated_at' => null,
                ]);

            $revision->update([
                'status' => BoqRevisionStatus::Active,
                'approved_by' => $approver->id,
                'activated_at' => now(),
            ]);

            return $revision->fresh();
        });
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<int, string>  $header
     * @return array<string, mixed>
     */
    private function mapRow(array $header, array $row): array
    {
        $mapped = [];

        foreach ($header as $index => $column) {
            $mapped[$column] = $row[$index] ?? null;
        }

        $aliases = [
            'section' => ['section', 'section_name', 'boq_section'],
            'description' => ['description', 'item', 'item_description'],
            'unit' => ['unit', 'uom'],
            'category' => ['category', 'cost_category'],
            'budgeted_qty' => ['budgeted_qty', 'quantity', 'qty', 'budget_qty'],
            'unit_rate' => ['unit_rate', 'rate', 'unit_cost'],
        ];

        $result = [];

        foreach ($aliases as $field => $keys) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $mapped) && $mapped[$key] !== null && $mapped[$key] !== '') {
                    $result[$field] = $mapped[$key];
                    break;
                }
            }
        }

        return $result;
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function resolveCategory(string $category): BoqItemCategory
    {
        $normalized = strtolower(trim($category));

        return BoqItemCategory::tryFrom($normalized) ?? BoqItemCategory::Materials;
    }

    /**
     * @return array<int, \App\Models\BoqSection>
     */
    public function tree(Project $project): array
    {
        return BoqSection::query()
            ->where('project_id', $project->id)
            ->with(['items' => fn ($query) => $query->orderBy('id')])
            ->orderBy('display_order')
            ->get()
            ->all();
    }
}
