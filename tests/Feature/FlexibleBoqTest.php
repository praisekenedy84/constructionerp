<?php

namespace Tests\Feature;

use App\Models\BoqItem;
use App\Models\BoqSection;
use App\Models\Project;
use App\Models\Tenant;
use App\Services\AuthService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlexibleBoqTest extends TestCase
{
    use RefreshDatabase;

    private function loginAsQuantitySurveyor(): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'BOQ Co',
            'slug' => 'boq-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'QS',
            'email' => 'qs@boq.local',
            'password' => 'password',
            'role' => 'Quantity Surveyor',
        ]);

        $tenant->run(function () {
            app(PermissionService::class)->syncTenantPermissions();

            Project::create([
                'code' => 'BQ-001',
                'name' => 'BOQ Project',
                'client' => 'Client',
                'location' => 'Site',
                'contract_amount' => '10000000.00',
                'wht_percentage' => '5.00',
                'physical_progress_pct' => '0.00',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => 'active',
            ]);
        });

        tenancy()->end();

        $this->post('/login', [
            'email' => 'qs@boq.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        return $tenant;
    }

    private function projectId(Tenant $tenant): int
    {
        tenancy()->initialize($tenant);
        $id = Project::query()->value('id');
        tenancy()->end();

        return (int) $id;
    }

    public function test_create_form_is_available(): void
    {
        $tenant = $this->loginAsQuantitySurveyor();
        $projectId = $this->projectId($tenant);

        $this->get("/projects/{$projectId}/boq/create")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('BOQ/Create')
                ->has('project')
                ->has('categories')
                ->has('sectionNames'));
    }

    public function test_can_add_boq_items_manually_without_file_import(): void
    {
        $tenant = $this->loginAsQuantitySurveyor();
        $projectId = $this->projectId($tenant);

        $this->post("/projects/{$projectId}/boq/items", [
            'items' => [
                [
                    'section' => 'Earthworks',
                    'description' => 'Excavation',
                    'unit' => 'm3',
                    'category' => 'materials',
                    'budgeted_qty' => '100',
                    'unit_rate' => '25.50',
                ],
                [
                    'section' => 'Earthworks',
                    'description' => 'Backfill',
                    'unit' => 'm3',
                    'category' => 'labor',
                    'budgeted_qty' => '40',
                    'unit_rate' => '15',
                ],
                [
                    'section' => 'Concrete',
                    'description' => 'Formwork',
                    'unit' => 'm2',
                    'category' => 'materials',
                    'budgeted_qty' => '200',
                    'unit_rate' => '12',
                ],
            ],
        ])->assertRedirect("/projects/{$projectId}/boq");

        tenancy()->initialize($tenant);

        $this->assertSame(2, BoqSection::query()->count());
        $this->assertSame(3, BoqItem::query()->count());

        $excavation = BoqItem::query()->where('description', 'Excavation')->first();
        $this->assertNotNull($excavation);
        $this->assertSame('100.000', $excavation->budgeted_qty);
        $this->assertSame('25.50', $excavation->unit_rate);
        $this->assertSame('2550.00', $excavation->budgeted_amount);
        $this->assertSame('Earthworks', $excavation->section->name);

        tenancy()->end();
    }

    public function test_manual_store_validates_required_fields(): void
    {
        $tenant = $this->loginAsQuantitySurveyor();
        $projectId = $this->projectId($tenant);

        $this->from("/projects/{$projectId}/boq/create")
            ->post("/projects/{$projectId}/boq/items", [
                'items' => [
                    [
                        'section' => '',
                        'description' => '',
                        'unit' => '',
                        'category' => 'not-a-category',
                        'budgeted_qty' => '0',
                        'unit_rate' => '-1',
                    ],
                ],
            ])
            ->assertRedirect("/projects/{$projectId}/boq/create")
            ->assertSessionHasErrors([
                'items.0.section',
                'items.0.description',
                'items.0.unit',
                'items.0.category',
                'items.0.budgeted_qty',
                'items.0.unit_rate',
            ]);
    }

    public function test_boq_import_from_csv_creates_items_and_redirects_to_boq(): void
    {
        $tenant = $this->loginAsQuantitySurveyor();
        $projectId = $this->projectId($tenant);

        $path = storage_path('framework/testing/boq-import.csv');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, implode("\n", [
            'Section,Description,Unit,Category,Qty,Rate',
            'Earthworks,Imported Excavation,m3,materials,50,1000',
            'Concrete,Imported Formwork,m2,materials,20,500',
        ])."\n");

        $this->post("/projects/{$projectId}/boq/import", [
            'file' => new \Illuminate\Http\UploadedFile(
                $path,
                'boq-import.csv',
                'text/csv',
                null,
                true,
            ),
        ])->assertRedirect("/projects/{$projectId}/boq")
            ->assertSessionHas('success');

        tenancy()->initialize($tenant);

        $this->assertSame(2, BoqItem::query()->count());
        $this->assertNotNull(BoqItem::query()->where('description', 'Imported Excavation')->first());
        $this->assertNotNull(BoqItem::query()->where('description', 'Imported Formwork')->first());

        tenancy()->end();
        @unlink($path);
    }

    public function test_boq_can_be_exported_as_excel_with_importable_headers(): void
    {
        $tenant = $this->loginAsQuantitySurveyor();
        $projectId = $this->projectId($tenant);

        $this->post("/projects/{$projectId}/boq/items", [
            'items' => [
                [
                    'section' => 'Earthworks',
                    'description' => 'Excavation',
                    'unit' => 'm3',
                    'category' => 'materials',
                    'budgeted_qty' => '100',
                    'unit_rate' => '25.50',
                ],
            ],
        ])->assertRedirect();

        $response = $this->get("/projects/{$projectId}/boq/export");

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $response->headers->get('content-type'),
        );
        $this->assertStringContainsString(
            '.xlsx',
            (string) $response->headers->get('content-disposition'),
        );

        $temp = tempnam(sys_get_temp_dir(), 'boq-export-').'.xlsx';
        file_put_contents($temp, $response->streamedContent());

        $rows = \Maatwebsite\Excel\Facades\Excel::toArray(null, $temp)[0] ?? [];
        @unlink($temp);

        $this->assertSame(
            ['Section', 'Description', 'Unit', 'Category', 'Qty', 'Rate'],
            $rows[0] ?? null,
        );
        $this->assertSame('Earthworks', $rows[1][0] ?? null);
        $this->assertSame('Excavation', $rows[1][1] ?? null);
        $this->assertSame('m3', $rows[1][2] ?? null);
        $this->assertSame('materials', $rows[1][3] ?? null);
    }

    public function test_boq_template_download_includes_sample_row(): void
    {
        $tenant = $this->loginAsQuantitySurveyor();
        $projectId = $this->projectId($tenant);

        $response = $this->get("/projects/{$projectId}/boq/export?template=1");

        $response->assertOk();

        $temp = tempnam(sys_get_temp_dir(), 'boq-template-').'.xlsx';
        file_put_contents($temp, $response->streamedContent());

        $rows = \Maatwebsite\Excel\Facades\Excel::toArray(null, $temp)[0] ?? [];
        @unlink($temp);

        $this->assertSame(
            ['Section', 'Description', 'Unit', 'Category', 'Qty', 'Rate'],
            $rows[0] ?? null,
        );
        $this->assertNotEmpty($rows[1] ?? null);
        $this->assertSame('Earthworks', $rows[1][0] ?? null);
    }

    public function test_boq_item_can_be_updated_and_bulk_deleted(): void
    {
        $tenant = $this->loginAsQuantitySurveyor();
        $projectId = $this->projectId($tenant);

        $this->post("/projects/{$projectId}/boq/items", [
            'items' => [
                [
                    'section' => 'Earthworks',
                    'description' => 'Excavation',
                    'unit' => 'm3',
                    'category' => 'materials',
                    'budgeted_qty' => '100',
                    'unit_rate' => '25.50',
                ],
                [
                    'section' => 'Earthworks',
                    'description' => 'Backfill',
                    'unit' => 'm3',
                    'category' => 'labor',
                    'budgeted_qty' => '40',
                    'unit_rate' => '15',
                ],
            ],
        ])->assertRedirect();

        tenancy()->initialize($tenant);
        $excavation = BoqItem::query()->where('description', 'Excavation')->firstOrFail();
        $backfill = BoqItem::query()->where('description', 'Backfill')->firstOrFail();
        $excavationId = $excavation->id;
        $backfillId = $backfill->id;
        tenancy()->end();

        $this->put("/projects/{$projectId}/boq/items/{$excavationId}", [
            'section' => 'Earthworks',
            'description' => 'Excavation Updated',
            'unit' => 'm3',
            'category' => 'materials',
            'budgeted_qty' => '120',
            'unit_rate' => '30',
        ])->assertRedirect("/projects/{$projectId}/boq");

        tenancy()->initialize($tenant);
        $excavation = BoqItem::query()->findOrFail($excavationId);
        $this->assertSame('Excavation Updated', $excavation->description);
        $this->assertSame('120.000', $excavation->budgeted_qty);
        $this->assertSame('3600.00', $excavation->budgeted_amount);
        tenancy()->end();

        $this->post("/projects/{$projectId}/boq/items/bulk-delete", [
            'ids' => [$excavationId, $backfillId],
        ])->assertRedirect("/projects/{$projectId}/boq");

        tenancy()->initialize($tenant);
        $this->assertSoftDeleted('boq_items', ['id' => $excavationId]);
        $this->assertSoftDeleted('boq_items', ['id' => $backfillId]);
        tenancy()->end();
    }
}
