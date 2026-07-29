<?php

namespace App\Services;

use App\Enums\BoqItemCategory;
use App\Enums\BoqRevisionStatus;
use App\Exports\BoqExport;
use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Models\BoqSection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BOQService
{
    /**
     * @param  array<int, array{section: string, description: string, unit: string, category: string, budgeted_qty: numeric-string|float|int, unit_rate: numeric-string|float|int}>  $items
     * @return array<int, BoqItem>
     */
    public function storeItems(Project $project, array $items): array
    {
        return DB::transaction(function () use ($project, $items) {
            $created = [];

            foreach ($items as $item) {
                $created[] = $this->createItem($project, [
                    'section' => $item['section'],
                    'description' => $item['description'],
                    'unit' => $item['unit'] ?: 'ea',
                    'category' => $item['category'],
                    'budgeted_qty' => $item['budgeted_qty'],
                    'unit_rate' => $item['unit_rate'],
                ]);
            }

            return $created;
        });
    }

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
                    $description = trim((string) ($mapped['description'] ?? ''));

                    if ($description === '') {
                        throw new \InvalidArgumentException('Description is required.');
                    }

                    $item = $this->createItem($project, [
                        'section' => $mapped['section'] ?? 'General',
                        'description' => $description,
                        'unit' => trim((string) ($mapped['unit'] ?? 'ea')),
                        'category' => $mapped['category'] ?? 'materials',
                        'budgeted_qty' => $mapped['budgeted_qty'] ?? '0',
                        'unit_rate' => $mapped['unit_rate'] ?? '0',
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

    /**
     * @param  array{section: string, description: string, unit?: string, category?: string, budgeted_qty?: mixed, unit_rate?: mixed}  $data
     */
    public function createItem(Project $project, array $data): BoqItem
    {
        $section = $this->resolveOrCreateSection($project, (string) ($data['section'] ?? 'General'));

        $budgetedQty = bcadd((string) ($data['budgeted_qty'] ?? '0'), '0', 3);
        $unitRate = bcadd((string) ($data['unit_rate'] ?? '0'), '0', 2);
        $budgetedAmount = bcmul($budgetedQty, $unitRate, 2);

        return BoqItem::create([
            'section_id' => $section->id,
            'description' => trim((string) $data['description']),
            'unit' => trim((string) ($data['unit'] ?? 'ea')) ?: 'ea',
            'category' => $this->resolveCategory((string) ($data['category'] ?? 'materials')),
            'budgeted_qty' => $budgetedQty,
            'unit_rate' => $unitRate,
            'budgeted_amount' => $budgetedAmount,
        ]);
    }

    /**
     * @param  array{section: string, description: string, unit?: string, category?: string, budgeted_qty?: mixed, unit_rate?: mixed}  $data
     */
    public function updateItem(Project $project, BoqItem $item, array $data): BoqItem
    {
        $this->assertItemBelongsToProject($project, $item);

        return DB::transaction(function () use ($project, $item, $data) {
            $oldSectionId = $item->section_id;
            $section = $this->resolveOrCreateSection($project, (string) ($data['section'] ?? 'General'));

            $budgetedQty = bcadd((string) ($data['budgeted_qty'] ?? '0'), '0', 3);
            $unitRate = bcadd((string) ($data['unit_rate'] ?? '0'), '0', 2);
            $budgetedAmount = bcmul($budgetedQty, $unitRate, 2);

            $item->update([
                'section_id' => $section->id,
                'description' => trim((string) $data['description']),
                'unit' => trim((string) ($data['unit'] ?? 'ea')) ?: 'ea',
                'category' => $this->resolveCategory((string) ($data['category'] ?? 'materials')),
                'budgeted_qty' => $budgetedQty,
                'unit_rate' => $unitRate,
                'budgeted_amount' => $budgetedAmount,
            ]);

            $this->deleteSectionIfEmpty($oldSectionId);

            return $item->fresh(['section']);
        });
    }

    /**
     * @param  list<int>  $itemIds
     */
    public function deleteItems(Project $project, array $itemIds): int
    {
        return DB::transaction(function () use ($project, $itemIds) {
            $items = BoqItem::query()
                ->whereIn('id', $itemIds)
                ->whereHas('section', fn ($query) => $query->where('project_id', $project->id))
                ->get();

            $sectionIds = $items->pluck('section_id')->unique()->all();

            foreach ($items as $item) {
                $item->delete();
            }

            foreach ($sectionIds as $sectionId) {
                $this->deleteSectionIfEmpty((int) $sectionId);
            }

            return $items->count();
        });
    }

    public function findItemForProject(Project $project, int $itemId): BoqItem
    {
        $item = BoqItem::query()
            ->with('section')
            ->whereKey($itemId)
            ->whereHas('section', fn ($query) => $query->where('project_id', $project->id))
            ->firstOrFail();

        return $item;
    }

    private function assertItemBelongsToProject(Project $project, BoqItem $item): void
    {
        $item->loadMissing('section');

        if ((int) $item->section?->project_id !== (int) $project->id) {
            abort(404);
        }
    }

    private function deleteSectionIfEmpty(int $sectionId): void
    {
        $section = BoqSection::query()->find($sectionId);

        if (! $section) {
            return;
        }

        if ($section->items()->count() === 0) {
            $section->delete();
        }
    }

    public function resolveOrCreateSection(Project $project, string $sectionName): BoqSection
    {
        $name = trim($sectionName) !== '' ? trim($sectionName) : 'General';

        return BoqSection::firstOrCreate(
            ['project_id' => $project->id, 'name' => $name],
            ['display_order' => BoqSection::where('project_id', $project->id)->count() + 1]
        );
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

    /**
     * Rows use the same columns accepted by importFromFile().
     *
     * @return Collection<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    public function exportRows(Project $project, bool $templateOnly = false): Collection
    {
        if ($templateOnly) {
            return collect([
                ['Earthworks', 'Excavation - bulk', 'm3', 'materials', '100', '8500'],
            ]);
        }

        $rows = collect();

        foreach ($this->tree($project) as $section) {
            foreach ($section->items as $item) {
                $rows->push([
                    $section->name,
                    $item->description,
                    $item->unit,
                    $item->category instanceof BoqItemCategory
                        ? $item->category->value
                        : (string) $item->category,
                    (string) $item->budgeted_qty,
                    (string) $item->unit_rate,
                ]);
            }
        }

        return $rows;
    }

    public function exportExcel(Project $project, bool $templateOnly = false): BinaryFileResponse
    {
        $rows = $this->exportRows($project, $templateOnly);
        $slug = Str::slug($project->code ?: 'project');
        $filename = $templateOnly
            ? "boq-template-{$slug}.xlsx"
            : "boq-{$slug}-".now()->format('Ymd-His').'.xlsx';

        return Excel::download(
            new BoqExport($rows, $templateOnly ? 'BOQ Template' : 'BOQ'),
            $filename,
        );
    }
}
