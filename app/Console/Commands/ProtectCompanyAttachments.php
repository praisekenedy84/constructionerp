<?php

namespace App\Console\Commands;

use App\Models\RequisitionAttachment;
use App\Models\Tenant;
use App\Services\CompanyAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ProtectCompanyAttachments extends Command
{
    protected $signature = 'company-access:protect-attachments';

    protected $description = 'Verify private attachment copies and remove public originals before enabling company access';

    public function handle(): int
    {
        foreach (Tenant::cursor() as $tenant) {
            $tenant->run(function () {
                foreach (RequisitionAttachment::cursor() as $attachment) {
                    $raw = $attachment->getRawOriginal('file_url');
                    $path = str_starts_with($raw, 'private:') ? substr($raw, 8) : null;
                    if (! $path) {
                        $url = parse_url($raw, PHP_URL_PATH);
                        $marker = strpos($url ?: '', '/storage/');
                        if ($marker === false) {
                            throw new \RuntimeException('Unrecognized attachment URL: '.$attachment->id);
                        }
                        $path = substr($url, $marker + 9);
                    }
                    if (str_contains($path, '..') || ! str_starts_with($path, 'requisitions/')) {
                        throw new \RuntimeException('Invalid attachment path');
                    }
                    $public = Storage::disk('public');
                    $private = Storage::disk('local');
                    if ($public->exists($path)) {
                        $content = $public->get($path);
                        if (! $private->put($path, $content) || hash('sha256', $private->get($path)) !== hash('sha256', $content)) {
                            throw new \RuntimeException('Private copy verification failed');
                        }
                        $attachment->withoutEvents(fn () => $attachment->update(['file_url' => 'private:'.$path]));
                        if (! $public->delete($path)) {
                            throw new \RuntimeException('Public copy removal failed');
                        }
                    } elseif (! $private->exists($path)) {
                        throw new \RuntimeException('Missing attachment: '.$attachment->id);
                    } else {
                        $attachment->withoutEvents(fn () => $attachment->update(['file_url' => 'private:'.$path]));
                    }
                }
            });
        }
        app(CompanyAccessService::class)->event('attachments_protected', null, 'platform');
        $this->info('All attachment copies verified and public originals removed.');

        return self::SUCCESS;
    }
}
